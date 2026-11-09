<?php

declare(strict_types=1);

namespace SupertextTranslation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SupertextTranslation\Api\SupertextException;
use SupertextTranslation\Sdk\Files\FileJob;
use SupertextTranslation\Sdk\Files\FileSegment;
use SupertextTranslation\Sdk\Files\TranslationFiles;
use SupertextTranslation\Translation\ImportPage;
use SupertextTranslation\Translation\Messages;
use SupertextTranslation\Translation\Planner;
use SupertextTranslation\Translation\RecordSource;
use SupertextTranslation\Translation\Settings;
use SupertextTranslation\Translation\TranslationFileExchange;

/** Export for translation / Import translation (XLIFF and HTML files) against a fake AtroCore. */
final class TranslationFileExchangeTest extends TestCase
{
    private const DEFS = [
        'name'                => ['type' => 'varchar', 'isMultilang' => true, 'maxLength' => 40],
        'nameDeCh'            => ['type' => 'varchar', 'multilangField' => 'name', 'multilangLocale' => 'de_CH'],
        'nameXxYy'            => ['type' => 'varchar', 'multilangField' => 'name', 'multilangLocale' => 'xx_YY'],
        'longDescription'     => ['type' => 'wysiwyg', 'isMultilang' => true],
        'longDescriptionDeCh' => ['type' => 'wysiwyg', 'multilangField' => 'longDescription', 'multilangLocale' => 'de_CH'],
        'tastingNotes'        => ['type' => 'text', 'isMultilang' => true],
        'tastingNotesDeCh'    => ['type' => 'text', 'multilangField' => 'tastingNotes', 'multilangLocale' => 'de_CH'],
    ];

    private FakeRecords $records;

    protected function setUp(): void
    {
        $this->records = new FakeRecords(['Product:p1' => [
            'name' => 'Praline box', 'nameDeCh' => '', 'nameXxYy' => '',
            'longDescription' => '<p>Filled <b>by hand</b>.</p>', 'longDescriptionDeCh' => '',
            'tastingNotes' => 'Dark, nutty', 'tastingNotesDeCh' => 'Dunkel',
        ]]);
    }

    private function exchange(): TranslationFileExchange
    {
        return new TranslationFileExchange($this->records, Settings::fromConnectionData(['supertextLanguageCodes' => 'de_CH = de-CH'], 'k'));
    }

    public function testReferenceRoundTrip(): void
    {
        $reference = TranslationFileExchange::reference('Product', '6650a1b2c3', 'en_US', 'de_CH');

        self::assertSame('Product:6650a1b2c3:en_US:de_CH', $reference);
        self::assertSame(['entity' => 'Product', 'id' => '6650a1b2c3', 'source' => 'en_US', 'target' => 'de_CH'], TranslationFileExchange::parseReference($reference));

        foreach (['page:12', 'Product:1:en_US', 'Product:a b:en_US:de_CH', '../x:1:en_US:de_CH', 'Product:1:en_US:de_CH:x'] as $bad) {
            self::assertNull(TranslationFileExchange::parseReference($bad), $bad);
        }

        self::assertSame('atropim-product-p1-de_CH.xlf', TranslationFileExchange::fileName('Product', 'p1', ['de_CH'], 'xliff'));
        self::assertSame('atropim-product-p1.xlf', TranslationFileExchange::fileName('Product', 'p1', ['de_CH', 'xx_YY'], 'xliff'));
        self::assertSame('atropim-product-p1-de_CH.html', TranslationFileExchange::fileName('Product', 'p1', ['de_CH'], 'html'));
        self::assertSame('application/x-xliff+xml', TranslationFileExchange::contentType('xliff'));
        self::assertSame('text/html; charset=utf-8', TranslationFileExchange::contentType('html'));
    }

