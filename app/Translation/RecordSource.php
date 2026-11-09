<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Translation;

/**
 * What translation files need from AtroCore: the languages, a record's multilingual text fields
 * and their values, and saving translations. AtroRecordSource is the AtroCore implementation; the
 * unit tests use a fake. No AtroCore classes here.
 */
interface RecordSource
{
    public function mainLanguage(): string;

    /** @return list<string> the main language and the additional input languages */
    public function languages(): array;

    /**
     * The record's text units (Planner::units()) and the values of their fields, or null when the
     * record doesn't exist or the user may not read it.
     *
     * @return array{units: list<TextUnit>, values: array<string, mixed>, editable: bool}|null
     */
    public function record(string $entityType, string $id): ?array;

    /**
     * Saves translated field values (field name => text) through the record service, with
     * AtroCore's permissions, validation and history.
     *
     * @param array<string, string> $data
     *
     * @throws \Throwable when AtroCore rejects the values
     */
    public function save(string $entityType, string $id, array $data): void;
}
