<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Console;

use Atro\Console\AbstractConsole;
use SupertextTranslation\Translation\ConnectionResolver;
use SupertextTranslation\Translation\Messages;
use SupertextTranslation\Translation\ReportingFactory;

/**
 * php console.php supertext send-configuration: what the action "Send configuration to Supertext"
 * does, from the console: the installed modules and the language mapping in one report.
 */
class SendConfiguration extends AbstractConsole
{
    public static function getDescription(): string
    {
        return 'Supertext Translation: send Supertext the installed modules and the language mapping (for support).';
    }

    public function run(array $data): void
    {
        $container = $this->getContainer();
        $messages  = new Messages();
        $sent      = ReportingFactory::sendConfiguration($container, (new ConnectionResolver($container))->settings(null));

        if (!$sent['enabled']) {
            self::show($messages->text('config_off'), self::ERROR, true);
        }

        if (!($sent['result']?->sent ?? false)) {
            self::show($messages->text('config_failed'), self::ERROR, true);
        }

        self::show($messages->text('config_sent', ['extensions' => (string) $sent['extensions'], 'languages' => (string) $sent['languages']]), self::SUCCESS);
    }
}
