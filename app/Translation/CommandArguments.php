<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Translation;

/**
 * Arguments of "supertext translate" and "supertext export": <Entity> <id> [languages|all] [--overwrite]
 * [--source=xx_XX] [--politeness=more|less] [--format=xliff|html] [--output=<file>], and of
 * "supertext import": <file> [--include-changed] [--overwrite] (the file is `entityType`).
 */
final class CommandArguments
{
    /**
     * @return array{entityType: string, id: string, targets: list<string>, overwrite: bool, source: string, politeness: string, format: string, output: string, includeChanged: bool}
     */
    public static function parse(string $args): array
    {
        $result     = ['entityType' => '', 'id' => '', 'targets' => [], 'overwrite' => false, 'source' => '', 'politeness' => '', 'format' => 'xliff', 'output' => '', 'includeChanged' => false];
        $positional = [];

        foreach (preg_split('/\s+/', trim($args)) ?: [] as $arg) {
            if ($arg === '') {
                continue;
            }

            if ($arg === '--overwrite') {
                $result['overwrite'] = true;
            } elseif (str_starts_with($arg, '--source=')) {
                $result['source'] = substr($arg, 9);
            } elseif (str_starts_with($arg, '--politeness=')) {
                $result['politeness'] = substr($arg, 13);
            } elseif (str_starts_with($arg, '--format=')) {
                $result['format'] = substr($arg, 9);
            } elseif (str_starts_with($arg, '--output=')) {
                $result['output'] = substr($arg, 9);
            } elseif ($arg === '--include-changed') {
                $result['includeChanged'] = true;
            } else {
                $positional[] = $arg;
            }
        }

        $result['entityType'] = $positional[0] ?? '';
        $result['id']         = $positional[1] ?? '';

        if (isset($positional[2]) && $positional[2] !== 'all') {
            $result['targets'] = array_values(array_filter(explode(',', $positional[2])));
        }

        return $result;
    }
}
