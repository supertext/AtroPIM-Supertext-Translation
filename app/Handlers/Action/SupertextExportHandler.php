<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Handlers\Action;

use Atro\Core\Routing\Route;
use Atro\Handlers\Action\AbstractActionTypeSyncHandler;

#[Route(
    path: '/Action/{id}/supertextExport',
    methods: ['POST'],
    summary: 'Execute an Export for translation action',
    description: 'Checks the record and returns the download link (GET /supertext/export) as link.',
    tag: 'Action',
    parameters: [
        ['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Action record ID.', 'schema' => ['type' => 'string']],
    ],
    requestBody: [
        'required' => false,
        'content'  => [
            'application/json' => [
                'schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'entityId' => ['type' => 'string', 'description' => 'ID of the record.'],
                    ],
                ],
            ],
        ],
    ],
    responses: [
        200 => ['description' => 'Execution result.', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['success' => ['type' => 'boolean'], 'message' => ['type' => 'string', 'nullable' => true], 'link' => ['type' => 'string', 'description' => 'URL the front end opens in a new tab.']]]]]],
        404 => ['description' => 'Action record not found.'],
    ],
)]
class SupertextExportHandler extends AbstractActionTypeSyncHandler
{
}
