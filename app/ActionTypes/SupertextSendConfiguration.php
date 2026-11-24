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
use SupertextTranslation\Translation\ConnectionResolver;
use SupertextTranslation\Translation\Messages;
use SupertextTranslation\Translation\ReportingFactory;

/**
 * Action type "Send configuration to Supertext": a button (best on the Supertext connection,
 * Source entity *Connection*) that sends Supertext the installed modules and the language mapping
 * in one `extensions` report (ReportingFactory::sendConfiguration()). Nothing is sent while version
 * reports are off. The result is shown instead of "Action executed" (ActionService::afterExecuteNow).
 */
class SupertextSendConfiguration extends AbstractAction
{
    public const TYPE = 'supertextSendConfiguration';

    /** memoryStorage key of the result message for ActionService. */
    public const RESULT_KEY = 'supertextSendConfigurationResult';

    public static function getTypeLabel(): ?string
    {
        return 'Send configuration to Supertext';
    }

    public static function getName(): ?string
    {
        return 'Send configuration to Supertext';
    }

    public static function getDescription(): ?string
    {
        return 'Sends Supertext the installed modules and the Supertext language of each language, so Supertext support can help better.';
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
        $resolver = new ConnectionResolver($this->container);
        $settings = $record instanceof Entity && $record->getEntityType() === 'Connection' && $record->get('type') === ConnectionResolver::TYPE
            ? ConnectionResolver::fromEntity($record, $this->container)
            : $resolver->settings(null);

        $sent    = ReportingFactory::sendConfiguration($this->container, $settings);
        $message = match (true) {
            !$sent['enabled']               => $messages->text('config_off'),
            (bool) ($sent['result']?->sent) => $messages->text('config_sent', ['extensions' => (string) $sent['extensions'], 'languages' => (string) $sent['languages']]),
            default                         => $messages->text('config_failed'),
        };
        $this->getMemoryStorage()->set(self::RESULT_KEY, $message);

        $execution->set('status', 'done');
        $execution->set('statusMessage', $message);
        $this->getEntityManager()->saveEntity($execution);

        return true;
    }
}
