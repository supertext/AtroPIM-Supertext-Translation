<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Translation;

/**
 * The "Import translation" page (GET/POST /api/supertext/import): the upload form and the
 * summary, in the user's interface language. Self-contained HTML, everything escaped. No AtroCore
 * classes.
 */
final class ImportPage
{
    /**
     * The form token for a browser session: derived from AtroCore's auth-token cookie, which other
     * sites can't read. Null when the request isn't cookie-authenticated (API clients).
     *
     * @param array<string, mixed> $cookies
     */
    public static function formToken(array $cookies): ?string
    {
        $cookie = $cookies['auth-token'] ?? null;

        return \is_string($cookie) && $cookie !== '' ? hash('sha256', 'supertext-import|' . $cookie) : null;
    }

    /**
     * @param list<array{title: string, lines: list<string>, error: string|null}>|null $blocks the summary, null: just the form
     */
    public static function render(Messages $messages, ?string $token, ?string $error, ?array $blocks): string
    {
        $e     = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = $e($messages->text('file_page_title'));
        $out   = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . ' · Supertext</title><style>'
            . 'body{font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;color:#1f2937;background:#f5f6f8;margin:0;padding:24px}'
            . 'main{max-width:640px;margin:0 auto;background:#fff;border:1px solid #e1e4e8;border-radius:6px;padding:24px}'
            . 'h1{font-size:20px;margin:0 0 8px}h2{font-size:16px;margin:24px 0 8px}h3{font-size:15px;margin:16px 0 4px}'
            . 'label{display:block;margin:12px 0}input[type=file]{display:block;margin-top:6px}'
            . 'button{margin-top:12px;padding:8px 16px;border:0;border-radius:4px;background:#1a56db;color:#fff;font:inherit;cursor:pointer}'
            . '.error{color:#b91c1c}.help{color:#4b5563}ul{margin:4px 0;padding-left:20px}'
            . '</style></head><body><main><h1>' . $title . '</h1>'
            . '<p class="help">' . $e($messages->text('file_help')) . '</p>';

        if ($error !== null) {
            $out .= '<p class="error" role="alert">' . $e($error) . '</p>';
        }

        if ($blocks !== null) {
            $out .= '<h2>' . $e($messages->text('file_result')) . '</h2>';

            foreach ($blocks as $block) {
                $out .= '<h3>' . $e($block['title']) . '</h3>';

                if ($block['error'] !== null) {
                    $out .= '<p class="error">' . $e($block['error']) . '</p>';
                }

                if ($block['lines'] !== []) {
                    $out .= '<ul>';

                    foreach ($block['lines'] as $line) {
                        $out .= '<li>' . $e($line) . '</li>';
                    }

                    $out .= '</ul>';
                }
            }
        }

        $out .= '<form method="post" enctype="multipart/form-data">'
            . ($token !== null ? '<input type="hidden" name="token" value="' . $e($token) . '">' : '')
            . '<label>' . $e($messages->text('file_field'))
            . '<input type="file" name="file" required accept=".xlf,.xliff,.html,.htm,application/x-xliff+xml,text/html"></label>'
            . '<label><input type="checkbox" name="includeChanged" value="1"> ' . $e($messages->text('file_include_changed')) . '</label>'
            . '<label><input type="checkbox" name="overwrite" value="1"> ' . $e($messages->text('file_overwrite')) . '</label>'
            . '<button type="submit">' . $e($messages->text('file_submit')) . '</button>'
            . '</form></main></body></html>';

        return $out;
    }
}
