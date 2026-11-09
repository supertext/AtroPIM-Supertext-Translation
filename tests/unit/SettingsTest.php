<?php

namespace SupertextTranslation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SupertextTranslation\Api\SupertextClient;
use SupertextTranslation\Api\SupertextException;
use SupertextTranslation\Translation\Messages;
use SupertextTranslation\Translation\Settings;

class SettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SUPERTEXT_API_KEY');
        putenv('SUPERTEXT_API_URL');
        putenv('SUPERTEXT_REVIEW_URL');
        unset($_SERVER['SUPERTEXT_REVIEW_URL'], $_ENV['SUPERTEXT_REVIEW_URL']);
        unset($_SERVER['SUPERTEXT_API_KEY'], $_ENV['SUPERTEXT_API_KEY'], $_SERVER['SUPERTEXT_API_URL'], $_ENV['SUPERTEXT_API_URL']);
    }

    public function testConnectionData(): void
    {
        $settings = Settings::fromConnectionData([
            'supertextEnvironment'    => 'custom',
            'supertextApiUrl'         => 'https://example.test/v1/',
            'supertextTimeout'        => 600,
            'supertextLanguageCodes'  => "de_DE = de-CH\nnonsense\nfr_FR: fr-CH\ngsw_CH = gsw-u-sd-chzh\nes_ES = es",
            'supertextVersionReports' => false,
        ], 'Supertext-Auth-Key abc');

        self::assertSame('abc', $settings->apiKey());
        self::assertSame('connection', $settings->apiKeySource());
        self::assertSame('https://example.test/v1/', $settings->baseUrl());
        self::assertSame(600, $settings->timeout());
        self::assertFalse($settings->versionReports());
        self::assertSame(['de_DE' => 'de-CH', 'fr_FR' => 'fr-CH', 'gsw_CH' => 'gsw-u-sd-chzh', 'es_ES' => 'es'], $settings->languageMapping());
        self::assertNull(Settings::fromConnectionData([], '')->versionReports(), 'not set: on by default');
    }

    public function testTheMappingWinsElseAnExactSupertextCode(): void
    {
        $settings = Settings::fromConnectionData(['supertextLanguageCodes' => "de_DE = de-CH\nes_ES = es\nen_US = en-GB"], 'k');

        self::assertSame('de-CH', $settings->languageCode('de_DE'));
        self::assertSame('es', $settings->languageCode('es_ES'));
        self::assertSame('it-CH', $settings->languageCode('it_CH'), 'equal to a Supertext code apart from case and _/-');
        self::assertSame('fr-FR', $settings->languageCode('fr_FR'));
        self::assertSame('nl', $settings->languageCode('nl'));
        self::assertSame('zh-Hant', $settings->languageCode('zh_hant'));

        // Sources: the same mapping in its source form, else the matching source language.
        self::assertSame('de', $settings->sourceCode('de_DE'));
        self::assertSame('en', $settings->sourceCode('en_US'));
        self::assertSame('it', $settings->sourceCode('it_CH'));
        self::assertSame('', $settings->sourceCode(''));
    }

    public function testNoGuessing(): void
    {
        $settings = new Settings('k');

        // Since SDK 0.4.0 an obvious default applies (de → de-DE, es_ES → es, zh_CN → zh-Hans); nothing else is guessed.
        foreach (['de' => 'de-DE', 'es_ES' => 'es', 'sl_SI' => 'sl', 'zh_CN' => 'zh-Hans', 'en' => 'en-US'] as $language => $code) {
            self::assertSame($code, $settings->languageCodeOrNull($language), "$language has a default");
        }

        foreach (['de_LI', 'en_IE', 'fr_BE', 'sr_RS', 'gsw'] as $language) {
            self::assertNull($settings->languageCodeOrNull($language), "$language must be mapped");
        }

        try {
            $settings->languageCode('fr_BE');
            self::fail('Expected unsupported_language');
        } catch (SupertextException $e) {
            self::assertSame('unsupported_language', $e->key);
            self::assertSame(['language' => 'fr_BE'], $e->params);
            self::assertStringContainsString('The language fr_BE has no Supertext language yet', $e->getMessage());
            self::assertStringStartsWith('The language fr_BE has no Supertext language yet.', (new Messages())->exception($e));
        }

        $this->expectException(SupertextException::class);
        $settings->sourceCode('xx_YY');
    }

    public function testLanguageStatusShowsUnmappedLanguagesAndMissingTones(): void
    {
        $settings = Settings::fromConnectionData(['supertextLanguageCodes' => "es_ES = es\nrm_CH = rm\nxx_XX = xx-YY"], 'k');
        $status   = $settings->languageStatus(['en_US', 'de_CH', 'es_ES', 'fr_BE', 'rm_CH', 'xx_XX'], 'en_US', new Messages());

        self::assertSame(
            "en_US → en-US (no formal/informal tone) · as source language: en\n"
            . "de_CH → de-CH\n"
            . "es_ES → es\n"
            . 'fr_BE: no Supertext language. Choose one in its combo box on the Supertext connection; until then it isn\'t translated.' . "\n"
            . "rm_CH → rm (no formal/informal tone)\n"
            . "xx_XX → xx-YY (not in Supertext's list of October 2026: sent as entered)",
            $status,
        );
    }

    public function testDefaultsAndEnvironmentFallback(): void
    {
        $settings = new Settings('');
        self::assertSame('', $settings->apiKeySource());
        self::assertSame(SupertextClient::LIVE, $settings->baseUrl());
        self::assertSame(180, (new Settings('', timeout: 5))->timeout());

        putenv('SUPERTEXT_API_KEY=env-key');
        putenv('SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/');
        self::assertSame('env-key', $settings->apiKey());
        self::assertSame('environment', $settings->apiKeySource());
        self::assertSame('http://127.0.0.1:8765/v1/', $settings->baseUrl());
    }

    public function testExplainAddsKeyLinksToAuthenticationErrors(): void
    {
        self::assertStringContainsString(Settings::API_KEY_URL, Settings::explain(new SupertextException('Authentication failed.', 401)));
        self::assertStringContainsString(Settings::SIGNUP_URL, Settings::explain(new SupertextException('Authentication failed.', 403)));
        self::assertSame('Timed out.', Settings::explain(new SupertextException('Timed out.')));
    }

    public function testTranslationReview(): void
    {
        $languages = ['en_US', 'de_CH', 'fr_CH'];
        $data      = ['supertextReviewDeCh' => true, 'supertextReviewFrCh' => false];

        $settings = Settings::fromConnectionData($data, 'k', $languages);
        self::assertSame(SupertextClient::REVIEW_URL, $settings->reviewUrl(), 'default: Supertext\'s review service');
        self::assertTrue($settings->review('de_CH'));
        self::assertFalse($settings->review('fr_CH'), 'off by default');
        self::assertFalse($settings->review('en_US'));
        self::assertNotSame($settings->client(), $settings->client()->forReview(true));

        $off = Settings::fromConnectionData($data + ['supertextReviewUrl' => ''], 'k', $languages);
        self::assertSame('', $off->reviewUrl());
        self::assertFalse($off->review('de_CH'), 'empty review URL: off for every language');
        $client = $off->client();
        self::assertSame($client, $client->forReview(true));

        $custom = Settings::fromConnectionData($data + ['supertextReviewUrl' => 'https://review.example/v1/'], 'k', $languages);
        self::assertSame('https://review.example/v1/', $custom->reviewUrl());

        putenv('SUPERTEXT_REVIEW_URL=http://127.0.0.1:8765/v1/');
        self::assertSame('http://127.0.0.1:8765/v1/', $off->reviewUrl(), 'the environment wins');
        self::assertTrue($off->review('de_CH'));

        $status = $settings->languageStatus($languages, 'en_US', new Messages());
        self::assertStringContainsString("de_CH → de-CH · translation review\n", $status);
        self::assertStringContainsString("fr_CH → fr-CH\n", $status . "\n");
    }
}
