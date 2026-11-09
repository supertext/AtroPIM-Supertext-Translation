<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Handlers\Supertext;

use Atro\Core\Http\Response\ErrorResponse;
use Atro\Core\Routing\Route;
use Atro\Handlers\AbstractHandler;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SupertextTranslation\Api\SupertextException;
use SupertextTranslation\Translation\AtroRecordSource;
use SupertextTranslation\Translation\ConnectionResolver;
use SupertextTranslation\Translation\Messages;
use SupertextTranslation\Translation\TranslationFileExchange;

/**
 * "Export for translation": GET /api/supertext/export?entity=Product&id=…&targets=de_CH,fr_CH&format=xliff
 * downloads the record's multilingual texts as an XLIFF 1.2 or HTML file (the action type
 * supertextExport opens this URL; the browser's AtroCore session cookie authenticates it).
 * Needs read permission on the record; nothing is stored.
 */
#[Route(
    path: '/supertext/export',
    methods: ['GET'],
    summary: 'Export a record for translation',
    description: 'Downloads the multilingual text fields of one record as an XLIFF 1.2 file (one or more target languages) or an HTML file (one target language), to translate outside the API and import with POST /supertext/import.',
    tag: 'Supertext',
    parameters: [
        ['name' => 'entity', 'in' => 'query', 'required' => true, 'description' => 'Entity type, e.g. Product.', 'schema' => ['type' => 'string']],
        ['name' => 'id', 'in' => 'query', 'required' => true, 'description' => 'Record ID.', 'schema' => ['type' => 'string']],
        ['name' => 'targets', 'in' => 'query', 'required' => false, 'description' => 'Target languages, comma-separated (e.g. de_CH,fr_CH). Empty: every other language.', 'schema' => ['type' => 'string']],
        ['name' => 'source', 'in' => 'query', 'required' => false, 'description' => 'Source language. Empty: the main language.', 'schema' => ['type' => 'string']],
        ['name' => 'format', 'in' => 'query', 'required' => false, 'description' => 'xliff (default) or html (exactly one target language).', 'schema' => ['type' => 'string', 'enum' => [TranslationFileExchange::XLIFF, TranslationFileExchange::HTML]]],
        ['name' => 'overwrite', 'in' => 'query', 'required' => false, 'description' => '1: also fields that already have a translation; otherwise only the empty ones.', 'schema' => ['type' => 'string']],
        ['name' => 'connection', 'in' => 'query', 'required' => false, 'description' => 'Supertext connection ID for the language mapping. Empty: the first one.', 'schema' => ['type' => 'string']],
    ],
    responses: [
        200 => [
            'description' => 'The translation file (Content-Disposition: attachment).',
            'content'     => [
                'application/x-xliff+xml' => ['schema' => ['type' => 'string']],
                'text/html'               => ['schema' => ['type' => 'string']],
            ],
        ],
        400 => ['description' => 'Nothing to translate, no target language, or HTML with several target languages.'],
        404 => ['description' => 'Record not found or not readable.'],
    ],
    skipActionHistory: true,
)]
class ExportHandler extends AbstractHandler
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $query    = $request->getQueryParams();
        $param    = static fn (string $name): string => \is_string($query[$name] ?? null) ? trim($query[$name]) : '';
        /** @var \Atro\Core\Container $container */
        $container = $this->container;
        $messages  = Messages::forUser($container);

        try {
            $settings = (new ConnectionResolver($container))->settings($param('connection') ?: null);
            $exchange = new TranslationFileExchange(new AtroRecordSource($container), $settings);
            $file     = $exchange->export(
                $param('entity'),
                $param('id'),
                $param('source'),
                array_values(array_filter(array_map('trim', explode(',', $param('targets'))))),
                $param('format'),
                \in_array($param('overwrite'), ['1', 'true'], true),
            );
        } catch (SupertextException $e) {
            return new ErrorResponse($e->key === 'record_not_found' ? 404 : 400, $messages->exception($e));
        }

        return new Response(200, [
            'Content-Type'           => $file['contentType'],
            'Content-Disposition'    => 'attachment; filename="' . $file['fileName'] . '"',
            'Cache-Control'          => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $file['content']);
    }
}
