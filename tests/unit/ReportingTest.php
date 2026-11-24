<?php

declare(strict_types=1);

namespace SupertextTranslation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SupertextTranslation\Api\CallableTransport;
use SupertextTranslation\PluginHealth\KeyCheck;
use SupertextTranslation\Sdk\Supertext;
use SupertextTranslation\Translation\Messages;
use SupertextTranslation\Translation\Reporting;

/** Version reports, the automatic API key check and the ping endpoint, against a fake transport (never the live API or center). */
final class ReportingTest extends TestCase
{
    private const SITE = 'https://pim.example.ch/';
    private const API = 'https://api.test/v1/';
    private const CENTER = 'https://center.test/api/v1/report';

    /** @var list<array{method: string, url: string, body: ?string, agent: string}> */
    private array $calls = [];

    private ?string $stored = null;

    /** HTTP status the fake API answers GET features with. */
    private int $keyStatus = 200;

    /** HTTP status the fake center answers reports with. */
    private int $centerStatus = 202;

    private float $now = 1_791_964_800.0; // 2026-10-14 08:00:00 UTC

    protected function tearDown(): void
    {
        putenv('SUPERTEXT_VERSION_REPORTS');
    }

    private function reporting(?bool $setting = null, string $pluginVersion = '1.2.0', string $systemVersion = '1.16.10', bool $failingStore = false, ?callable $languages = null): Reporting
    {
        $transport = new CallableTransport(function (string $method, string $url, array $headers, ?string $body): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body, 'agent' => $headers['User-Agent'] ?? ''];
            self::assertStringNotContainsString('secret-key', (string) $body, 'the API key is never sent to the center');

            return str_starts_with($url, self::CENTER)
                ? ['status' => $this->centerStatus, 'body' => '{"ok":true}']
                : ['status' => $this->keyStatus, 'body' => $this->keyStatus === 200 ? '[]' : '{"error_code":"UNAUTHORIZED"}'];
        });

        return Reporting::create(
            $setting,
            self::SITE,
            $pluginVersion,
            'AtroPIM',
            $systemVersion,
            self::API,
            load: function (): ?string {
                return $this->stored;
            },
            save: function (string $state) use ($failingStore): void {
                if ($failingStore) {
                    throw new \RuntimeException('config not writable');
                }
                $this->stored = $state;
            },
            transport: $transport,
            reportUrl: self::CENTER,
            sleep: static function (): void {
            },
            now: fn (): float => $this->now,
            languages: $languages,
        );
    }

    /** @return list<array<string, mixed>> */
    private function reports(): array
    {
        $reports = [];

        foreach ($this->calls as $call) {
            if ($call['url'] === self::CENTER) {
                $reports[] = json_decode((string) $call['body'], true);
            }
        }

        return $reports;
    }

    private function keyChecks(): int
    {
        return \count(array_filter($this->calls, static fn (array $c): bool => $c['url'] === self::API . 'features'));
    }

    public function testSettingsSavedChecksTheKeyAndReportsInstalledThenSettingsSaved(): void
    {
        $check = $this->reporting()->afterSettingsSaved('secret-key');

        self::assertNotNull($check);
        self::assertSame(KeyCheck::OK, $check->status);
        self::assertSame(1, $this->keyChecks());

        $report = $this->reports()[0];
        self::assertSame('installed', $report['event']);
        self::assertSame('https://pim.example.ch', $report['site_url']);
        self::assertSame(['name' => 'AtroPIM-Supertext-Translation', 'version' => '1.2.0'], $report['plugin']);
        self::assertSame(['name' => 'AtroPIM', 'version' => '1.16.10'], $report['system']);
        self::assertSame('PHP', $report['runtime']['name']);
        self::assertSame(Supertext::SDK_VERSION, $report['sdk_version']);
        self::assertTrue($report['api_key_configured']);
        self::assertSame('ok', $report['api_key_check']['status']);
        self::assertSame(['url' => 'https://pim.example.ch/api/supertext/ping'], $report['callback']);

        $this->reporting()->afterSettingsSaved('secret-key');
        self::assertSame('settings_saved', $this->reports()[1]['event']);

        $state = json_decode((string) $this->stored, true);
        self::assertIsArray($state);
        self::assertStringNotContainsString('secret-key', (string) $this->stored, 'only a fingerprint is stored');
    }

    public function testTheKeyCheckIsTheSdkClientsAgainstTheConnectionsApi(): void
    {
        $this->reporting(false)->afterSettingsSaved('secret-key');

        self::assertCount(1, $this->calls);
        self::assertSame(['GET', self::API . 'features'], [$this->calls[0]['method'], $this->calls[0]['url']]);
        self::assertStringContainsString('AtroPIM-Supertext-Translation/1.2.0', $this->calls[0]['agent']);
    }

    public function testStateStoredByTheSdk02ReporterIsKept(): void
    {
        // What the Supertext SDK 0.2 reporter stored before the switch to Plugin Health.
        $this->stored = '{"reportedVersions":"1.2.0|1.16.10|' . PHP_VERSION . '","reportedKeyStatus":"ok","lastFailureAt":null,'
            . '"keyCheck":{"status":"ok","checkedAt":"2026-10-13T08:00:00Z","httpStatus":200,"errorCode":null,"fingerprint":"' . hash('sha256', 'secret-key') . '"}}';

        self::assertSame('2026-10-13T08:00:00Z', $this->reporting()->latestCheck()?->checkedAt);
        self::assertNull($this->reporting()->onRequest('secret-key')?->event, 'no new installed report after the update');
        self::assertSame([], $this->calls);

        $this->reporting()->afterSettingsSaved('secret-key');
        self::assertSame('settings_saved', $this->reports()[0]['event']);
        self::assertSame(
            // Plugin Health 0.2+ adds keyChecks and reportedKeyStatuses (null with one key).
            ['reportedVersions', 'reportedKeyStatus', 'lastFailureAt', 'keyCheck', 'keyChecks', 'reportedKeyStatuses'],
            array_keys(json_decode((string) $this->stored, true)),
            'same stored format',
        );
    }

    public function testOnRequestReportsOnlyChanges(): void
    {
        $this->reporting()->afterSettingsSaved('secret-key');
        $this->calls = [];

        $this->reporting()->onRequest('secret-key');
        self::assertSame([], $this->calls, 'nothing changed: no key check, no report');

        $this->reporting(pluginVersion: '1.3.0')->onRequest('secret-key');
        self::assertSame(0, $this->keyChecks(), 'same key: not checked again');
        self::assertSame('upgraded', $this->reports()[0]['event']);

        $this->calls     = [];
        $this->keyStatus = 401;
        $this->reporting(pluginVersion: '1.3.0')->onRequest('other-key');
        self::assertSame(1, $this->keyChecks(), 'a changed key is checked');
        self::assertSame('api_key_check', $this->reports()[0]['event']);
        self::assertSame('invalid', $this->reports()[0]['api_key_check']['status']);
    }

    public function testAuthenticationFailedMarksTheKeyInvalidWithoutARequest(): void
    {
        $this->reporting()->afterSettingsSaved('secret-key');
        $this->calls = [];

        $result = $this->reporting()->authenticationFailed('secret-key', 403);

        self::assertSame(0, $this->keyChecks());
        self::assertSame('api_key_check', $result?->event);
        self::assertSame(['status' => 'invalid', 'checked_at' => '2026-10-14T08:00:00Z', 'http_status' => 403], $this->reports()[0]['api_key_check']);
        self::assertSame(KeyCheck::INVALID, $this->reporting()->latestCheck()?->status);

        $this->calls = [];
        $this->reporting()->authenticationFailed('secret-key', 401);
        self::assertSame([], $this->reports(), 'no report per failed translation');
    }

    public function testTestConnectionAndCheck(): void
    {
        $this->keyStatus = 401;
        $check           = $this->reporting()->testConnection('secret-key');
        self::assertSame(KeyCheck::INVALID, $check?->status);
        self::assertSame('installed', $this->reports()[0]['event']);

        $this->calls     = [];
        $this->keyStatus = 200;
        $result          = $this->reporting()->check('secret-key');
        self::assertSame(KeyCheck::OK, $result?->check?->status);
        self::assertSame(['api_key_check', 'check'], array_column($this->reports(), 'event'));
    }

    public function testMissingKeyIsNotSent(): void
    {
        $check = $this->reporting()->afterSettingsSaved('');

        self::assertSame(KeyCheck::MISSING, $check?->status);
        self::assertSame(0, $this->keyChecks());
        self::assertFalse($this->reports()[0]['api_key_configured']);
    }

    public function testOffMeansOff(): void
    {
        $check = $this->reporting(false)->afterSettingsSaved('secret-key');

        self::assertSame(KeyCheck::OK, $check?->status, 'the key check runs whether or not reports are on');
        self::assertSame([], $this->reports());

        putenv('SUPERTEXT_VERSION_REPORTS=0');
        $this->reporting(true)->afterSettingsSaved('secret-key');
        self::assertSame([], $this->reports(), 'the environment wins over the setting');
        self::assertTrue(Reporting::offByEnvironment(true));
        self::assertFalse(Reporting::offByEnvironment(false));
    }

    public function testAFailedReportIsRetriedAfterAnHour(): void
    {
        $this->centerStatus = 503;
        $this->reporting()->afterSettingsSaved('secret-key');
        $this->calls        = [];
        $this->centerStatus = 202;

        $this->reporting()->onRequest('secret-key');
        self::assertSame([], $this->reports(), 'within the hour: not tried again');

        $this->now += 3601;
        $this->reporting()->onRequest('secret-key');
        self::assertSame('installed', $this->reports()[0]['event']);
    }

    public function testSendConfigurationSendsModulesAndTheLanguageMappingNow(): void
    {
        $languages = [['code' => 'en_US', 'supertext' => 'en-US'], ['code' => 'de_CH', 'supertext' => 'de-CH'], ['code' => 'fr_BE', 'supertext' => null]];
        $modules   = [['name' => 'atrocore/pim', 'version' => '1.16.10', 'active' => true, 'type' => 'module']];

        // Within the hour after a failed report: still sent, the administrator asked for it.
        $this->centerStatus = 503;
        $this->reporting()->afterSettingsSaved('secret-key');
        $this->calls        = [];
        $this->centerStatus = 202;

        $result = $this->reporting(languages: static fn (): array => $languages)->sendConfiguration($modules);

        self::assertSame('extensions', $result?->event);
        self::assertTrue($result->sent);
        [$report] = $this->reports();
        self::assertSame('extensions', $report['event']);
        self::assertSame($modules, $report['extensions']);
        self::assertSame($languages, $report['languages']);
    }

    public function testSendConfigurationSendsNothingWithReportsOff(): void
    {
        self::assertFalse((bool) $this->reporting(false)->sendConfiguration([['name' => 'atrocore/pim']])?->sent);
        self::assertSame([], $this->reports());
    }

    public function testABrokenStoreNeverFails(): void
    {
        $check = $this->reporting(failingStore: true)->afterSettingsSaved('secret-key');

        self::assertSame(KeyCheck::OK, $check?->status);
        self::assertNull($this->stored);

        $this->stored = '{not json';
        self::assertNotNull($this->reporting()->onRequest('secret-key'));
    }

    public function testPing(): void
    {
        $ok = Reporting::ping('abcdefghijklmnop_-12', null);
        self::assertSame(200, $ok->status);
        self::assertSame(['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], $ok->headers);
        self::assertSame('{"ok":true,"plugin":"AtroPIM-Supertext-Translation","challenge":"abcdefghijklmnop_-12"}', $ok->body);

        self::assertSame(400, Reporting::ping('short', true)->status);
        self::assertSame(400, Reporting::ping('<script>alert(1)</script>xxxxxxxx', true)->status);
        self::assertSame(400, Reporting::ping(null, true)->status);
        self::assertSame(404, Reporting::ping('abcdefghijklmnop_-12', false)->status);

        putenv('SUPERTEXT_VERSION_REPORTS=off');
        self::assertSame(404, Reporting::ping('abcdefghijklmnop_-12', true)->status);
    }

    public function testCallbackUrlAndSiteUrlWithoutHost(): void
    {
        self::assertSame('https://pim.example.ch/shop/api/supertext/ping', Reporting::callbackUrl('https://pim.example.ch/shop/'));

        $reporting = Reporting::create(null, '', '1.0.0', 'AtroPIM', null, self::API, static fn () => null, static function (): void {
        }, transport: new CallableTransport(fn () => ['status' => 200, 'body' => '[]']), reportUrl: self::CENTER);
        self::assertFalse($reporting->isEnabled() && $reporting->pingUrl() !== '', 'no site URL: nothing to report');
    }

    public function testCheckAndStatusTexts(): void
    {
        $messages = new Messages();

        self::assertSame('Not checked yet: save the connection or click Test connection.', Reporting::checkText(null, $messages));
        self::assertSame(
            'API key checked: the connection to Supertext works. Checked 2026-10-14 08:00 UTC.',
            Reporting::checkText(new KeyCheck('ok', '2026-10-14T08:00:00Z', 200), $messages),
        );
        self::assertStringContainsString('https://www.supertext.com/en/integrations/api', Reporting::checkText(new KeyCheck('invalid', '2026-10-14T08:00:00Z', 401), $messages));
        self::assertStringContainsString('https://www.supertext.com/person/en/account/signin', Reporting::checkText(new KeyCheck('missing', '2026-10-14T08:00:00Z'), $messages));
        self::assertStringStartsWith("The API key couldn't be checked because", Reporting::checkText(new KeyCheck('unreachable', '2026-10-14T08:00:00Z'), $messages));
        self::assertStringContainsString('error (HTTP 429)', Reporting::checkText(new KeyCheck('error', '2026-10-14T08:00:00Z', 429), $messages));

        $german = json_decode((string) file_get_contents(__DIR__ . '/../../app/Resources/i18n/de_DE/Action.json'), true)['messages'];
        $de     = new Messages(static fn (string $name): string => $german[$name] ?? $name);
        self::assertStringStartsWith('API-Schlüssel geprüft: Die Verbindung zu Supertext funktioniert. Geprüft am', Reporting::checkText(new KeyCheck('ok', '2026-10-14T08:00:00Z', 200), $de));

        $reporting = $this->reporting();
        $reporting->afterSettingsSaved('secret-key');
        self::assertSame(
            "API key checked: the connection to Supertext works. Checked 2026-10-14 08:00 UTC.\n"
            . 'Version information is sent to Supertext. Ping URL for the Supertext Commando Center: https://pim.example.ch/api/supertext/ping',
            $reporting->statusText(null, $messages),
        );
        self::assertStringEndsWith('Version information is not sent to Supertext.', $this->reporting(false)->statusText(false, $messages));

        putenv('SUPERTEXT_VERSION_REPORTS=0');
        self::assertStringEndsWith('overrides this setting.', $this->reporting(true)->statusText(true, $messages));
    }
}