    public function testExportUsesTheCollectorsKeysAndTheMapping(): void
    {
        $file  = $this->exchange()->export('Product', 'p1', '', [], 'xliff', false);
        $files = TranslationFiles::read($file['content']);

        self::assertSame(['de_CH', 'xx_YY'], $file['targets']);
        self::assertSame('atropim-product-p1.xlf', $file['fileName']);
        self::assertCount(2, $files);
        self::assertSame(TranslationFileExchange::PLUGIN, $files[0]->plugin);
        self::assertSame('Product:p1:en_US:de_CH', $files[0]->reference);
        self::assertSame(['en', 'de-CH'], [$files[0]->sourceLanguage, $files[0]->targetLanguage], 'the mapping and the source code');
        self::assertSame('xx-YY', $files[1]->targetLanguage, 'an unmapped language never blocks the export');
        // tastingNotes already has German text: kept, as in an API translation without overwrite.
        self::assertSame(['name', 'longDescription'], array_map(static fn ($u) => $u->key, $files[0]->units));
        self::assertSame(['name'], array_map(static fn ($u) => $u->key, $files[1]->units));

        $all = TranslationFiles::read($this->exchange()->export('Product', 'p1', '', ['de_CH'], 'xliff', true)['content']);
        self::assertSame(['name', 'longDescription', 'tastingNotes'], array_map(static fn ($u) => $u->key, $all[0]->units));
    }

    public function testExportHtmlAndErrors(): void
    {
        $html = $this->exchange()->export('Product', 'p1', '', ['de_CH'], 'html', false);
        self::assertSame('text/html; charset=utf-8', $html['contentType']);
        self::assertStringContainsString('data-st-id', $html['content']);
        self::assertSame('html', TranslationFiles::read($html['content'])[0]->format);

        foreach ([
            'html_one_language'    => fn () => $this->exchange()->export('Product', 'p1', '', [], 'html', false),
            'record_not_found'     => fn () => $this->exchange()->export('Product', 'nope', '', [], 'xliff', false),
            'not_a_language'       => fn () => $this->exchange()->export('Product', 'p1', 'fr_FR', [], 'xliff', false),
            'no_targets'           => fn () => $this->exchange()->export('Product', 'p1', '', ['en_US'], 'xliff', false),
        ] as $key => $call) {
            try {
                $call();
                self::fail("Expected $key");
            } catch (SupertextException $e) {
                self::assertSame($key, $e->key);
            }
        }

        $this->records->values['Product:p1'] = ['name' => 'Box', 'nameDeCh' => 'Schachtel', 'nameXxYy' => 'Box', 'longDescription' => '<p><br></p>', 'tastingNotes' => ''];

        try {
            $this->exchange()->export('Product', 'p1', '', [], 'xliff', false);
            self::fail('Expected nothing_to_translate');
        } catch (SupertextException $e) {
            self::assertSame('nothing_to_translate', $e->key);
            self::assertSame('Nothing to translate.', (new Messages())->exception($e));
        }
    }

    public function testImportWritesOkSegmentsOnly(): void
    {
        $xliff = $this->exchange()->export('Product', 'p1', '', ['de_CH'], 'xliff', false)['content'];
        $xliff = $this->translate($xliff, 'name', 'Pralinenschachtel');
        $xliff = $this->translate($xliff, 'longDescription', '<bpt id="1">p</bpt>Von <bpt id="2">b</bpt>Hand<ept id="2">/b</ept> gefüllt.<ept id="1">/p</ept>');

        $jobs = $this->exchange()->import($xliff, false, false);

        self::assertCount(1, $jobs);
        self::assertNull($jobs[0]['error']);
        self::assertSame(2, $jobs[0]['counts']['imported']);
        self::assertSame(['Product:p1' => ['nameDeCh' => 'Pralinenschachtel', 'longDescriptionDeCh' => '<p>Von <b>Hand</b> gefüllt.</p>']], $this->records->saved);

        $summary = TranslationFileExchange::summaryText(TranslationFileExchange::summary($jobs, new Messages()));
        self::assertSame("Product p1, de_CH\n  Imported: 2", $summary);
    }

