<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Translation;

use SupertextTranslation\Sdk\V1\Languages;

/**
 * The connection's language mapping: one combo box (an enum field) per AtroCore language with
 * Supertext's target codes, e.g. `supertextCodeDeDe` for de_DE, stored in the connection's `data`
 * JSON like the other Supertext fields. The fields are added to the Connection entity by
 * Listeners\Metadata (definitions: fieldDefs()), labelled by Listeners\Language (labels()) and
 * placed on the form by Listeners\ConnectionLayout.
 *
 * Earlier versions kept the mapping in a text field (`supertextLanguageCodes`, "de_DE = de-CH" per line).
 * Its lines still count (the combo box wins) and migrate() moves them into the combo boxes.
 *
 * No AtroCore dependencies: the languages and texts are passed in.
 */
final class LanguageMapping
{
    public const FIELD_PREFIX = 'supertextCode';
    public const REVIEW_PREFIX = 'supertextReview';
    public const REVIEW_URL_FIELD = 'supertextReviewUrl';
    public const LEGACY_FIELD = 'supertextLanguageCodes';

    /** "de_DE" → "supertextCodeDeDe" (AtroCore's camel case, as for multilingual fields: nameDeDe). */
    public static function field(string $language): string
    {
        $parts = array_filter(explode('_', strtolower($language)), static fn (string $part): bool => $part !== '');

        return self::FIELD_PREFIX . implode('', array_map('ucfirst', $parts));
    }

    /** "de_DE" → "supertextReviewDeDe": the language's *Translation review* checkbox. */
    public static function reviewField(string $language): string
    {
        return self::REVIEW_PREFIX . substr(self::field($language), \strlen(self::FIELD_PREFIX));
    }

    /** The preselection of a language's combo box: Supertext's code for it (exact match or SDK default), else "". */
    public static function suggestion(string $language): string
    {
        return Languages::suggestTarget($language) ?? '';
    }

    /** @return list<string> "" ("— choose —") and Supertext's target codes, sorted by name */
    public static function options(): array
    {
        return array_merge([''], array_map(static fn (array $option): string => $option['code'], Languages::targetOptions()));
    }

    /**
     * The Connection field definitions of the combo boxes, one per language.
     *
     * @param list<string> $languages
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fieldDefs(array $languages): array
    {
        $defs = [];

        foreach ($languages as $language) {
            $defs[self::field($language)] = [
                'type'                  => 'enum',
                'notStorable'           => true,
                'dataField'             => true,
                'options'               => self::options(),
                'default'               => self::suggestion($language),
                'prohibitedEmptyValue'  => false,
                'tooltip'               => true,
                'conditionalProperties' => self::visibleForSupertext(),
            ];
            $defs[self::reviewField($language)] = [
                'type'                  => 'bool',
                'notStorable'           => true,
                'dataField'             => true,
                'default'               => false,
                'tooltip'               => true,
                'conditionalProperties' => self::visibleForSupertext(),
            ];
        }

        return $defs;
    }

    /** @return array<string, mixed> */
    private static function visibleForSupertext(): array
    {
        return ['visible' => ['conditionGroup' => [['type' => 'equals', 'attribute' => 'type', 'value' => 'supertext']]]];
    }

    /**
     * The form rows of the mapping: one per language, its combo box and its *Translation review*.
     *
     * @param list<string> $languages
     *
     * @return list<list<array{name: string}>>
     */
    public static function rows(array $languages): array
    {
        return array_map(static fn (string $language): array => [['name' => self::field($language)], ['name' => self::reviewField($language)]], $languages);
    }

    /**
     * The first submitted combo box value that isn't one of Supertext's codes (server-side check of a
     * save), or null.
     *
     * @param array<mixed> $input the submitted fields (a cast stdClass: keys may be numeric)
     */
    public static function invalidValue(array $input): ?string
    {
        $options = self::options();

        foreach ($input as $field => $value) {
            if (\is_string($field) && str_starts_with($field, self::FIELD_PREFIX) && $value !== null && !\in_array($value, $options, true)) {
                return \is_scalar($value) ? (string) $value : \gettype($value);
            }
        }

        return null;
    }

