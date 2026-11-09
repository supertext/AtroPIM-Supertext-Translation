<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Translation;

use SupertextTranslation\Api\SupertextException;
use SupertextTranslation\Sdk\Files\FileJob;
use SupertextTranslation\Sdk\Files\FileSegment;
use SupertextTranslation\Sdk\Files\ImportedSegment;
use SupertextTranslation\Sdk\Files\TranslationFile;
use SupertextTranslation\Sdk\Files\TranslationFileError;
use SupertextTranslation\Sdk\Files\TranslationFiles;

/**
 * "Export for translation" and "Import translation": one record's multilingual text fields as an
 * XLIFF 1.2 or HTML file (the SDK's TranslationFiles), translated outside the API, and the
 * translated file written back with the same rules as an API translation (Planner: the same
 * fields, existing translations kept unless overwrite, plain text within maxLength).
 *
 * Reference of a job: "<Entity>:<id>:<source language>:<target language>", e.g.
 * "Product:6650a1b2c3d4e5f60:en_US:de_CH" (AtroCore names and ids, never titles). Segment keys are
 * the base field names (Planner units, e.g. "name", "longDescription", attribute value fields).
 *
 * No AtroCore classes: the record access is a RecordSource.
 */
final class TranslationFileExchange
{
    /** The `plugin` of every job: the GitHub repository name. */
    public const PLUGIN = 'AtroPIM-Supertext-Translation';

    /** First part of the file names. */
    public const SHORT_NAME = 'atropim';

    public const XLIFF = 'xliff';
    public const HTML = 'html';

    /** Uploads larger than this are refused before they are read (10 MB, the SDK's limit). */
    public const MAX_UPLOAD_BYTES = TranslationFiles::MAX_FILE_CHARACTERS;

    /** Counters of an imported job, in the order of the summary. */
    public const COUNTS = ['imported', 'source_changed', 'untranslated', 'invalid_markup', 'missing', 'kept'];

    public function __construct(private readonly RecordSource $records, private readonly Settings $settings)
    {
    }

    public static function reference(string $entityType, string $id, string $source, string $target): string
    {
        return $entityType . ':' . $id . ':' . $source . ':' . $target;
    }

    /** @return array{entity: string, id: string, source: string, target: string}|null */
    public static function parseReference(string $reference): ?array
    {
        if (preg_match('/^([A-Za-z][A-Za-z0-9_]*):([A-Za-z0-9_-]+):([A-Za-z]{2,3}(?:_[A-Za-z0-9]+)*):([A-Za-z]{2,3}(?:_[A-Za-z0-9]+)*)$/', $reference, $m) !== 1) {
            return null;
        }

        return ['entity' => $m[1], 'id' => $m[2], 'source' => $m[3], 'target' => $m[4]];
    }

    /**
     * "atropim-product-<id>-de_CH.xlf", or "atropim-product-<id>.xlf" for several target languages.
     *
     * @param list<string> $targets
     */
    public static function fileName(string $entityType, string $id, array $targets, string $format): string
    {
        $name = self::SHORT_NAME . '-' . strtolower($entityType) . '-' . $id . (\count($targets) === 1 ? '-' . $targets[0] : '');

        return (string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) . ($format === self::HTML ? '.html' : '.xlf');
    }

    public static function contentType(string $format): string
    {
        return $format === self::HTML ? 'text/html; charset=utf-8' : 'application/x-xliff+xml';
    }

    /**
     * The Supertext code of an AtroCore language through the connection's mapping; an unmapped
     * language becomes its AtroCore code with "-" (a file may go to any translator, so it never
     * blocks the export).
     */
    public function supertextCode(string $language, bool $source): string
    {
        try {
            return $source ? $this->settings->sourceCode($language) : $this->settings->languageCode($language);
        } catch (SupertextException) {
            return str_replace('_', '-', $language);
        }
    }

