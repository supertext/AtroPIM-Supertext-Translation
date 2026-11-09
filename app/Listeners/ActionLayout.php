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

/** Adds the Supertext options to the Action form; they are only visible for the Supertext action types. */
class ActionLayout extends AbstractLayoutListener
{
    public const ROWS = [
        [['name' => 'supertextConnection'], ['name' => 'supertextPoliteness']],
        [['name' => 'supertextSourceLanguage'], ['name' => 'supertextTargetLanguages']],
        [['name' => 'supertextOverwrite'], false],
    ];

    /** "Export for translation" only; added on its own so layouts customised before it get it too. */
    public const FILE_ROWS = [
        [['name' => 'supertextFileFormat'], false],
    ];

    public function detail(Event $event): void
    {
        if ($this->isRelatedLayout($event)) {
            return;
        }

        $layout = self::addRows($event->getArgument('result'), self::ROWS, 'supertextConnection');
        $event->setArgument('result', self::addRows($layout, self::FILE_ROWS, 'supertextFileFormat'));
    }

    /**
     * Appends the rows to the first panel unless the layout already has the marker field
     * (an administrator may have placed the fields in a custom layout).
     *
     * @param list<list<array<string, string|bool>|false>> $rows
     */
    public static function addRows(mixed $layout, array $rows, string $marker): mixed
    {
        if (!\is_array($layout) || !isset($layout[0]['rows']) || str_contains((string) json_encode($layout), '"name":"' . $marker . '"')) {
            return $layout;
        }

        foreach ($rows as $row) {
            $layout[0]['rows'][] = $row;
        }

        return $layout;
    }
}
