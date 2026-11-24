<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Translation;

use Atro\Core\Container;
use Atro\Services\SoftwarePackage;

/**
 * Builds Reporting from what AtroCore knows: the site URL (Administration → Settings → Site URL),
 * the module's version (Composer, as Administration → Updates & Modules shows it), AtroPIM's or
 * AtroCore's version, and AtroCore's config file as the place for the reporter's state (key
 * `supertextReporter`, never sent to the browser: Settings only exposes its own fields).
 *
 * The reporter runs on back-end requests that run the module: reading or saving a Supertext
 * connection, "Test connection", translations (the action, mass actions, background jobs, the
 * console) and `supertext check`. Translations schedule it once per process, after the work
 * (shutdown function), so it never delays or fails a translation.
 */
final class ReportingFactory
{
    private static bool $scheduled = false;
    private static ?int $authenticationFailed = null;

    public static function create(Container $container, Settings $settings, ?callable $languages = null): Reporting
    {
        $config = $container->get('config');
        $system = self::system($container);

        return Reporting::create(
            $settings->versionReports(),
            self::siteUrl($container),
            self::moduleVersion($container),
            $system['systemName'],
            $system['systemVersion'],
            apiBaseUrl: $settings->baseUrl(),
            load: static fn (): mixed => $config->get(Reporting::STATE_KEY),
            save: static function (string $state) use ($config): void {
                if ($config->get(Reporting::STATE_KEY) === $state) {
                    return;
                }
                $config->set(Reporting::STATE_KEY, $state);
                $config->save();
            },
            languages: $languages,
        );
    }

    /**
     * "Send configuration to Supertext" (action type supertextSendConfiguration, `supertext send-configuration`):
     * the installed AtroCore modules and every language with its Supertext code, in one report.
     *
     * @return array{result: ?\SupertextTranslation\PluginHealth\ReporterResult, enabled: bool, extensions: int, languages: int}
     */
    public static function sendConfiguration(Container $container, Settings $settings): array
    {
        $languages = [];

        foreach (ConnectionStatus::languages($container)[0] as $language) {
            $languages[] = ['code' => $language, 'supertext' => $settings->languageCodeOrNull($language)];
        }

        $extensions = self::modules($container);
        $reporting  = self::create($container, $settings, static fn (): array => $languages);

        return [
            'result'     => $reporting->isEnabled() ? $reporting->sendConfiguration($extensions) : null,
            'enabled'    => $reporting->isEnabled(),
            'extensions' => \count($extensions),
            'languages'  => \count($languages),
        ];
    }

    /** @return list<array{name: string, version: ?string, active: bool, type: string}> the installed modules (Administration → Updates & Modules) */
    public static function modules(Container $container): array
    {
        $out = [];

        try {
            foreach ($container->get('moduleManager')->getModules() as $id => $module) {
                $version = (string) $module->getVersion();
                $name    = (string) $module->getComposerName();
                $out[]   = ['name' => $name !== '' && $name !== '-' ? $name : (string) $id, 'version' => $version !== '' ? ltrim($version, 'v') : null, 'active' => true, 'type' => 'module'];
            }
        } catch (\Throwable) {
            // A report without modules is still useful (languages).
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** AtroCore's site URL; without one (rare) the request's own host. */
    public static function siteUrl(Container $container): string
    {
        $url = trim((string) $container->get('config')->get('siteUrl', ''));

        if ($url === '' && !empty($_SERVER['HTTP_HOST']) && \is_string($_SERVER['HTTP_HOST'])) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            $url   = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
        }

        return rtrim($url, '/');
    }

    /** The module's version from Composer (the official version source: the Git tag). */
    public static function moduleVersion(Container $container): string
    {
        try {
            $module = $container->get('moduleManager')->getModule('SupertextTranslation');

            return $module !== null ? (string) $module->getVersion() : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * AtroPIM and its version when AtroPIM is installed, else AtroCore and its version.
     *
     * @return array{systemName: string, systemVersion: ?string}
     */
    public static function system(Container $container): array
    {
        try {
            $pim = $container->get('moduleManager')->getModule('Pim');

            if ($pim !== null) {
                return ['systemName' => 'AtroPIM', 'systemVersion' => (string) $pim->getVersion()];
            }
        } catch (\Throwable) {
            // Fall through to AtroCore.
        }

        try {
            $version = SoftwarePackage::getCoreVersion();
        } catch (\Throwable) {
            $version = null;
        }

        return ['systemName' => 'AtroCore', 'systemVersion' => $version];
    }

    /**
     * After a translation ran (or failed): onRequest, or authenticationFailed when Supertext answered
     * 401/403 (see markAuthenticationFailed()). Once per process, at its end.
     */
    public static function afterTranslation(Container $container, Settings $settings): void
    {
        if (self::$scheduled) {
            return;
        }

        self::$scheduled = true;

        self::defer(static function () use ($container, $settings): void {
            $reporting = self::create($container, $settings);
            $status    = self::$authenticationFailed;
            $status !== null
                ? $reporting->authenticationFailed($settings->apiKey(), $status)
                : $reporting->onRequest($settings->apiKey());
        });
    }

    public static function markAuthenticationFailed(int $httpStatus): void
    {
        self::$authenticationFailed = $httpStatus;
    }

    /** Back-end request that shows a Supertext connection: onRequest after the response (see defer()). */
    public static function onRequestLater(Container $container, Settings $settings): void
    {
        self::defer(static function () use ($container, $settings): void {
            self::create($container, $settings)->onRequest($settings->apiKey());
        });
    }

    /** Runs $work at the end of the process, after the response where PHP allows it; never throws. */
    public static function defer(\Closure $work): void
    {
        register_shutdown_function(static function () use ($work): void {
            try {
                self::finishResponse();
                $work();
            } catch (\Throwable) {
                // Reporting never fails anything.
            }
        });
    }

    /** Sends the response before the reporter runs where PHP allows it (PHP-FPM); else it runs right after. */
    private static function finishResponse(): void
    {
        if (\PHP_SAPI === 'cli') {
            return;
        }

        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();

            return;
        }

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        flush();
    }
}
