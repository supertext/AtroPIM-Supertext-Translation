<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Listeners;

use Atro\Core\EventManager\Event;
use Atro\Listeners\AbstractLayoutListener;
use SupertextTranslation\Translation\ConnectionStatus;
use SupertextTranslation\Translation\LanguageMapping;

/**
 * Adds the Supertext fields to the Connection form; they are only visible for type "Supertext".
 * The language mapping is one row per AtroCore language: its Supertext language and its
 * *Translation review* (see LanguageMapping).
 */
class ConnectionLayout extends AbstractLayoutListener
{
    public const ROWS_BEFORE_LANGUAGES = [
        [['name' => 'supertextApiKey'], ['name' => 'supertextEnvironment']],
        [['name' => 'supertextApiUrl'], ['name' => 'supertextTimeout']],
    ];

    public const ROWS_AFTER_LANGUAGES = [
        [['name' => 'supertextReviewUrl'], false],
        [['name' => 'supertextVersionReports'], false],
        [['name' => 'supertextKeyCheck', 'fullWidth' => true]],
        [['name' => 'supertextLanguageStatus', 'fullWidth' => true]],
    ];

    public function detail(Event $event): void
    {
        if ($this->isRelatedLayout($event)) {
            return;
        }

        try {
            [$languages] = ConnectionStatus::languages($this->getContainer());
        } catch (\Throwable) {
            $languages = [];
        }

        $event->setArgument('result', ActionLayout::addRows($event->getArgument('result'), self::rows($languages), 'supertextApiKey'));
    }

    /**
     * @param list<string> $languages
     *
     * @return list<list<array<string, mixed>|false>>
     */
    public static function rows(array $languages): array
    {
        return array_merge(self::ROWS_BEFORE_LANGUAGES, LanguageMapping::rows($languages), self::ROWS_AFTER_LANGUAGES);
    }
}
