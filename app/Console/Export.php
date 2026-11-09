<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Console;

use Atro\Console\AbstractConsole;
use Atro\Core\UserContext;
use SupertextTranslation\Api\SupertextException;
use SupertextTranslation\Translation\AtroRecordSource;
use SupertextTranslation\Translation\CommandArguments;
use SupertextTranslation\Translation\ConnectionResolver;
use SupertextTranslation\Translation\Messages;
use SupertextTranslation\Translation\TranslationFileExchange;

/**
 * php console.php "supertext export Product <id> [de_CH,fr_CH] [--format=xliff|html] [--source=en_US] [--overwrite] [--output=<file>]"
 */
class Export extends AbstractConsole
{
    public static function getDescription(): string
    {
        return 'Supertext Translation: export one record for translation, e.g. "supertext export Product <id> de_CH,fr_CH --format=xliff --output=product.xlf".';
    }

    public function run(array $data): void
    {
        $options   = CommandArguments::parse((string) ($data['args'] ?? ''));
        $container = $this->getContainer();
        self::runAsSystemUser($container);

        if ($options['entityType'] === '' || $options['id'] === '') {
            self::show('Usage: php console.php "supertext export <Entity> <id> [languages] [--format=xliff|html] [--source=<language>] [--overwrite] [--output=<file>]"', self::ERROR, true);
        }

        try {
            $exchange = new TranslationFileExchange(new AtroRecordSource($container), (new ConnectionResolver($container))->settings(null));
            $file     = $exchange->export($options['entityType'], $options['id'], $options['source'], $options['targets'], $options['format'], $options['overwrite']);
        } catch (SupertextException $e) {
            self::show((new Messages())->exception($e), self::ERROR, true);

            return;
        }

        $path = $options['output'] !== '' ? $options['output'] : $file['fileName'];

        if (@file_put_contents($path, $file['content']) === false) {
            self::show("Could not write $path.", self::ERROR, true);
        }

        self::show(sprintf('%s: %s (%s)', $path, implode(', ', $file['targets']), $options['format'] === TranslationFileExchange::HTML ? 'HTML' : 'XLIFF 1.2'), self::SUCCESS);
    }

    /** The console has no signed-in user: run as AtroCore's system user, like jobs do. */
    public static function runAsSystemUser(\Atro\Core\Container $container): void
    {
        $entityManager = $container->get('entityManager');
        $systemUser    = $entityManager->getRepository('User')->getGlobalSystemUser();
        $entityManager->setUser($systemUser);
        $container->get(UserContext::class)->set($systemUser);
    }
}
