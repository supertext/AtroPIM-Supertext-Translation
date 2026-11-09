<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Translation;

use Atro\Core\Container;
use Atro\Core\Exceptions\NotModified;
use Espo\ORM\Entity;
use SupertextTranslation\Api\SupertextClient;
use SupertextTranslation\Api\SupertextException;
use SupertextTranslation\Sdk\V1\Languages;

/**
 * Translates the multilingual text fields of one record (a product, a category, …) from a
 * source language into target languages: one Supertext document per target language, saved
 * through the entity's record service, so AtroCore's permissions, validation and change
 * history apply exactly as when an editor types the text.
 *
 * Field rules (keep docs/DEVELOPER.md → Field rules and docs/USER_GUIDE.md in sync): see
 * Planner::units() — multilingual varchar, text, markdown and wysiwyg fields, native ones and
 * attribute values alike; read-only fields are skipped.
 */
final class EntityTranslator
{
    private readonly Messages $messages;

    /** @param Messages|null $messages null: in the current user's language */
    public function __construct(private readonly Container $container, ?Messages $messages = null)
    {
        $this->messages = $messages ?? Messages::forUser($container);
    }

    public function mainLanguage(): string
    {
        return (string) $this->container->get('config')->get('mainLanguage', 'en_US');
    }

    /** @return list<string> the main language and the additional input languages */
    public function languages(): array
    {
        $config = $this->container->get('config');

        return array_values(array_unique(array_merge([$this->mainLanguage()], (array) $config->get('inputLanguageList', []))));
    }

    /**
     * @param list<string> $targets empty: every language except the source
     *
     * @return array<string, array{status: string, translated: int, existing: int, message: string}>
     *         status: "translated", "nothing" (no text, or all kept) or "error"
     */
    public function translate(
        string $entityType,
        string $id,
        string $source,
        array $targets,
        bool $overwrite,
        string $politeness = '',
        ?Settings $settings = null,
    ): array {
        $service = $this->container->get('serviceFactory')->create($entityType);
        $entity  = $service->getEntity($id);

        if (!$entity instanceof Entity) {
            throw new SupertextException(sprintf('%s %s was not found.', $entityType, $id), key: 'record_not_found', params: ['entity' => $entityType, 'id' => $id]);
        }

        $units  = Planner::units($this->fieldDefs($entity), $this->mainLanguage());
        $source = $source !== '' ? $source : $this->mainLanguage();

        if ($units === []) {
            throw new SupertextException(sprintf('%s has no multilingual text fields. Make the fields multilingual and add languages under Administration → Languages.', $entityType), key: 'no_text_fields', params: ['entity' => $entityType]);
        }

        $available = Planner::languages($units);

        if (!\in_array($source, $available, true)) {
            throw new SupertextException(sprintf('"%s" is not one of the languages of this record (%s).', $source, implode(', ', $available)), key: 'not_a_language', params: ['language' => $source, 'languages' => implode(', ', $available)]);
        }

        $targets = $targets === [] ? array_values(array_diff($available, [$source])) : array_values(array_intersect(array_unique($targets), $available));
        $targets = array_values(array_diff($targets, [$source]));

        $settings ??= (new ConnectionResolver($this->container))->settings(null);
        $client   = $settings->client(ReportingFactory::moduleVersion($this->container));

        // Version report / key check of the Commando Center, at the end of the process (never fails this).
        ReportingFactory::afterTranslation($this->container, $settings);

        if (!$client->hasApiKey()) {
            throw new SupertextException('No Supertext API key is configured. Create a connection of type "Supertext" under Administration → Connections. ' . Settings::KEY_HELP, key: 'no_api_key_connection');
        }

        // No guessing: a language without a Supertext code fails with unsupported_language, nothing is sent.
        $sourceCode = $settings->sourceCode($source);
        $politeness = \in_array($politeness, ['more', 'less'], true) ? $politeness : 'default';
        $results    = [];

        foreach ($targets as $target) {
            $values = $this->values($entity, $units);
            $plan   = Planner::plan($units, $values, $source, $target, $overwrite);

            if ($plan['translate'] === []) {
                $results[$target] = ['status' => 'nothing', 'translated' => 0, 'existing' => $plan['existing'], 'message' => ''];

                continue;
            }

            $notes = [];

            try {
                $targetCode = $settings->languageCode($target);
                $tone       = $politeness;

                // Formal/informal only where Supertext offers it (not e.g. en-US); say so instead of failing.
                if ($tone !== 'default' && Languages::supportsPoliteness($targetCode) === false) {
                    $tone    = 'default';
                    $notes[] = $this->messages->text('politeness_skipped', ['language' => $target]);
                }

                // Languages with *Translation review* on go through Supertext's review service.
                $translations = $this->translateUnits($client->forReview($settings->review($target)), $plan['translate'], $values, $source, $sourceCode, $targetCode, $tone);
            } catch (SupertextException $e) {
                if (\in_array($e->getCode(), [401, 403], true)) {
                    ReportingFactory::markAuthenticationFailed($e->getCode());
                }

                $results[$target] = ['status' => 'error', 'translated' => 0, 'existing' => $plan['existing'], 'message' => $this->messages->exception($e)];

                continue;
            }

            $data    = new \stdClass();
            $tooLong = [];

            foreach ($plan['translate'] as $index => $unit) {
                $text = $translations[$index] ?? '';

                if (!Planner::hasText($text)) {
                    continue;
                }

                if ($unit->maxLength !== null && !$unit->html && mb_strlen($text) > $unit->maxLength) {
                    $tooLong[] = $unit->label !== '' ? $unit->label : $unit->field;

                    continue;
                }

                $data->{$unit->fieldFor($target)} = $text;
            }

            if ((array) $data === []) {
                $results[$target] = [
                    'status'     => 'error',
                    'translated' => 0,
                    'existing'   => $plan['existing'],
                    'message'    => $tooLong !== [] ? $this->messages->text('too_long', ['fields' => implode(', ', $tooLong)]) : $this->messages->text('no_text'),
                ];

                continue;
            }

            try {
                $service->updateEntity($id, $data);
            } catch (NotModified) {
                // The record already holds exactly this translation.
            } catch (\Throwable $e) {
                $reason = trim($e->getMessage()) !== '' ? $e->getMessage() : (new \ReflectionClass($e))->getShortName();
                $results[$target] = ['status' => 'error', 'translated' => 0, 'existing' => $plan['existing'], 'message' => $this->messages->text('rejected', ['reason' => $reason])];

                continue;
            }

            if ($tooLong !== []) {
                array_unshift($notes, $this->messages->text('partly_too_long', ['fields' => implode(', ', $tooLong)]));
            }

            $entity = $service->getEntity($id) ?? $entity;
            $results[$target] = [
                'status'     => 'translated',
                'translated' => \count((array) $data),
                'existing'   => $plan['existing'],
                'message'    => implode(' ', $notes),
            ];
        }

        return $results;
    }

