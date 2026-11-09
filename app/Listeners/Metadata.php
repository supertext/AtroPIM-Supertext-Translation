<?php

declare(strict_types=1);

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Listeners;

use Atro\Core\EventManager\Event;
use Atro\Listeners\AbstractMetadataListener;
use SupertextTranslation\Translation\LanguageMapping;

/**
 * Fills the language options of the Supertext action fields from the configured languages and adds
 * the connection's language mapping: one combo box per language (see LanguageMapping).
 */
class Metadata extends AbstractMetadataListener
{
    public function modify(Event $event): void
    {
        $data = $event->getArgument('data');

        $languages = array_values(array_unique(array_filter(array_merge(
            [(string) $this->getConfig()->get('mainLanguage', 'en_US')],
            (array) $this->getConfig()->get('inputLanguageList', []),
        ))));

        if (isset($data['entityDefs']['Action']['fields']['supertextSourceLanguage'])) {
            $data['entityDefs']['Action']['fields']['supertextSourceLanguage']['options'] = array_merge([''], $languages);
        }

        if (isset($data['entityDefs']['Action']['fields']['supertextTargetLanguages'])) {
            $data['entityDefs']['Action']['fields']['supertextTargetLanguages']['options'] = $languages;
        }

        if (isset($data['entityDefs']['Connection']['fields'])) {
            $data['entityDefs']['Connection']['fields'] = array_merge($data['entityDefs']['Connection']['fields'], LanguageMapping::fieldDefs($languages));
        }

        $event->setArgument('data', $data);
    }
}