    public function testSourceChangedOnlyWithTheOptionAndOthersNeverWritten(): void
    {
        $xliff = $this->exchange()->export('Product', 'p1', '', ['de_CH'], 'xliff', false)['content'];
        $xliff = $this->translate($xliff, 'name', 'Pralinenschachtel');
        // longDescription stays untranslated (empty target).
        $this->records->values['Product:p1']['name'] = 'Praline box (new)';

        $jobs = $this->exchange()->import($xliff, false, false);
        self::assertSame(0, $jobs[0]['counts']['imported']);
        self::assertSame(1, $jobs[0]['counts']['source_changed']);
        self::assertSame(1, $jobs[0]['counts']['untranslated']);
        self::assertSame([], $this->records->saved);

        $jobs = $this->exchange()->import($xliff, true, false);
        self::assertSame(1, $jobs[0]['counts']['imported']);
        self::assertSame(['Product:p1' => ['nameDeCh' => 'Pralinenschachtel']], $this->records->saved);
    }

    public function testInvalidMarkupMissingAndKept(): void
    {
        $html = $this->exchange()->export('Product', 'p1', '', ['de_CH'], 'html', false)['content'];
        $html = str_replace('Filled <b>by hand</b>.', 'Von <script>x</script>Hand gefüllt.', $html);

        // A field added (filled in the source) since the export, and one translated meanwhile.
        $this->records->defs['subtitle']     = ['type' => 'varchar', 'isMultilang' => true];
        $this->records->defs['subtitleDeCh'] = ['type' => 'varchar', 'multilangField' => 'subtitle', 'multilangLocale' => 'de_CH'];
        $this->records->values['Product:p1'] += ['subtitle' => 'Swiss chocolate', 'subtitleDeCh' => ''];
        $this->records->values['Product:p1']['nameDeCh'] = 'Schachtel';

        $jobs   = $this->exchange()->import($html, false, false);
        $counts = $jobs[0]['counts'];

        self::assertSame(['imported' => 0, 'source_changed' => 0, 'untranslated' => 0, 'invalid_markup' => 1, 'missing' => 1, 'kept' => 1], $counts);
        self::assertSame([], $this->records->saved);

        $lines = TranslationFileExchange::summary($jobs, new Messages())[0]['lines'];
        self::assertSame(['Imported: 0', 'Formatting changed, not imported: 1', 'Not in the file: 1', 'Kept, already translated in AtroPIM: 1'], $lines);

        // Imported unchanged, an HTML file writes the source text back (with overwrite, name too).
        $unchanged = $this->exchange()->export('Product', 'p1', '', ['de_CH'], 'html', true)['content'];
        $jobs      = $this->exchange()->import($unchanged, false, true);
        self::assertSame(4, $jobs[0]['counts']['imported']);
        self::assertSame('Praline box', $this->records->saved['Product:p1']['nameDeCh']);
    }

    public function testJobErrors(): void
    {
        $segments = [new FileSegment('name', 'Praline box')];
        $file     = static fn (string $plugin, string $reference): string => TranslationFiles::exportXliff([new FileJob($plugin, $reference, 'en', 'de-CH', $segments)]);
        $error    = fn (string $content): array => [$this->exchange()->import($content, false, false)[0]['error'], $this->exchange()->import($content, false, false)[0]['errorParams']];

        self::assertSame(['file_other_system', ['plugin' => 'Contao-Supertext-Translation']], $error($file('Contao-Supertext-Translation', 'Product:p1:en_US:de_CH')));
        self::assertSame(['file_not_found', []], $error($file(TranslationFileExchange::PLUGIN, 'page:12')));
        self::assertSame(['file_not_found', []], $error($file(TranslationFileExchange::PLUGIN, 'Product:gone:en_US:de_CH')));
        self::assertSame(['file_no_language', ['language' => 'fr_FR']], $error($file(TranslationFileExchange::PLUGIN, 'Product:p1:en_US:fr_FR')));

        $this->records->editable = false;
        self::assertSame(['file_not_allowed', []], $error($file(TranslationFileExchange::PLUGIN, 'Product:p1:en_US:de_CH')));
        self::assertSame([], $this->records->saved);

        $summary = TranslationFileExchange::summary($this->exchange()->import($file('Other-Plugin', 'x'), false, false), new Messages());
        self::assertSame([['title' => 'x', 'lines' => [], 'error' => 'This file belongs to another system (Other-Plugin).']], $summary);
    }