    /**
     * One line per outcome, e.g. "de_CH, fr_CH: translated (3 fields)".
     *
     * @param array<string, array{status: string, translated: int, existing: int, message: string}> $results
     * @param Messages|null $messages null: English
     */
    public static function summary(array $results, ?Messages $messages = null): string
    {
        $messages ??= new Messages();
        // Languages with the same outcome share a line: "de_CH, fr_CH: translated (3 fields)".
        $groups = [];

        foreach ($results as $language => $result) {
            $outcome = match ($result['status']) {
                'translated' => $messages->text($result['translated'] === 1 ? 'translated_one' : 'translated_many', ['count' => $result['translated']])
                    . ($result['message'] !== '' ? '. ' . $result['message'] : ''),
                'nothing'    => $messages->text($result['existing'] > 0 ? 'kept' : 'nothing'),
                default      => $messages->text('error', ['message' => $result['message']]),
            };
            $groups[$outcome][] = $language;
        }

        $lines = [];

        foreach ($groups as $outcome => $languages) {
            $lines[] = implode(', ', $languages) . ': ' . $outcome;
        }

        return $lines === [] ? $messages->text('no_targets') : implode("\n", $lines);
    }

    /**
     * The message shown after the action ran: the summary for one record, counts for several.
     *
     * @param list<array{ok: bool, summary: string}> $runs
     * @param Messages|null $messages null: English
     */
    public static function runsMessage(array $runs, ?Messages $messages = null): string
    {
        $messages ??= new Messages();

        if (\count($runs) === 1) {
            return str_replace("\n", '; ', $runs[0]['summary']);
        }

        $failed = \count(array_filter($runs, static fn (array $run): bool => !$run['ok']));

        return $messages->text('records_translated', ['done' => \count($runs) - $failed, 'total' => \count($runs)])
            . ($failed > 0 ? ' ' . $messages->text('records_failed') : '');
    }

    /** @return array<string, array<string, mixed>> */
    private function fieldDefs(Entity $entity): array
    {
        $defs = $this->container->get('metadata')->get(['entityDefs', $entity->getEntityType(), 'fields'], []);

        // Attribute values are only in the loaded entity's own definitions.
        if (\is_array($entity->entityDefs['fields'] ?? null)) {
            $defs = array_merge($defs, $entity->entityDefs['fields']);
        }

        return $defs;
    }

    /**
     * @param list<TextUnit> $units
     *
     * @return array<string, mixed>
     */
    private function values(Entity $entity, array $units): array
    {
        $values = [];

        foreach ($units as $unit) {
            foreach ($unit->fieldsByLanguage as $field) {
                $values[$field] = $entity->get($field);
            }
        }

        return $values;
    }

    /**
     * @param list<TextUnit>       $units
     * @param array<string, mixed> $values
     *
     * @return array<int, string> unit index => translated text
     */
    private function translateUnits(SupertextClient $client, array $units, array $values, string $source, string $sourceCode, string $targetCode, string $politeness): array
    {
        $segments = [];

        foreach ($units as $index => $unit) {
            $segments[$index] = ['text' => (string) $values[$unit->fieldFor($source)], 'html' => $unit->html];
        }

        return $client->translateSegments($segments, $targetCode, $sourceCode, $politeness);
    }
}
