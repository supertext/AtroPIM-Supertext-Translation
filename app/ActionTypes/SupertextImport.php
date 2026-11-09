<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\ActionTypes;

use Atro\ActionTypes\AbstractAction;
use Atro\Entities\ActionExecution;
use Espo\ORM\Entity;
use SupertextTranslation\Translation\Messages;

/**
 * Action type "Import translation": a button that opens the upload page
 * (GET /api/supertext/import) in a new tab, through the result's `link`
 * (ActionService::afterExecuteNow). The file itself says which records it belongs to.
 */
class SupertextImport extends AbstractAction
{
    public const TYPE = 'supertextImport';

    public static function getTypeLabel(): ?string
    {
        return 'Import translation';
    }

    public static function getName(): ?string
    {
        return 'Import translation';
    }

    public static function getDescription(): ?string
    {
        return 'Opens the page to upload a translated XLIFF or HTML file exported with Export for translation.';
    }

    public function useMassActions(Entity $action, \stdClass $input): bool
    {
        return false;
    }

    public function execute(ActionExecution $execution, \stdClass $input): bool
    {
        $message = Messages::forUser($this->container)->text('file_import_opens');
        $this->getMemoryStorage()->set(SupertextExport::RESULT_KEY, ['link' => 'api/supertext/import', 'message' => $message]);

        $execution->set('status', 'done');
        $execution->set('statusMessage', $message);
        $this->getEntityManager()->saveEntity($execution);

        return true;
    }
}
