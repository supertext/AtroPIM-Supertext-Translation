<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Translation;

use SupertextTranslation\PluginHealth\CallableKeyChecker;
use SupertextTranslation\PluginHealth\InstallationReporter;
use SupertextTranslation\PluginHealth\KeyCheck;
use SupertextTranslation\PluginHealth\PingResponse;
use SupertextTranslation\PluginHealth\ReporterResult;
use SupertextTranslation\PluginHealth\ReporterState;
use SupertextTranslation\Sdk\Http\HttpTransport;
use SupertextTranslation\Sdk\Supertext;
use SupertextTranslation\Sdk\V1\Client;
use SupertextTranslation\Sdk\V1\Protocol;

/**
 * Version reports to the Supertext Commando Center, the automatic API key check and the callback
 * ping (Commando-Center-Supertext-Translation → docs/DEVELOPER.md → Report protocol). The protocol
 * is Supertext Plugin Health's (InstallationReporter, bundled in lib/supertext-plugin-health), with
 * the Supertext SDK's checkApiKey() as its key check; this class gives it what the module knows and
 * keeps the reporter's JSON state through two closures (ReportingFactory stores it in AtroCore's config).
 *
 * Nothing here ever throws: a broken state store or an unreachable center must never fail a save,
 * a "Test connection" or a translation. No AtroCore dependencies (unit-tested).
 */
final class Reporting
{
    /** The GitHub repository name: the center reads the latest release from github.com/Supertext/<name>. */
    public const PLUGIN_NAME = 'AtroPIM-Supertext-Translation';

    /** The ping route below the site URL (app/Handlers/Supertext/PingHandler.php: /api + /supertext/ping). */
    public const PING_PATH = 'api/supertext/ping';

    /** The AtroCore config key that holds the reporter's state (JSON). */
    public const STATE_KEY = 'supertextReporter';

    /**
     * @param \Closure(): mixed      $load the stored state (JSON string, array or null)
     * @param \Closure(string): void $save stores the state (JSON)
     */
    public function __construct(
        private readonly InstallationReporter $reporter,
        private readonly \Closure $load,
        private readonly \Closure $save,
        private readonly bool $enabled,
        private readonly string $callbackUrl,
    ) {
    }

