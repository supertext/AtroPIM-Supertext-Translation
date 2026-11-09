<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\ActionTypes;

use Atro\ActionTypes\AbstractAction;
use Atro\Core\Exceptions\BadRequest;
use Atro\Entities\ActionExecution;
use Espo\ORM\Entity;
use SupertextTranslation\Api\SupertextException;
use SupertextTranslation\Translation\AtroRecordSource;
use SupertextTranslation\Translation\ConnectionResolver;
use SupertextTranslation\Translation\Messages;
use SupertextTranslation\Translation\TranslationFileExchange;

/**
 * Action type "Export for translation": a button on the record that downloads its multilingual
 * texts as an XLIFF 1.2 or HTML file. execute() builds the file once to report "Nothing to
 * translate" and similar problems as the action's error; the download itself is
 * GET /api/supertext/export, which AtroCore's front end opens because the result carries it as
 * `link` (ActionService::afterExecuteNow).
 */
class SupertextExport extends AbstractAction
{
    public const TYPE = 'supertextExport';

    /** memoryStorage key: ['link' => …, 'message' => …] for the action's result. */
    public const RESULT_KEY = 'supertextFileResult';

    public static function getTypeLabel(): ?string
    {
        return 'Export for translation';
    }

    public static function getName(): ?string
    {
        return 'Export for translation';
    }

    public static function getDescription(): ?string
    {
        return 'Downloads the multilingual text fields of the record as an XLIFF 1.2 or HTML file to translate outside the Supertext API.';
    }

    public function useMassActions(Entity $action, \stdClass $input): bool
    {
        return false;
    }

    public function execute(ActionExecution $execution, \stdClass $input): bool
    {
        /** @var Entity $action */
        $action   = $execution->get('action');
        $record   = $this->getSourceEntity($action, $input);
        $messages = Messages::forUser($this->container);

        if (!$record instanceof Entity) {
            throw new BadRequest($messages->text('needs_record'));
        }

        $connectionId = (string) ($action->get('supertextConnectionId') ?? '');
        $query        = [
            'entity'     => $record->getEntityType(),
            'id'         => (string) $record->get('id'),
            'source'     => (string) $action->get('supertextSourceLanguage'),
            'targets'    => implode(',', SupertextTranslate::languages($action->get('supertextTargetLanguages'))),
            'format'     => (string) $action->get('supertextFileFormat') === TranslationFileExchange::HTML ? TranslationFileExchange::HTML : TranslationFileExchange::XLIFF,
            'overwrite'  => $action->get('supertextOverwrite') ? '1' : '0',
            'connection' => $connectionId,
        ];

        try {
            $exchange = new TranslationFileExchange(new AtroRecordSource($this->container), (new ConnectionResolver($this->container))->settings($connectionId ?: null));
            $file     = $exchange->export($query['entity'], $query['id'], $query['source'], SupertextTranslate::languages($query['targets']), $query['format'], $query['overwrite'] === '1');
        } catch (SupertextException $e) {
            throw new BadRequest($messages->exception($e));
        }

        $message = $messages->text('file_download', ['file' => $file['fileName']]);
        $this->getMemoryStorage()->set(self::RESULT_KEY, ['link' => 'api/supertext/export?' . http_build_query(array_filter($query, static fn (string $v): bool => $v !== '')), 'message' => $message]);

        $execution->set('status', 'done');
        $execution->set('statusMessage', $message);
        $this->getEntityManager()->saveEntity($execution);

        return true;
    }
}
