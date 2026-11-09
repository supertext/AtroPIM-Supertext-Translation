<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Translation;

use Atro\Core\Container;
use Atro\Core\Exceptions\NotModified;
use Espo\ORM\Entity;

/**
 * Records for translation files, through the entity's record service like EntityTranslator: the
 * signed-in user's read permission when loading, edit permission, validation, hooks and history
 * when saving.
 */
final class AtroRecordSource implements RecordSource
{
    public function __construct(private readonly Container $container)
    {
    }

    public function mainLanguage(): string
    {
        return (string) $this->container->get('config')->get('mainLanguage', 'en_US');
    }

    public function languages(): array
    {
        $config = $this->container->get('config');

        return array_values(array_unique(array_filter(array_merge([$this->mainLanguage()], array_map('strval', (array) $config->get('inputLanguageList', []))))));
    }

    public function record(string $entityType, string $id): ?array
    {
        try {
            if (!$this->container->get('metadata')->get(['scopes', $entityType, 'entity'])) {
                return null;
            }

            $entity = $this->container->get('serviceFactory')->create($entityType)->getEntity($id);
        } catch (\Throwable) {
            // Not found, or no read permission (AtroCore throws Forbidden/NotFound).
            return null;
        }

        if (!$entity instanceof Entity) {
            return null;
        }

        $defs = $this->container->get('metadata')->get(['entityDefs', $entityType, 'fields'], []);

        // Attribute values are only in the loaded entity's own definitions.
        if (\is_array($entity->entityDefs['fields'] ?? null)) {
            $defs = array_merge(\is_array($defs) ? $defs : [], $entity->entityDefs['fields']);
        }

        $units  = Planner::units(\is_array($defs) ? $defs : [], $this->mainLanguage());
        $values = [];

        foreach ($units as $unit) {
            foreach ($unit->fieldsByLanguage as $field) {
                $values[$field] = $entity->get($field);
            }
        }

        try {
            $editable = (bool) $this->container->get('acl')->check($entity, 'edit');
        } catch (\Throwable) {
            $editable = false;
        }

        return ['units' => $units, 'values' => $values, 'editable' => $editable];
    }

    public function save(string $entityType, string $id, array $data): void
    {
        try {
            $this->container->get('serviceFactory')->create($entityType)->updateEntity($id, (object) $data);
        } catch (NotModified) {
            // The record already holds exactly these texts.
        }
    }
}
