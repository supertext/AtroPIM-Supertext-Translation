<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation;

use Atro\Core\ModuleManager\AbstractModule;
use SupertextTranslation\Console\Check;
use SupertextTranslation\Console\Export;
use SupertextTranslation\Console\Import;
use SupertextTranslation\Console\Translate;

/**
 * The AtroCore module. AtroCore finds it through composer.json → extra.atroId and loads the
 * metadata, translations, listeners, handlers and action/connection types below app/.
 */
class Module extends AbstractModule
{
    public static function getLoadOrder(): int
    {
        // After AtroCore (and AtroPIM, 5120), so our metadata additions apply to theirs.
        return 9300;
    }

    public function getConsoleCommands(): array
    {
        return [
            'supertext check'            => Check::class,
            'supertext translate <args>' => Translate::class,
            'supertext export <args>'    => Export::class,
            'supertext import <args>'    => Import::class,
        ];
    }
}
