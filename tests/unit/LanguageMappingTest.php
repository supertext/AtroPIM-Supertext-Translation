<?php

namespace SupertextTranslation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SupertextTranslation\Translation\LanguageMapping;
use SupertextTranslation\Translation\Settings;

class LanguageMappingTest extends TestCase
{
    private const LANGUAGES = ['en_US', 'de_DE', 'es_ES', 'fr_BE', 'de_CH'];

    public function testFieldNamesFollowAtroCoresCamelCase(): void
    {
        self::assertSame('supertextCodeDeDe', LanguageMapping::field('de_DE'));
        self::assertSame('supertextCodeDe', LanguageMapping::field('de'));
        self::assertSame('supertextCodeZhHantTw', LanguageMapping::field('zh_Hant_TW'));
    }

    public function testOneComboBoxPerLanguagePreselectedWithTheSuggestion(): void
    {
        $defs = LanguageMapping::fieldDefs(self::LANGUAGES);

        self::assertSame(['supertextCodeEnUs', 'supertextCodeDeDe', 'supertextCodeEsEs', 'supertextCodeFrBe', 'supertextCodeDeCh'], array_values(array_filter(array_keys($defs), static fn (string $f): bool => str_starts_with($f, 'supertextCode'))));
        self::assertSame(['type' => 'bool', 'default' => false], array_intersect_key($defs['supertextReviewDeDe'], ['type' => 1, 'default' => 1]), 'Translation review: off by default');
        self::assertTrue($defs['supertextReviewDeDe']['dataField']);
        self::assertSame('enum', $defs['supertextCodeDeDe']['type']);
        self::assertTrue($defs['supertextCodeDeDe']['dataField']);
        self::assertTrue($defs['supertextCodeDeDe']['notStorable']);
        self::assertSame('de-DE', $defs['supertextCodeDeDe']['default'], 'the SDK default');
        self::assertSame('es', $defs['supertextCodeEsEs']['default']);
        self::assertSame('de-CH', $defs['supertextCodeDeCh']['default'], 'exact match');
        self::assertSame('', $defs['supertextCodeFrBe']['default'], 'no default: — choose —');

        $options = $defs['supertextCodeDeDe']['options'];
        self::assertSame('', $options[0]);
        self::assertContains('de-CH', $options);
        self::assertContains('gsw-u-sd-chzh', $options);
        self::assertNotContains('de', $options, 'targets only');
        self::assertCount(38, $options);
    }

    public function testChosenCodesWinOverTheOldTextAndDefaults(): void
    {
        $data = [
            'supertextLanguageCodes' => "de_DE = de-AT\nfr_BE = fr-FR",
            'supertextCodeDeDe'      => 'de-CH',
            'supertextCodeEsEs'      => '',
            'supertextCodeEnUs'      => 'xx-YY',
        ];

        self::assertSame(['de_DE' => 'de-CH'], LanguageMapping::fromData($data, self::LANGUAGES), 'empty and unknown values are left out');

        $settings = Settings::fromConnectionData($data, 'k', self::LANGUAGES);
        self::assertSame('de-CH', $settings->languageCode('de_DE'), 'the combo box wins');
        self::assertSame('fr-FR', $settings->languageCode('fr_BE'), 'an old line still counts');
        self::assertSame('es', $settings->languageCode('es_ES'), 'empty: the default');
        self::assertSame('en-US', $settings->languageCode('en_US'), 'an unknown value is ignored');
        self::assertSame('de', $settings->sourceCode('de_DE'));
    }

    public function testReviewLanguages(): void
    {
        $data = ['supertextReviewDeDe' => true, 'supertextReviewEsEs' => false, 'supertextReviewFrBe' => '1', 'supertextReviewXxYy' => true];

        self::assertSame('supertextReviewZhHantTw', LanguageMapping::reviewField('zh_Hant_TW'));
        self::assertSame(['de_DE', 'fr_BE'], LanguageMapping::reviewLanguages($data, self::LANGUAGES));
        self::assertNull(LanguageMapping::invalidValue($data), 'checkboxes are no combo boxes');
    }

