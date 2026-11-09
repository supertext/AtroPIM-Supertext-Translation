<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Listeners;

use Atro\Core\EventManager\Event;
use Atro\Listeners\AbstractListener;
use SupertextTranslation\ActionTypes\SupertextExport;
use SupertextTranslation\ActionTypes\SupertextImport;
use SupertextTranslation\ActionTypes\SupertextTranslate;
use SupertextTranslation\Translation\EntityTranslator;
use SupertextTranslation\Translation\Messages;

/**
 * Shows what was translated ("de_CH: translated (3 fields)") instead of the generic "Action executed";
 * for "Export for translation" and "Import translation" adds the `link` AtroCore's front end opens
 * in a new tab (the file download, the upload page).
 */
class ActionService extends AbstractListener
{
    public function afterExecuteNow(Event $event): void
    {
        $action = $event->getArgument('action');

        if ($action !== null && \in_array($action->get('type'), [SupertextExport::TYPE, SupertextImport::TYPE], true)) {
            $file   = $this->getContainer()->get('memoryStorage')->get(SupertextExport::RESULT_KEY);
            $result = $event->getArgument('result');

            if (!empty($result['success']) && \is_array($file)) {
                $result['link']    = $file['link'];
                $result['message'] = $file['message'];
                $event->setArgument('result', $result);
            }

            return;
        }

        if ($action === null || $action->get('type') !== SupertextTranslate::TYPE) {
            return;
        }

        $runs   = $this->getContainer()->get('memoryStorage')->get(SupertextTranslate::SUMMARY_KEY);
        $result = $event->getArgument('result');

        if (!empty($result['success']) && \is_array($runs) && $runs !== []) {
            $result['message'] = EntityTranslator::runsMessage($runs, Messages::forUser($this->getContainer()));
            $event->setArgument('result', $result);
        }
    }
}