    /**
     * @param bool|null                   $setting the connection's "Send version information to Supertext" (null: not set, on)
     * @param string                      $siteUrl AtroCore's siteUrl
     * @param string                      $apiBaseUrl the Supertext API the key check calls (the connection's endpoint)
     * @param HttpTransport|null          $transport  tests: the key check's and the report's HTTP (default: curl)
     * @param \Closure(): mixed           $load
     * @param \Closure(string): void      $save
     * @param (callable(float): void)|null $sleep tests
     * @param (callable(): float)|null     $now   tests
     * @param (callable(): list<array{code: string, supertext: ?string}>)|null $languages the language mapping, for sendConfiguration()
     */
    public static function create(
        ?bool $setting,
        string $siteUrl,
        string $pluginVersion,
        string $systemName,
        ?string $systemVersion,
        string $apiBaseUrl,
        \Closure $load,
        \Closure $save,
        ?HttpTransport $transport = null,
        ?string $reportUrl = null,
        ?callable $sleep = null,
        ?callable $now = null,
        ?callable $languages = null,
    ): self {
        $enabled  = InstallationReporter::reportsEnabled($setting);
        $siteUrl  = rtrim(trim($siteUrl), '/');
        $callback = $siteUrl !== '' ? self::callbackUrl($siteUrl) : '';

        $pluginVersion = $pluginVersion !== '' ? $pluginVersion : 'dev';
        $userAgent     = self::PLUGIN_NAME . '/' . $pluginVersion;

        $reporter = new InstallationReporter(
            enabled: $enabled && $siteUrl !== '',
            siteUrl: $siteUrl,
            pluginName: self::PLUGIN_NAME,
            pluginVersion: $pluginVersion,
            systemName: $systemName,
            keyChecker: new CallableKeyChecker(
                static fn (#[\SensitiveParameter] string $key) => (new Client($key, baseUrl: $apiBaseUrl, userAgent: $userAgent, transport: $transport, sleep: $sleep, now: $now))->checkApiKey(),
                [Protocol::class, 'apiKeyFingerprint'],
            ),
            systemVersion: $systemVersion !== '' ? $systemVersion : null,
            sdkVersion: Supertext::SDK_VERSION,
            callbackUrl: $callback !== '' ? $callback : null,
            reportUrl: $reportUrl,
            transport: $transport !== null ? new ReportTransport($transport) : null,
            now: $now,
            languages: $languages,
        );

        return new self($reporter, $load, $save, $enabled, $callback);
    }

    /** Absolute URL of the public ping endpoint. */
    public static function callbackUrl(string $siteUrl): string
    {
        return rtrim(trim($siteUrl), '/') . '/' . self::PING_PATH;
    }

    /** Whether reports are on: the setting, overridden by SUPERTEXT_VERSION_REPORTS. */
    public static function enabled(?bool $setting): bool
    {
        return InstallationReporter::reportsEnabled($setting);
    }

    /** Whether SUPERTEXT_VERSION_REPORTS turns reports off although the setting is on. */
    public static function offByEnvironment(?bool $setting): bool
    {
        return ($setting ?? true) && !InstallationReporter::reportsEnabled($setting);
    }

    /** The answer of GET /api/supertext/ping (status, headers and body exactly as Plugin Health builds them). */
    public static function ping(?string $challenge, ?bool $setting): PingResponse
    {
        return InstallationReporter::pingResponse($challenge, self::PLUGIN_NAME, InstallationReporter::reportsEnabled($setting));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function pingUrl(): string
    {
        return $this->callbackUrl;
    }

    /** After the Supertext connection was saved: checks the key, reports `installed` or `settings_saved`. */
    public function afterSettingsSaved(#[\SensitiveParameter] string $apiKey): ?KeyCheck
    {
        return $this->run(fn (?ReporterState $state): ReporterResult => $this->reporter->afterSettingsSaved($state, $apiKey))?->check;
    }

    /** Back-end requests that run the module (connection page, translations, commands): reports what changed. */
    public function onRequest(#[\SensitiveParameter] string $apiKey): ?ReporterResult
    {
        return $this->run(fn (?ReporterState $state): ReporterResult => $this->reporter->onRequest($state, $apiKey));
    }

    /** A translation got 401/403: the key is invalid (no request to Supertext). */
    public function authenticationFailed(#[\SensitiveParameter] string $apiKey, int $httpStatus): ?ReporterResult
    {
        return $this->run(fn (?ReporterState $state): ReporterResult => $this->reporter->authenticationFailed($state, $apiKey, $httpStatus));
    }

    /** "Test connection": checks the key, reports a changed result. */
    public function testConnection(#[\SensitiveParameter] string $apiKey): ?KeyCheck
    {
        return $this->run(fn (?ReporterState $state): ReporterResult => $this->reporter->testConnection($state, $apiKey))?->check;
    }

    /** `supertext check`: checks the key like "Test connection", then sends a `check` report. */
    public function check(#[\SensitiveParameter] string $apiKey): ?ReporterResult
    {
        $this->testConnection($apiKey);

        return $this->run(fn (?ReporterState $state): ReporterResult => $this->reporter->reportCheck($state));
    }

    /**
     * "Send configuration to Supertext": one `extensions` report with the installed modules and the
     * language mapping, sent now (also within the hour after a failed report). Nothing with reports off.
     *
     * @param list<array{name: string, version?: ?string, active?: ?bool, type?: ?string}> $extensions
     */
    public function sendConfiguration(array $extensions): ?ReporterResult
    {
        return $this->run(fn (?ReporterState $state): ReporterResult => $this->reporter->sendExtensions($state, $extensions));
    }

    /** The latest stored key check, for the connection page. */
    public function latestCheck(): ?KeyCheck
    {
        return $this->state()?->keyCheck;
    }

    /**
     * The key check for people: the center's message for its status, then when it ran.
     */
    public static function checkText(?KeyCheck $check, Messages $messages): string
    {
        if ($check === null) {
            return $messages->text('key_check_none');
        }

        $status = $check->httpStatus !== null ? 'HTTP ' . $check->httpStatus : ($check->errorCode ?? '?');
        $text   = match ($check->status) {
            KeyCheck::OK          => $messages->text('key_check_ok'),
            KeyCheck::INVALID     => $messages->text('key_check_invalid'),
            KeyCheck::MISSING     => $messages->text('key_check_missing'),
            KeyCheck::UNREACHABLE => $messages->text('key_check_unreachable'),
            default                  => $messages->text('key_check_error', ['status' => $status]),
        };
        $time = strtotime($check->checkedAt);

        return $text . ($time !== false ? ' ' . $messages->text('key_check_time', ['time' => gmdate('Y-m-d H:i', $time)]) : '');
    }

    /** The connection page's "API key check" field: the key check and whether version information is sent. */
    public function statusText(?bool $setting, Messages $messages): string
    {
        $lines = [self::checkText($this->latestCheck(), $messages)];

        if (self::offByEnvironment($setting)) {
            $lines[] = $messages->text('reports_off_environment');
        } elseif ($this->enabled && $this->callbackUrl !== '') {
            $lines[] = $messages->text('reports_on', ['url' => $this->callbackUrl]);
        } else {
            $lines[] = $messages->text('reports_off');
        }

        return implode("\n", $lines);
    }

    private function state(): ?ReporterState
    {
        try {
            return ReporterState::from(($this->load)());
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param \Closure(?ReporterState): ReporterResult $action */
    private function run(\Closure $action): ?ReporterResult
    {
        try {
            $result = $action($this->state());
        } catch (\Throwable) {
            return null;
        }

        try {
            ($this->save)((string) json_encode($result->state, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // The state is only a cache of what was reported: losing it means one more report later.
        }

        return $result;
    }
}