    public function testOnlySupertextCodesCanBeSaved(): void
    {
        self::assertNull(LanguageMapping::invalidValue(['supertextCodeDeDe' => 'de-CH', 'supertextCodeFrBe' => '', 'supertextTimeout' => 99, 'supertextLanguageCodes' => 'x']));
        self::assertSame('xx-YY', LanguageMapping::invalidValue(['supertextCodeDeDe' => 'xx-YY']));
        self::assertSame('de', LanguageMapping::invalidValue(['supertextCodeDeDe' => 'de']), 'targets only');
        self::assertSame('de-ch', LanguageMapping::invalidValue(['supertextCodeDeDe' => 'de-ch']), 'Supertext\'s spelling');
        self::assertSame('array', LanguageMapping::invalidValue(['supertextCodeDeDe' => ['de-CH']]));
    }

    public function testMigrationMovesOldLinesIntoTheComboBoxes(): void
    {
        $data = [
            'supertextLanguageCodes' => "de_DE = de-ch\nfr_BE = fr-FR\nes_ES = xx-YY\nnl_BE = nl\nde_CH = de-AT",
            'supertextCodeDeCh'      => 'de-CH',
            'supertextTimeout'       => 300,
        ];

        $new = LanguageMapping::migrate($data, self::LANGUAGES);

        self::assertNotNull($new);
        self::assertSame('de-CH', $new['supertextCodeDeDe'], 'moved, with Supertext\'s spelling');
        self::assertSame('fr-FR', $new['supertextCodeFrBe']);
        self::assertSame('de-CH', $new['supertextCodeDeCh'], 'a saved combo box wins; its line is dropped');
        self::assertArrayNotHasKey('supertextCodeEsEs', $new, 'a code Supertext does not list stays in the text');
        self::assertSame("es_ES = xx-YY\nnl_BE = nl", $new['supertextLanguageCodes'], 'and so do languages AtroPIM does not have');
        self::assertSame(300, $new['supertextTimeout']);

        // The same mapping before and after (apart from case: an old "de-ch" was sent as entered).
        $before = Settings::fromConnectionData($data, 'k', self::LANGUAGES);
        $after  = Settings::fromConnectionData($new, 'k', self::LANGUAGES);
        foreach (self::LANGUAGES as $language) {
            self::assertSame(strtolower((string) $before->languageCodeOrNull($language)), strtolower((string) $after->languageCodeOrNull($language)), $language);
        }

        self::assertNull(LanguageMapping::migrate($new, self::LANGUAGES), 'nothing left to move');
        self::assertNull(LanguageMapping::migrate(['supertextTimeout' => 300], self::LANGUAGES));
    }

    public function testLabels(): void
    {
        $labels = LanguageMapping::labels(['de_DE', 'fr_BE'], ['de_DE' => 'Deutsch'], 'Supertext language for {language}', '— choose —', 'Tip', 'en_US');

        self::assertSame('Supertext language for Deutsch (de_DE)', $labels['fields']['supertextCodeDeDe']);
        self::assertSame('Supertext language for fr_BE', $labels['fields']['supertextCodeFrBe']);
        self::assertSame('Tip', $labels['tooltips']['supertextCodeFrBe']);
        self::assertSame('Translation review', $labels['fields']['supertextReviewFrBe']);
        self::assertSame('— choose —', $labels['options']['supertextCodeDeDe']['']);
        self::assertSame('German (Switzerland) (de-CH)', $labels['options']['supertextCodeDeDe']['de-CH']);
        self::assertSame('Swiss German (Zurich) (gsw-u-sd-chzh)', $labels['options']['supertextCodeDeDe']['gsw-u-sd-chzh']);

        $german = LanguageMapping::labels(['de_DE'], [], '{language}', '—', 'Tip', 'de_DE');
        self::assertStringEndsWith('(de-CH)', $german['options']['supertextCodeDeDe']['de-CH']);
        self::assertSame('Swiss German (Zurich) (gsw-u-sd-chzh)', $german['options']['supertextCodeDeDe']['gsw-u-sd-chzh'], 'dialect names stay as in the SDK');
    }

    public function testLayoutRowPerLanguage(): void
    {
        $rows  = LanguageMapping::rows(['en_US', 'de_CH']);
        $names = [];

        foreach ($rows as $row) {
            foreach ($row as $cell) {
                if (\is_array($cell)) {
                    $names[] = $cell['name'];
                }
            }
        }

        self::assertSame(['supertextCodeEnUs', 'supertextReviewEnUs', 'supertextCodeDeCh', 'supertextReviewDeCh'], $names, 'the review checkbox next to the combo box');
    }
}