    /**
     * @param list<string> $targets empty: every language of the record except the source
     *
     * @return array{content: string, fileName: string, contentType: string, targets: list<string>}
     *
     * @throws SupertextException record_not_found, no_text_fields, not_a_language, no_targets, html_one_language, nothing_to_translate
     */
    public function export(string $entityType, string $id, string $source, array $targets, string $format, bool $overwrite): array
    {
        $format = $format === self::HTML ? self::HTML : self::XLIFF;
        $record = $this->records->record($entityType, $id);

        if ($record === null) {
            throw new SupertextException(sprintf('%s %s was not found.', $entityType, $id), key: 'record_not_found', params: ['entity' => $entityType, 'id' => $id]);
        }

        if ($record['units'] === []) {
            throw new SupertextException(sprintf('%s has no multilingual text fields.', $entityType), key: 'no_text_fields', params: ['entity' => $entityType]);
        }

        $source    = $source !== '' ? $source : $this->records->mainLanguage();
        $available = Planner::languages($record['units']);

        if (!\in_array($source, $available, true)) {
            throw new SupertextException(sprintf('"%s" is not one of the languages of this record (%s).', $source, implode(', ', $available)), key: 'not_a_language', params: ['language' => $source, 'languages' => implode(', ', $available)]);
        }

        $targets = $targets === [] ? $available : array_values(array_intersect(array_unique($targets), $available));
        $targets = array_values(array_diff($targets, [$source]));

        if ($targets === []) {
            throw new SupertextException('No other languages to translate into.', key: 'no_targets');
        }

        if ($format === self::HTML && \count($targets) > 1) {
            throw new SupertextException('An HTML file holds one target language: choose exactly one, or use XLIFF.', key: 'html_one_language');
        }

        $jobs     = [];
        $exported = [];

        foreach ($targets as $target) {
            $segments = $this->segments($record, $source, $target, $overwrite)['segments'];

            if ($segments === []) {
                continue;
            }

            $jobs[]     = new FileJob(self::PLUGIN, self::reference($entityType, $id, $source, $target), $this->supertextCode($source, true), $this->supertextCode($target, false), $segments);
            $exported[] = $target;
        }

        if ($jobs === []) {
            throw new SupertextException('Nothing to translate.', key: 'nothing_to_translate');
        }

        try {
            $content = $format === self::HTML ? TranslationFiles::exportHtml($jobs[0]) : TranslationFiles::exportXliff($jobs);
        } catch (TranslationFileError $e) {
            throw new SupertextException($e->getMessage(), key: sprintf('file_%s', $e->errorCode), previous: $e);
        }

        return ['content' => $content, 'fileName' => self::fileName($entityType, $id, $exported, $format), 'contentType' => self::contentType($format), 'targets' => $exported];
    }

    /**
     * Imports a translated file. File-level problems throw; problems of one job (another system,
     * an unknown record or language) are that job's `error`.
     *
     * @param bool $includeChanged also write texts whose source changed since the export
     * @param bool $overwrite      also replace fields that already have text in the target language
     *
     * @return list<array{reference: string, entity: string, id: string, language: string, error: string|null, errorParams: array<string, string>, counts: array<string, int>, tooLong: list<string>}>
     *
     * @throws SupertextException file_too_large, file_invalid_file, file_missing_metadata
     */
    public function import(string $content, bool $includeChanged, bool $overwrite): array
    {
        if (\strlen($content) > self::MAX_UPLOAD_BYTES) {
            throw new SupertextException('The file is too large (at most 10 MB).', key: 'file_too_large');
        }

        try {
            $files = TranslationFiles::read($content);
        } catch (TranslationFileError $e) {
            throw new SupertextException($e->getMessage(), key: sprintf('file_%s', $e->errorCode), previous: $e);
        }

        $results = [];

        foreach ($files as $file) {
            $results[] = $this->importJob($file, $includeChanged, $overwrite);
        }

        return $results;
    }

    /**
     * @return array{reference: string, entity: string, id: string, language: string, error: string|null, errorParams: array<string, string>, counts: array<string, int>, tooLong: list<string>}
     */
    private function importJob(TranslationFile $file, bool $includeChanged, bool $overwrite): array
    {
        $result = [
            'reference'   => $file->reference,
            'entity'      => '',
            'id'          => '',
            'language'    => $file->targetLanguage,
            'error'       => null,
            'errorParams' => [],
            'counts'      => array_fill_keys(self::COUNTS, 0),
            'tooLong'     => [],
        ];

        if ($file->plugin !== self::PLUGIN) {
            return ['error' => 'file_other_system', 'errorParams' => ['plugin' => $file->plugin]] + $result;
        }

        $reference = self::parseReference($file->reference);

        if ($reference === null) {
            return ['error' => 'file_not_found'] + $result;
        }

        $result = ['entity' => $reference['entity'], 'id' => $reference['id'], 'language' => $reference['target']] + $result;
        $languages = $this->records->languages();

        foreach ([$reference['target'], $reference['source']] as $language) {
            if (!\in_array($language, $languages, true)) {
                return ['error' => 'file_no_language', 'errorParams' => ['language' => $language]] + $result;
            }
        }

        $record = $this->records->record($reference['entity'], $reference['id']);

        if ($record === null) {
            return ['error' => 'file_not_found'] + $result;
        }

        if (!$record['editable']) {
            return ['error' => 'file_not_allowed'] + $result;
        }

        $collected = $this->segments($record, $reference['source'], $reference['target'], $overwrite);
        $fileKeys  = array_map(static fn ($unit): string => $unit->key, $file->units);
        $counts    = $result['counts'];

        // Fields that got a translation since the export are kept, as in an API translation without overwrite.
        $counts['kept'] = \count(array_intersect($fileKeys, $collected['kept']));

        try {
            $imported = TranslationFiles::importTranslations($file, $collected['segments']);
        } catch (TranslationFileError $e) {
            return ['error' => sprintf('file_%s', $e->errorCode)] + $result;
        }

        $data    = [];
        $tooLong = [];

        foreach ($imported as $segment) {
            $write = $segment->status === ImportedSegment::OK || ($segment->status === ImportedSegment::SOURCE_CHANGED && $includeChanged);

            if (!$write || $segment->text === null) {
                $counts[$segment->status === ImportedSegment::SOURCE_CHANGED ? 'source_changed' : $segment->status]++;

                continue;
            }

            $unit = $collected['units'][$segment->key] ?? null;
            $field = $unit?->fieldFor($reference['target']);

            if ($unit === null || $field === null || !Planner::hasText($segment->text)) {
                $counts['untranslated']++;

                continue;
            }

            if ($unit->maxLength !== null && !$unit->html && mb_strlen($segment->text) > $unit->maxLength) {
                $tooLong[] = $unit->label !== '' ? $unit->label : $unit->field;

                continue;
            }

            $data[$field] = $segment->text;
        }

        $result['tooLong'] = $tooLong;

        if ($data !== []) {
            try {
                $this->records->save($reference['entity'], $reference['id'], $data);
            } catch (\Throwable $e) {
                $reason = trim($e->getMessage()) !== '' ? $e->getMessage() : (new \ReflectionClass($e))->getShortName();

                return ['counts' => $counts, 'error' => 'rejected', 'errorParams' => ['reason' => $reason]] + $result;
            }
        }

        $counts['imported'] = \count($data);
        $result['counts']   = $counts;

        return $result;
    }

