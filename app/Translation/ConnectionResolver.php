<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Translation;

use Atro\Core\Container;
use Espo\ORM\Entity;
use SupertextTranslation\Api\SupertextException;

/**
 * Finds the Supertext connection (Administration → Connections, type "Supertext") and turns it
 * into Settings. Without any Supertext connection the SUPERTEXT_API_KEY environment variable
 * is used with the default settings.
 */
final class ConnectionResolver
{
    public const TYPE = 'supertext';

    public function __construct(private readonly Container $container)
    {
    }

    /** @param string|null $connectionId empty: the first Supertext connection */
    public function settings(?string $connectionId): Settings
    {
        $connection = $this->connection($connectionId);

        return $connection === null ? new Settings('') : self::fromEntity($connection, $this->container);
    }

    public function connection(?string $connectionId): ?Entity
    {
        $repository = $this->container->get('entityManager')->getRepository('Connection');

        if ($connectionId !== null && $connectionId !== '') {
            $connection = $repository->get($connectionId);

            if (!$connection instanceof Entity || $connection->get('type') !== self::TYPE) {
                throw new SupertextException(sprintf('The Supertext connection %s was not found.', $connectionId), key: 'connection_not_found', params: ['id' => $connectionId]);
            }

            return $connection;
        }

        $connection = $repository->where(['type' => self::TYPE])->order('createdAt', 'ASC')->findOne();

        return $connection instanceof Entity ? $connection : null;
    }

    public static function fromEntity(Entity $connection, Container $container): Settings
    {
        $data = $connection->get('data');
        $data = \is_object($data) || \is_array($data) ? json_decode((string) json_encode($data), true) : [];
        $key  = (string) ($data['supertextApiKey'] ?? '');

        if ($key !== '') {
            $decrypted = $container->get('serviceFactory')->create('Connection')->decryptPassword($key);
            $key       = \is_string($decrypted) ? $decrypted : '';
        }

        return Settings::fromConnectionData(\is_array($data) ? $data : [], $key, ConnectionStatus::languages($container)[0]);
    }
}