    public function testRejectedAndTooLong(): void
    {
        $xliff = $this->exchange()->export('Product', 'p1', '', ['de_CH'], 'xliff', false)['content'];
        $xliff = $this->translate($xliff, 'name', str_repeat('Pralinen', 10));
        $xliff = $this->translate($xliff, 'longDescription', '<bpt id="1">p</bpt>Von <bpt id="2">b</bpt>Hand<ept id="2">/b</ept> gefüllt.<ept id="1">/p</ept>');

        $jobs = $this->exchange()->import($xliff, false, false);
        self::assertSame(1, $jobs[0]['counts']['imported']);
        self::assertSame(['name'], $jobs[0]['tooLong'], 'longer than maxLength: not saved');

        $this->records->reject = 'Validation failed';
        $jobs = $this->exchange()->import($xliff, false, false);
        self::assertSame(['rejected', ['reason' => 'Validation failed']], [$jobs[0]['error'], $jobs[0]['errorParams']]);
    }

    public function testFileErrors(): void
    {
        foreach ([
            'file_too_large'        => str_repeat('a', TranslationFileExchange::MAX_UPLOAD_BYTES + 1),
            'file_invalid_file'     => 'not a translation file',
            'file_missing_metadata' => '<!doctype html><html><head></head><body><p data-st-id="1">x</p></body></html>',
        ] as $key => $content) {
            try {
                $this->exchange()->import($content, false, false);
                self::fail("Expected $key");
            } catch (SupertextException $e) {
                self::assertSame($key, $e->key);
                self::assertArrayHasKey(Messages::name($key), Messages::englishTexts());
            }
        }

        self::assertSame(10_000_000, TranslationFileExchange::MAX_UPLOAD_BYTES);
    }

    public function testImportPage(): void
    {
        self::assertNull(ImportPage::formToken([]));
        $token = ImportPage::formToken(['auth-token' => 'abc']);
        self::assertSame(hash('sha256', 'supertext-import|abc'), $token);

        $page = ImportPage::render(new Messages(), $token, 'Bad <file>', [['title' => 'Product <p1>, de_CH', 'lines' => ['Imported: 1'], 'error' => null]]);
        self::assertStringContainsString('name="token" value="' . $token . '"', $page);
        self::assertStringContainsString('Bad &lt;file&gt;', $page);
        self::assertStringContainsString('Product &lt;p1&gt;, de_CH', $page);
        self::assertStringContainsString('Translated file (XLIFF or HTML)', $page);
        self::assertStringContainsString('Also import texts whose source changed since the export', $page);
        self::assertStringNotContainsString('name="token"', ImportPage::render(new Messages(), null, null, null));
    }

    /** Fills the <target> of one trans-unit, as a translation tool does. */
    private function translate(string $xliff, string $key, string $target): string
    {
        return (string) preg_replace_callback(
            '~(<trans-unit id="' . preg_quote($key, '~') . '".*?</source>)(\s*<target[^>]*>.*?</target>|\s*<target[^>]*/>)?~s',
            static fn (array $m): string => $m[1] . '<target>' . $target . '</target>',
            $xliff,
        );
    }
}

/** AtroCore stand-in: products with the DEFS fields, languages en_US, de_CH, xx_YY. */
final class FakeRecords implements RecordSource
{
    /** @var array<string, array<string, mixed>> */
    public array $defs;

    /** @var array<string, array<string, string>> */
    public array $saved = [];

    public bool $editable = true;

    public string $reject = '';

    /** @param array<string, array<string, mixed>> $values "Entity:id" => field values */
    public function __construct(public array $values)
    {
        $this->defs = (new \ReflectionClassConstant(TranslationFileExchangeTest::class, 'DEFS'))->getValue();
    }

    public function mainLanguage(): string
    {
        return 'en_US';
    }

    public function languages(): array
    {
        return ['en_US', 'de_CH', 'xx_YY'];
    }

    public function record(string $entityType, string $id): ?array
    {
        if (!isset($this->values["$entityType:$id"])) {
            return null;
        }

        return ['units' => Planner::units($this->defs, 'en_US'), 'values' => $this->values["$entityType:$id"], 'editable' => $this->editable];
    }

    public function save(string $entityType, string $id, array $data): void
    {
        if ($this->reject !== '') {
            throw new \RuntimeException($this->reject);
        }

        $this->saved["$entityType:$id"] = $data;
    }
}