    /**
     * The segments an API translation of this record would send (Planner::plan), keyed by base field.
     *
     * @param array{units: list<TextUnit>, values: array<string, mixed>, editable: bool} $record
     *
     * @return array{segments: list<FileSegment>, units: array<string, TextUnit>, kept: list<string>}
     */
    private function segments(array $record, string $source, string $target, bool $overwrite): array
    {
        $plan     = Planner::plan($record['units'], $record['values'], $source, $target, $overwrite);
        $segments = [];
        $units    = [];

        foreach ($plan['translate'] as $unit) {
            $segments[]          = new FileSegment($unit->field, (string) $record['values'][(string) $unit->fieldFor($source)], $unit->html);
            $units[$unit->field] = $unit;
        }

        $kept = [];

        if (!$overwrite) {
            foreach ($record['units'] as $unit) {
                $targetField = $unit->fieldFor($target);

                if (!isset($units[$unit->field]) && $targetField !== null && Planner::hasText($record['values'][$targetField] ?? null)) {
                    $kept[] = $unit->field;
                }
            }
        }

        return ['segments' => $segments, 'units' => $units, 'kept' => $kept];
    }

    /**
     * The summary of an import, one block per job: "Product 123, de_CH" and the counts (zero
     * counts left out, except "Imported"), or the job's error.
     *
     * @param list<array{reference: string, entity: string, id: string, language: string, error: string|null, errorParams: array<string, string>, counts: array<string, int>, tooLong: list<string>}> $jobs
     *
     * @return list<array{title: string, lines: list<string>, error: string|null}>
     */
    public static function summary(array $jobs, Messages $messages): array
    {
        $blocks = [];

        foreach ($jobs as $job) {
            $title = $job['entity'] !== ''
                ? $messages->text('file_job', ['entity' => $job['entity'], 'id' => $job['id'], 'language' => $job['language']])
                : $job['reference'];
            $lines = [];

            foreach (self::COUNTS as $count) {
                if ($count === 'imported' || $job['counts'][$count] > 0) {
                    $lines[] = $messages->text(sprintf('file_%s', $count), ['count' => $job['counts'][$count]]);
                }
            }

            if ($job['tooLong'] !== []) {
                $lines[] = $messages->text('partly_too_long', ['fields' => implode(', ', $job['tooLong'])]);
            }

            $blocks[] = [
                'title' => $title,
                'lines' => $job['error'] === null || $job['error'] === 'rejected' ? $lines : [],
                'error' => $job['error'] !== null ? $messages->text($job['error'], $job['errorParams']) : null,
            ];
        }

        return $blocks;
    }

    /**
     * The summary as plain text (console, JSON answers).
     *
     * @param list<array{title: string, lines: list<string>, error: string|null}> $blocks
     */
    public static function summaryText(array $blocks): string
    {
        $out = [];

        foreach ($blocks as $block) {
            $out[] = $block['title'] . ($block['error'] !== null ? ': ' . $block['error'] : '');

            foreach ($block['lines'] as $line) {
                $out[] = '  ' . $line;
            }
        }

        return implode("\n", $out);
    }
}
