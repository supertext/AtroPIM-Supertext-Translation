<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Listeners;

use Atro\Core\EventManager\Event;
use Atro\Listeners\AbstractListener;
use SupertextTranslation\Translation\ConnectionStatus;
use SupertextTranslation\Translation\LanguageMapping;

/**
 * Labels the language mapping's combo boxes (one field per AtroCore language, added by
 * Listeners\Metadata) in every interface language: "Supertext language for German (de_DE)", the
 * tooltip and the option names, and the *Translation review* checkbox next to each. The texts are
 * the Connection.json "labels" of each language.
 */
class Language extends AbstractListener
{
    public function modify(Event $event): void
    {
        $data = $event->getArgument('data');

        if (!\is_array($data)) {
            return;
        }

        try {
            [$languages] = ConnectionStatus::languages($this->getContainer());
            $names       = $this->languageNames();
        } catch (\Throwable) {
            return;
        }

        foreach ($data as $locale => $rows) {
            $labels = LanguageMapping::labels(
                $languages,
                $names,
                self::text($data, (string) $locale, 'supertextLanguageField'),
                self::text($data, (string) $locale, 'supertextLanguageChoose'),
                self::text($data, (string) $locale, 'supertextLanguageFieldTooltip'),
                (string) $locale,
                self::text($data, (string) $locale, 'supertextReviewField'),
                self::text($data, (string) $locale, 'supertextReviewFieldTooltip'),
            );

            foreach (['fields', 'tooltips', 'options'] as $category) {
                $data[$locale]['Connection'][$category] = array_merge($data[$locale]['Connection'][$category] ?? [], $labels[$category]);
            }
        }

        $event->setArgument('data', $data);
    }

    /** @return array<string, string> AtroCore language code => its name (Administration → Languages) */
    private function languageNames(): array
    {
        $names = [];

        foreach ((array) ($this->getConfig()->get('referenceData')['Language'] ?? []) as $row) {
            if (\is_array($row) && isset($row['code'], $row['name']) && \is_string($row['name'])) {
                $names[(string) $row['code']] = $row['name'];
            }
        }

        return $names;
    }

    /** @param array<string, mixed> $data */
    private static function text(array $data, string $locale, string $key): string
    {
        $text = $data[$locale]['Connection']['labels'][$key] ?? $data['en_US']['Connection']['labels'][$key] ?? $key;

        return \is_string($text) ? $text : $key;
    }
}
