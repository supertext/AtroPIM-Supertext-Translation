<?php

namespace SupertextTranslation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SupertextTranslation\Translation\CommandArguments;
use SupertextTranslation\Translation\EntityTranslator;

class CommandTest extends TestCase
{
    public function testParsesArguments(): void
    {
        self::assertSame(
            ['entityType' => 'Product', 'id' => 'abc', 'targets' => ['de_CH', 'fr_CH'], 'overwrite' => true, 'source' => 'en_US', 'politeness' => 'more', 'format' => 'xliff', 'output' => '', 'includeChanged' => false],
            CommandArguments::parse(' Product  abc de_CH,fr_CH --overwrite --source=en_US --politeness=more'),
        );
        self::assertSame([], CommandArguments::parse('Product abc all')['targets']);
        self::assertSame('', CommandArguments::parse('')['entityType']);

        $export = CommandArguments::parse('Product abc de_CH --format=html --output=/tmp/p.html');
        self::assertSame(['html', '/tmp/p.html'], [$export['format'], $export['output']]);

        $import = CommandArguments::parse('/tmp/p.xlf --include-changed');
        self::assertSame(['/tmp/p.xlf', true, false], [$import['entityType'], $import['includeChanged'], $import['overwrite']]);
    }

    public function testSummary(): void
    {
        $summary = EntityTranslator::summary([
            'de_CH' => ['status' => 'translated', 'translated' => 1, 'existing' => 0, 'message' => ''],
            'fr_CH' => ['status' => 'nothing', 'translated' => 0, 'existing' => 2, 'message' => ''],
            'it_CH' => ['status' => 'error', 'translated' => 0, 'existing' => 0, 'message' => 'Timed out.'],
            'rm_CH' => ['status' => 'nothing', 'translated' => 0, 'existing' => 1, 'message' => ''],
            'en_GB' => ['status' => 'translated', 'translated' => 1, 'existing' => 0, 'message' => ''],
        ]);

        self::assertSame("de_CH, en_GB: translated (1 field)\nfr_CH, rm_CH: kept, already translated\nit_CH: error: Timed out.", $summary);
        self::assertSame('No other languages to translate into.', EntityTranslator::summary([]));
    }

    public function testRunsMessage(): void
    {
        self::assertSame('de_CH: translated (2 fields); fr_CH: translated (2 fields)', EntityTranslator::runsMessage([
            ['ok' => true, 'summary' => "de_CH: translated (2 fields)\nfr_CH: translated (2 fields)"],
        ]));
        self::assertStringStartsWith('Supertext translated 2 of 3 records.', EntityTranslator::runsMessage([
            ['ok' => true, 'summary' => 'x'], ['ok' => false, 'summary' => 'y'], ['ok' => true, 'summary' => 'z'],
        ]));
    }
}