    /**
     * The codes chosen in the combo boxes (empty ones and values Supertext doesn't offer are left out).
     *
     * @param array<string, mixed> $data the connection's data fields
     * @param list<string>         $languages
     *
     * @return array<string, string> AtroCore language => Supertext code
     */
    public static function fromData(array $data, array $languages): array
    {
        $mapping = [];

        foreach ($languages as $language) {
            $code = $data[self::field($language)] ?? null;

            if (\is_string($code) && $code !== '' && Languages::isSupportedTarget($code)) {
                $mapping[$language] = $code;
            }
        }

        return $mapping;
    }

    /**
     * The languages whose *Translation review* is ticked.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $languages
     *
     * @return list<string>
     */
    public static function reviewLanguages(array $data, array $languages): array
    {
        return array_values(array_filter($languages, static fn (string $language): bool => \in_array($data[self::reviewField($language)] ?? false, [true, 1, '1', 'true'], true)));
    }

    /**
     * Moves the lines of the old text field into the combo boxes: a line for one of the languages
     * whose combo box was never saved becomes its value (if Supertext offers the code; other codes
     * stay in the text and are sent as entered); a line for a language whose combo box was saved is
     * dropped (the combo box wins). Lines for other languages stay.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $languages
     *
     * @return array<string, mixed>|null the new data, or null when nothing changed
     */
    public static function migrate(array $data, array $languages): ?array
    {
        $text = (string) ($data[self::LEGACY_FIELD] ?? '');

        if (trim($text) === '') {
            return null;
        }

        $lines   = Settings::parseLanguageCodes($text);
        $kept    = [];
        $changed = false;

        foreach ($lines as $language => $code) {
            $field = self::field($language);

            if (!\in_array($language, $languages, true)) {
                $kept[$language] = $code;
            } elseif (\array_key_exists($field, $data) && $data[$field] !== null) {
                $changed = true;
            } elseif (Languages::isSupportedTarget($code)) {
                $data[$field] = Languages::matchTarget($code) ?? $code;
                $changed      = true;
            } else {
                $kept[$language] = $code;
            }
        }

        if (!$changed) {
            return null;
        }

        $data[self::LEGACY_FIELD] = implode("\n", array_map(static fn (string $language, string $code): string => "$language = $code", array_keys($kept), $kept));

        return $data;
    }

    /**
     * The labels of the combo boxes for one interface language: the field label (the AtroCore
     * language's name and code), the tooltip and the option names ("German (Switzerland) (de-CH)").
     *
     * @param list<string>          $languages
     * @param array<string, string> $languageNames AtroCore language => its name (optional)
     * @param string                $fieldText     the label template with {language}
     * @param string                $choose        the label of the empty option
     * @param string                $tooltip       the tooltip of every combo box
     * @param string                $locale        the interface language (en_US, de_DE, …) for the option names
     * @param string                $reviewText    the label of the *Translation review* checkboxes
     * @param string                $reviewTooltip their tooltip
     *
     * @return array{fields: array<string, string>, tooltips: array<string, string>, options: array<string, array<string, string>>}
     */
    public static function labels(array $languages, array $languageNames, string $fieldText, string $choose, string $tooltip, string $locale, string $reviewText = 'Translation review', string $reviewTooltip = ''): array
    {
        $optionNames = ['' => $choose];

        foreach (Languages::targetOptions() as $option) {
            $optionNames[$option['code']] = self::targetName($option['code'], $option['name'], $locale) . ' (' . $option['code'] . ')';
        }

        $result = ['fields' => [], 'tooltips' => [], 'options' => []];

        foreach ($languages as $language) {
            $field = self::field($language);
            $name  = trim($languageNames[$language] ?? '');

            $result['fields'][$field]   = strtr($fieldText, ['{language}' => $name !== '' && $name !== $language ? "$name ($language)" : $language]);
            $result['tooltips'][$field] = $tooltip;
            $result['options'][$field]  = $optionNames;

            $result['fields'][self::reviewField($language)]   = $reviewText;
            $result['tooltips'][self::reviewField($language)] = $reviewTooltip;
        }

        return $result;
    }

    /** A Supertext language's name in the interface language (PHP's intl where available), else the SDK's English name. */
    private static function targetName(string $code, string $english, string $locale): string
    {
        if (str_starts_with(strtolower($locale), 'en') || str_contains($code, '-u-') || !class_exists(\Locale::class)) {
            return $english;
        }

        $name = \Locale::getDisplayName($code, $locale);

        return \is_string($name) && strcasecmp($name, $code) !== 0 ? $name : $english;
    }
}
