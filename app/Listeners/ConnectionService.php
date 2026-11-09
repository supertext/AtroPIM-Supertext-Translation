<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Listeners;

use Atro\Core\EventManager\Event;
use Atro\Core\Exceptions\BadRequest;
use Atro\Listeners\AbstractListener;
use Espo\ORM\Entity;
use SupertextTranslation\Api\SupertextClient;
use SupertextTranslation\Translation\ConnectionResolver;
use SupertextTranslation\Translation\ConnectionStatus;
use SupertextTranslation\Translation\LanguageMapping;
use SupertextTranslation\Translation\Messages;
use SupertextTranslation\Translation\ReportingFactory;

/**
 * The Supertext connection is the module's settings page:
 *
 * - shown (afterReadEntity): lines of the old text mapping are moved into the language combo boxes
 *   (saved once), each combo box without a value shows its suggestion (Supertext's code for the
 *   language, see LanguageMapping), the read-only fields "API key check" and "Languages" in the
 *   viewer's language, then (after the response) the reporter's onRequest: checks a changed key,
 *   reports an update;
 * - saved (afterCreateEntity, afterUpdateEntity): the old text mapping is moved as above, the
 *   automatic API key check and the `installed`/`settings_saved` report, then both fields are
 *   stored so the form shows the result.
 *
 * Nothing here may fail a read or a save (except a save with a language code Supertext doesn't offer,
 * which is refused with a message): every other step is wrapped.
 */
class ConnectionService extends AbstractListener
{
    public function afterReadEntity(Event $event): void
    {
        $entity = $event->getArgument('entity');

        if (!self::isSupertext($entity)) {
            return;
        }

        try {
            $this->migrateLanguageMapping($entity);
            self::preselectLanguages($entity, ConnectionStatus::languages($this->getContainer())[0]);
        } catch (\Throwable) {
            // The connection is shown with the combo boxes as saved.
        }

        try {
            $settings = ConnectionResolver::fromEntity($entity, $this->getContainer());
            ConnectionStatus::fill($this->getContainer(), $entity, $settings, ReportingFactory::create($this->getContainer(), $settings));
            ReportingFactory::onRequestLater($this->getContainer(), $settings);
        } catch (\Throwable) {
            // The connection is shown without the status fields.
        }
    }

    public function beforeCreateEntity(Event $event): void
    {
        self::dropReadOnlyFields($event->getArgument('attachment'));
        $this->checkLanguageCodes($event->getArgument('attachment'));
    }

    public function beforeUpdateEntity(Event $event): void
    {
        self::dropReadOnlyFields($event->getArgument('data'));
        $this->checkLanguageCodes($event->getArgument('data'));
    }

    /** Only Supertext's codes (or none) may be saved in the language combo boxes. */
    private function checkLanguageCodes(mixed $data): void
    {
        if (!$data instanceof \stdClass) {
            return;
        }

        $invalid = LanguageMapping::invalidValue((array) $data);

        if ($invalid !== null) {
            throw new BadRequest(Messages::forUser($this->getContainer())->text('invalid_language_code', ['code' => $invalid]));
        }
    }

    public function afterCreateEntity(Event $event): void
    {
        $this->afterSave($event->getArgument('entity'));
    }

    public function afterUpdateEntity(Event $event): void
    {
        $this->afterSave($event->getArgument('entity'));
    }

    private function afterSave(mixed $entity): void
    {
        if (!self::isSupertext($entity)) {
            return;
        }

        try {
            $this->migrateLanguageMapping($entity);
        } catch (\Throwable) {
            // The old lines keep working (Settings reads them); the next view moves them.
        }

        try {
            $settings  = ConnectionResolver::fromEntity($entity, $this->getContainer());
            $reporting = ReportingFactory::create($this->getContainer(), $settings);
            $reporting->afterSettingsSaved($settings->apiKey());
            ConnectionStatus::store($this->getContainer(), $entity, $settings, $reporting);
        } catch (\Throwable) {
            // Saving the connection never fails because of the key check or the report.
        }
    }

    /** Moves the lines of the old text mapping into the combo boxes and saves them (no hooks, no report). */
    private function migrateLanguageMapping(Entity $entity): void
    {
        $data = self::data($entity);
        $new  = LanguageMapping::migrate($data, ConnectionStatus::languages($this->getContainer())[0]);

        if ($new === null) {
            return;
        }

        $entity->set('data', $new);

        // The form and the REST answer show the fields' own values: set them too.
        if (method_exists($entity, 'setDataField')) {
            foreach ([LanguageMapping::LEGACY_FIELD, ...array_map(LanguageMapping::field(...), ConnectionStatus::languages($this->getContainer())[0])] as $field) {
                if (\array_key_exists($field, $new)) {
                    $entity->setDataField($field, $new[$field]);
                }
            }
        }

        $this->getContainer()->get('entityManager')->saveEntity($entity, ['skipAll' => true]);
    }

    /**
     * Each combo box without a value shows its suggestion (not saved: a language without a value is
     * translated with the same code anyway), and a review service URL never saved shows the default.
     *
     * @param list<string> $languages
     */
    private static function preselectLanguages(Entity $entity, array $languages): void
    {
        $data = self::data($entity);

        foreach ($languages as $language) {
            $field = LanguageMapping::field($language);

            if ((string) ($data[$field] ?? '') === '' && method_exists($entity, 'setDataField')) {
                $entity->setDataField($field, LanguageMapping::suggestion($language));
            }
        }

        // Not saved yet: Supertext's review service (as Settings uses it).
        if (($data[LanguageMapping::REVIEW_URL_FIELD] ?? null) === null && method_exists($entity, 'setDataField')) {
            $entity->setDataField(LanguageMapping::REVIEW_URL_FIELD, SupertextClient::REVIEW_URL);
        }
    }

    /** @return array<string, mixed> */
    private static function data(Entity $entity): array
    {
        $data = $entity->get('data');
        $data = \is_object($data) || \is_array($data) ? json_decode((string) json_encode($data), true) : [];

        return \is_array($data) ? $data : [];
    }

    private static function isSupertext(mixed $entity): bool
    {
        return $entity instanceof Entity && $entity->get('type') === ConnectionResolver::TYPE;
    }

    /** The status fields are written by the module only. */
    private static function dropReadOnlyFields(mixed $data): void
    {
        if ($data instanceof \stdClass) {
            unset($data->{ConnectionStatus::KEY_CHECK_FIELD}, $data->{ConnectionStatus::LANGUAGES_FIELD});
        }
    }
}
