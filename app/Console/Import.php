<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Console;

use Atro\Console\AbstractConsole;
use SupertextTranslation\Api\SupertextException;
use SupertextTranslation\Translation\AtroRecordSource;
use SupertextTranslation\Translation\CommandArguments;
use SupertextTranslation\Translation\ConnectionResolver;
use SupertextTranslation\Translation\Messages;
use SupertextTranslation\Translation\TranslationFileExchange;

/**
 * php console.php "supertext import <file> [--include-changed] [--overwrite]"
 */
class Import extends AbstractConsole
{
    public static function getDescription(): string
    {
        return 'Supertext Translation: import a translated XLIFF or HTML file, e.g. "supertext import product.xlf --include-changed".';
    }

    public function run(array $data): void
    {
        $options   = CommandArguments::parse((string) ($data['args'] ?? ''));
        $path      = $options['entityType'];
        $container = $this->getContainer();
        Export::runAsSystemUser($container);
        $messages = new Messages();

        if ($path === '') {
            self::show('Usage: php console.php "supertext import <file> [--include-changed] [--overwrite]"', self::ERROR, true);
        }

        if (!is_file($path) || !is_readable($path)) {
            self::show("Could not read $path.", self::ERROR, true);
        }

        if ((int) filesize($path) > TranslationFileExchange::MAX_UPLOAD_BYTES) {
            self::show($messages->text('file_too_large'), self::ERROR, true);
        }

        try {
            $exchange = new TranslationFileExchange(new AtroRecordSource($container), (new ConnectionResolver($container))->settings(null));
            $jobs     = $exchange->import((string) file_get_contents($path), $options['includeChanged'], $options['overwrite']);
        } catch (SupertextException $e) {
            self::show($messages->exception($e), self::ERROR, true);

            return;
        }

        $failed = array_filter($jobs, static fn (array $job): bool => $job['error'] !== null);
        self::show(TranslationFileExchange::summaryText(TranslationFileExchange::summary($jobs, $messages)), $failed === [] ? self::SUCCESS : self::ERROR, $failed !== []);
    }
}
