<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Handlers\Supertext;

use Atro\Core\Routing\Route;
use Atro\Handlers\AbstractHandler;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SupertextTranslation\Api\SupertextException;
use SupertextTranslation\Translation\AtroRecordSource;
use SupertextTranslation\Translation\ConnectionResolver;
use SupertextTranslation\Translation\ImportPage;
use SupertextTranslation\Translation\Messages;
use SupertextTranslation\Translation\TranslationFileExchange;

/**
 * "Import translation": GET /api/supertext/import shows the upload page (the action type
 * supertextImport opens it in a new tab), POST (multipart: file, includeChanged, overwrite)
 * imports one XLIFF or HTML file and shows the summary; with "Accept: application/json" it answers
 * JSON instead. The upload is read in memory and never stored. Each record needs edit permission.
 */
#[Route(
    path: '/supertext/import',
    methods: ['GET', 'POST'],
    summary: 'Import a translated file',
    description: 'GET: the upload page. POST (multipart/form-data: file, includeChanged=1, overwrite=1): imports an XLIFF or HTML file exported with GET /supertext/export into the records it came from (at most 10 MB). Send Accept: application/json for a JSON summary.',
    tag: 'Supertext',
    responses: [
        200 => [
            'description' => 'The upload page or the import summary.',
            'content'     => [
                'text/html'        => ['schema' => ['type' => 'string']],
                'application/json' => ['schema' => ['type' => 'object']],
            ],
        ],
        400 => [
            'description' => 'No file, a file that is too large or not a valid translation file.',
            'content'     => [
                'text/html'        => ['schema' => ['type' => 'string']],
                'application/json' => ['schema' => ['type' => 'object']],
            ],
        ],
        403 => ['description' => 'Invalid form token.'],
    ],
    skipActionHistory: true,
)]
class ImportHandler extends AbstractHandler
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var \Atro\Core\Container $container */
        $container = $this->container;
        $messages  = Messages::forUser($container);
        $json      = str_contains($request->getHeaderLine('Accept'), 'application/json');
        $token     = ImportPage::formToken($request->getCookieParams());

        if ($request->getMethod() !== 'POST') {
            return $this->page(ImportPage::render($messages, $token, null, null), 200);
        }

        $body = $request->getParsedBody();
        $body = \is_array($body) ? $body : [];

        // Browser forms authenticate with AtroCore's session cookie: they must carry the token
        // derived from it, so another site can't submit the form. API clients send credentials
        // in headers and need no token.
        if ($token !== null && !hash_equals($token, \is_string($body['token'] ?? null) ? $body['token'] : '')) {
            return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8'], 'Invalid form token.');
        }

        $includeChanged = \in_array($body['includeChanged'] ?? '', ['1', 'on', 'true'], true);
        $overwrite      = \in_array($body['overwrite'] ?? '', ['1', 'on', 'true'], true);

        try {
            $content  = self::readUpload($request->getUploadedFiles()['file'] ?? null);
            $exchange = new TranslationFileExchange(new AtroRecordSource($container), (new ConnectionResolver($container))->settings(null));
            $blocks   = TranslationFileExchange::summary($exchange->import($content, $includeChanged, $overwrite), $messages);
        } catch (SupertextException $e) {
            $error = $messages->exception($e);

            return $json
                ? new Response(400, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'], (string) json_encode(['error' => $e->key, 'message' => $error]))
                : $this->page(ImportPage::render($messages, $token, $error, null), 400);
        }

        return $json
            ? new Response(200, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'], (string) json_encode(['jobs' => $blocks, 'message' => TranslationFileExchange::summaryText($blocks)], JSON_UNESCAPED_UNICODE))
            : $this->page(ImportPage::render($messages, $token, null, $blocks), 200);
    }

    /** The upload's content; the size is checked before anything is read. */
    private static function readUpload(mixed $file): string
    {
        if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            throw new SupertextException('Choose a file to import.', key: 'file_no_file');
        }

        if (\in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($file->getSize() ?? 0) > TranslationFileExchange::MAX_UPLOAD_BYTES) {
            throw new SupertextException('The file is too large (at most 10 MB).', key: 'file_too_large');
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new SupertextException('The file could not be uploaded.', key: 'file_invalid_file');
        }

        $stream  = $file->getStream();
        $content = $stream->read(TranslationFileExchange::MAX_UPLOAD_BYTES + 1);

        while (!$stream->eof() && \strlen($content) <= TranslationFileExchange::MAX_UPLOAD_BYTES) {
            $content .= $stream->read(TranslationFileExchange::MAX_UPLOAD_BYTES + 1 - \strlen($content));
        }

        return $content;
    }

    private function page(string $html, int $status): ResponseInterface
    {
        return new Response($status, [
            'Content-Type'            => 'text/html; charset=utf-8',
            'Cache-Control'           => 'no-store',
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'",
        ], $html);
    }
}
