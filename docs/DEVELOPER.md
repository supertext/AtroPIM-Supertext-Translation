# Developer guide — Supertext Translation for AtroPIM

An AtroCore module (Composer package `supertext/atropim-supertext-translation`, AtroCore module id and PHP namespace `SupertextTranslation`). It works in every AtroCore application; AtroPIM is the one it is built and tested for.

## Architecture

AtroCore loads a module through `composer.json` → `extra.atroId`: the class `\SupertextTranslation\Module` and everything below `app/` by convention.

| Part | Files | What it does |
| --- | --- | --- |
| Module | `app/Module.php` | Load order (after AtroPIM) and the console commands. |
| Action type | `app/ActionTypes/SupertextTranslate.php`, `app/Resources/metadata/action/types.json` | The action type `supertextTranslate`. AtroCore's action framework provides the record button, the mass action, background jobs (*Execute in background*), *Execute as*, conditions and the execution log; `execute()` translates one record (mass actions call it once per record) and writes the summary to the execution and its log. |
| Action routes | `app/Handlers/Action/SupertextTranslate{,Async}Handler.php` | `POST /api/Action/{id}/supertextTranslate` and `…Async`, the URLs AtroCore's front end calls for an action type. |
| Action options | `app/Resources/metadata/entityDefs/Action.json`, `app/Listeners/ActionLayout.php`, `app/Listeners/Metadata.php` | Real columns on `action` (`supertext_connection_id`, `supertext_source_language`, `supertext_target_languages`, `supertext_overwrite`, `supertext_politeness`), visible for this type only; the layout listener adds them to the form, the metadata listener fills the language options from the configured languages. |
| Connection type | `app/ConnectionType/ConnectionSupertext.php`, `app/Resources/metadata/app/connectionTypes.json`, `entityDefs/Connection.json`, `app/Listeners/ConnectionLayout.php` | Connection type `supertext`, the module's settings page: API key (a `password` field, encrypted by AtroCore), environment, URL, timeout, the language mapping (one `enum` field per AtroCore language, `supertextCode<Language>`, see *Language codes*) with a *Translation review* `bool` next to each (`supertextReview<Language>`), the review service URL (`supertextReviewUrl`, see *Translation review* under *Supertext API protocol*), *Send version information to Supertext* (`supertextVersionReports`, bool, default on) and the read-only `supertextKeyCheck` / `supertextLanguageStatus`, all stored in the connection's `data` JSON (`dataField`). *Test connection* runs the reporter's key check (see *Commando Center*). |
| Language mapping | `app/Translation/LanguageMapping.php`, `app/Listeners/Metadata.php`, `Language.php`, `ConnectionLayout.php` | The combo boxes of the connection: field definitions, labels (`Language` listener, event `Language` → `modify`: label, tooltip and option names per interface language, from `Connection.json` → `labels`), form rows, the server-side check and the migration of the old text field (no AtroCore classes in `LanguageMapping`: `tests/unit/LanguageMappingTest.php`). |
| Connection events | `app/Listeners/ConnectionService.php`, `app/Translation/ConnectionStatus.php` | `afterReadEntity`: moves lines of the old text mapping into the combo boxes (saved once), shows each empty combo box with its suggestion, fills the two read-only fields in the viewer's language, then the reporter's `onRequest` after the response; `afterCreateEntity`/`afterUpdateEntity`: `afterSettingsSaved` (key check, report), then stores both fields (`saveEntity(…, ['skipAll' => true])`: no hooks, no history) so the form shows the result at once; `before…Entity` drops the read-only fields from incoming data and refuses a combo box value that isn't one of Supertext's codes (`invalid_language_code`); `after…Entity` also runs the migration. |
| Commando Center | `app/Translation/Reporting.php`, `ReportingFactory.php`, `app/Handlers/Supertext/PingHandler.php` | Version reports, the automatic API key check and the public ping `GET /api/supertext/ping` (see *Commando Center*). |
| Result message | `app/Listeners/ActionService.php` | `afterExecuteNow`: replaces AtroCore's "Action … was executed" with the summary ("de_CH, fr_CH: translated (4 fields)", or counts for a mass action); for the file action types it adds the result's `link` (download, upload page). |
| Translation | `app/Translation/EntityTranslator.php` | Loads the record through its record service, plans, sends one HTML document per target language, saves with the record service's `updateEntity()` (ACL, validation, hooks, history). |
| Planning | `app/Translation/Planner.php`, `TextUnit.php` | Which fields are translated (no AtroCore classes: unit-tested). |
| Settings | `app/Translation/Settings.php`, `ConnectionResolver.php` | Connection data → API key, base URL, timeout, language mapping (Supertext codes through the SDK's `Languages`), *Translation review* per language and the review service URL, the reporting setting; `SUPERTEXT_API_KEY` / `SUPERTEXT_API_URL` / `SUPERTEXT_REVIEW_URL` fallbacks. |
| Translation files | `app/ActionTypes/SupertextExport.php`, `SupertextImport.php`, `app/Handlers/Supertext/ExportHandler.php`, `ImportHandler.php`, `app/Translation/TranslationFileExchange.php`, `RecordSource.php`, `AtroRecordSource.php`, `ImportPage.php` | *Export for translation* / *Import translation* (XLIFF 1.2 and HTML files, SDK `TranslationFiles`; see *Translation files*). |
| Console | `app/Console/Check.php`, `Translate.php`, `Export.php`, `Import.php`, `app/Translation/CommandArguments.php` | `supertext check`, `supertext translate <args>`, `supertext export <args>`, `supertext import <args>`. |
| API client | `app/Api/` | Wrapper around the Supertext SDK (`SupertextClient`, `CallableTransport`) and the exception (no AtroCore classes: unit-tested) |
| Supertext SDK | `lib/supertext-sdk/` | Bundled copy of the Supertext PHP SDK, namespace `SupertextTranslation\Sdk` (API classes in `SupertextTranslation\Sdk\V1`; see *Supertext SDK*) |
| Supertext Plugin Health | `lib/supertext-plugin-health/` | Bundled copy of Supertext Plugin Health (the Commando Center reporter), namespace `SupertextTranslation\PluginHealth` (see *Supertext Plugin Health*) |
| Translations | `app/Resources/i18n/{en_US,de_DE,fr_FR,it_IT}/` | Labels, options and tooltips of the new fields (`Action.json`, `Connection.json`) and the messages editors see (`Action.json` → `messages`, keys `supertext…`). |
| Messages | `app/Translation/Messages.php` | Messages in the user's language through AtroCore's `language` service (`Messages::forUser()`), falling back to the English file; `{name}` placeholders. `SupertextException` carries a key, params and Supertext's untranslated detail; the console uses English (`new Messages()`). No AtroCore classes. |

### Field rules

`Planner::units()` decides what is translated (keep [USER_GUIDE.md → What is translated](USER_GUIDE.md#what-is-translated) in sync):

- AtroCore stores a multilingual field as a base field in the main language (`name`) plus one field per additional language (`nameDeCh`) with `multilangField: name` and `multilangLocale: de_CH`. Attribute values follow the same model; `EntityTranslator` merges the entity's own `entityDefs` (where the attribute fields are) into the metadata field definitions.
- Translated: base fields with `isMultilang` of type `varchar`, `text`, `markdown` (plain text) and `wysiwyg` (HTML) that have at least one language sibling.
- Skipped: `readOnly`, `disabled`, `notStorable` and `emHidden` fields; every other type.
- A target field with text (after removing tags and whitespace) is kept unless *overwrite* is set.
- Plain text is sent HTML-escaped with line breaks as `<br>`; rich text as is. Each field is one `data-st-id` element (whole sentences), so formatting inside a paragraph stays inline.
- Plain-text translations longer than the field's `maxLength` are not saved (named in the message); the rest is saved in one `updateEntity()` per language. `NotModified` (the record already has exactly this text) counts as success.

## Supertext API protocol

Shared with the WordPress plugin and every other Supertext plugin:

1. `POST {base}translate/ai/file`: multipart with `file` (part `Content-Type` exactly `text/html`, no charset, or the API answers 415), `target_lang` (a Supertext target code, e.g. `de-CH` or `es`), optional `source_lang` (bare language, e.g. `en`); see *Language codes*, optional `politeness` (`more`/`less`, only for targets that offer it). Returns `{file_id}`.
2. `GET …/{file_id}/status` until `done` (`error`, `limit_exceeded`, `deleted` are terminal).
3. `GET …/{file_id}/translation` returns the translated HTML.
4. `DELETE …/{file_id}` (files also expire after 24 h).

Auth header: `Authorization: Supertext-Auth-Key <key>`. The header name must be `Authorization` (`Authentication` gets 403). Supertext shows the key with the prefix, so the client strips a pasted `Supertext-Auth-Key ` and always sends exactly one. Base URLs: `https://api.supertext.com/v1/` (live), `https://api.staging.supertext.com/v1/`, `https://api.testing.supertext.com/v1/`. `GET features` is the cost-free key check (*Test connection*, `supertext check`).

**Rate limit:** the API limits requests per second per key (HTTP 429). The client retries a 429 up to 4 times, waiting for `Retry-After` if sent, otherwise 1, 2, 4 and 8 seconds plus jitter. Target languages are translated one after the other.

Language codes: see *Language codes* (the connection's mapping, else an exact Supertext code, else the SDK's default target, else `unsupported_language`).

User-Agent: `AtroPIM-Supertext-Translation/<version>` (the module's version from Composer, as in the reports: `ReportingFactory::moduleVersion()`, `dev` when unknown), after the SDK's own name.

**Translation review** (per target language: the checkbox `supertextReview<Language>` next to the language's combo box, `LanguageMapping::reviewField()`; `Settings::review()`): `EntityTranslator` takes `SupertextClient::forReview($settings->review($target))` for each target, a second SDK client with the same key, timeouts, transport and User-Agent whose base URL is the review service (`Settings::reviewUrl()`: `SUPERTEXT_REVIEW_URL`, else the connection's `supertextReviewUrl`, never saved = `SupertextClient::REVIEW_URL`, the translation proxy of [Supertext Localization Review](https://github.com/Supertext/Localization-Review-Supertext-Translation); created once, lazily). Each document's whole round trip (upload, status, download, delete: the SDK's `translateHtml()`) runs on the client that submitted it. The proxy speaks the same API v1, forwards to the live Supertext API with the module's key, stores every segment per key and serves reviewers' corrections on the next upload, so nothing else changes in the module. An empty review URL (or the API's own URL) means `forReview()` always returns the direct client and `review()` is false. The key check (`Reporting`, its own SDK client on `Settings::baseUrl()`), the version reports and the translation files never use the review service. `tests/unit/SupertextClientTest.php` checks that a review language's four calls go to the review URL with the same key and the versioned User-Agent, that other languages go directly, and that an empty or identical URL gives no second client; `SettingsTest` checks the default (Supertext's review service), off with `''`, the environment override and the **Languages** note; `demo-check.sh` ticks the checkbox through the REST API and checks `supertext check`.

## Supertext SDK

The protocol above, the retries, the list of Supertext languages, the API key check and the `data-st-id` document come from the official Supertext PHP SDK ([Supertext/Supertext-API-SDK-and-Guide](https://github.com/Supertext/Supertext-API-SDK-and-Guide)). The module ships a copy in `lib/supertext-sdk/` with its namespace changed from `Supertext\Sdk` to `SupertextTranslation\Sdk` (Composer autoloads it from the module's `composer.json`; `tests/bootstrap.php` and `tests/phpstan-bootstrap.php` too), so sites install nothing extra and another package that requires the SDK itself can't clash with it. HTTP goes through the SDK's `CurlTransport` (ext-curl, which AtroCore requires anyway; proxy environment variables apply). `lib/supertext-sdk/COMMIT` names the SDK revision.

- Since SDK 0.2.0 everything that talks to the API is in the namespace of its API version, `SupertextTranslation\Sdk\V1` (`src/V1/`); only the HTTP transport (`…\Sdk\Http`) and `…\Sdk\Supertext::SDK_VERSION` are version-neutral. The vendor script copies `src/` with its subfolders and rewrites every `Supertext\Sdk\` prefix, `V1` included.
- Never edit `lib/supertext-sdk/`. Fix the SDK in its repository, then run `scripts/vendor-sdk.sh [ref]` (default `main`; `SDK_DIR=/path/to/clone` uses a local clone) and the tests.
- `app/Api/SupertextClient.php` keeps the module's interface (`translateDocument`, `translateSegments`, `validateApiKey`; a transport or a fake callable) and turns every SDK error code into a `SupertextException` with a `key` (`KEYS`) and `params`; `Translation\Messages` shows the key's text. A new SDK error code needs a key there and its `supertext…` text in all four `i18n/*/Action.json`.
- The SDK provides the client and the API key check (`Client::checkApiKey()`, `Protocol::apiKeyFingerprint()`, `Supertext::SDK_VERSION`); since SDK 0.3.0 the version reports are [Supertext Plugin Health](#supertext-plugin-health)'s.
- The translation files (`SupertextTranslation\Sdk\Files`, SDK 0.5.0) are not tied to an API version and never call the API (see *Translation files*); the vendor script's namespace rewrite covers them like `V1`.
- PHPStan analyses `app/` only; the SDK and Plugin Health have their own checks in their repositories.
- Segments missing from Supertext's answer are left untouched.
- The live API answers an unknown language (`xx-YY`) with HTTP 404 `INVALID_LANGUAGE_PAIR`; the SDK reports it as `invalid_language_pair` with both codes (message `supertextInvalidLanguagePair`). A language without a Supertext code never reaches the API: `Languages::target()`/`source()` throw `unsupported_language` (message `supertextUnsupportedLanguage`, with `{language}`); a tone the target doesn't offer comes back as `unsupported_feature` (`supertextUnsupportedFeature`).

## Translation files

*Export for translation* and *Import translation*: a record's multilingual texts as an XLIFF 1.2 or HTML file, translated outside the API (a CAT tool, a translator, Supertext's web app), and the translated file written back. The file formats, checksums and the safety checks of imported files are the SDK's `SupertextTranslation\Sdk\Files\TranslationFiles` (SDK 0.5.0, integration guide → *Translation files: XLIFF and HTML*); the module only collects, finds and writes back.

- **Core** (`app/Translation/TranslationFileExchange.php`, no AtroCore classes, `tests/unit/TranslationFileExchangeTest.php`): `export()` builds one SDK `FileJob` per target language from exactly what an API translation sends (`Planner::units()` + `Planner::plan()`, so existing target texts are left out unless *overwrite*), segment key = the unit's base field name (`name`, `longDescription`, attribute value fields), plain text as plain segments and `wysiwyg` as HTML. `import()` refuses files over 10,000,000 bytes, `read()`s the file, and per job checks `plugin` (`AtroPIM-Supertext-Translation`, else *other system*), parses `reference` (else *not found*), checks both languages exist (`inputLanguageList`), loads the record (not found / not readable → *not found*; not editable → *not allowed*), collects the current segments the same way and calls `importTranslations()`. `ok` results (and `source_changed` with *include changed*) are saved in one `updateEntity()` per job with the API translation's rules (plain text over `maxLength` is not saved and named); `untranslated`, `invalid_markup` and `missing` are only counted. *Kept* counts the file's keys whose field got a target text since the export (left alone without *overwrite*).
- **Reference** of a job: `<Entity>:<id>:<source language>:<target language>`, e.g. `Product:6650a1b2c3d4e5f60:en_US:de_CH` (AtroCore entity name, record id and language codes, never titles; `TranslationFileExchange::reference()` / `parseReference()`). The source language is part of it because an action may translate from another language than the main one.
- **Languages in the file:** `sourceLanguage`/`targetLanguage` are the Supertext codes of the connection's mapping (`Settings::sourceCode()` / `languageCode()`); a language without one is written as its AtroCore code with `-` (`xx_YY` → `xx-YY`), so a language without a Supertext language never blocks the export.
- **File names:** `atropim-<entity>-<id>-<language>.xlf` / `.html`, `atropim-<entity>-<id>.xlf` for several languages; `Content-Type` `application/x-xliff+xml` / `text/html; charset=utf-8`, `Content-Disposition: attachment`.
- **AtroCore glue:** `AtroRecordSource` (the `RecordSource` interface) loads through the record service (`getEntity()`: read ACL), checks `acl->check($entity, 'edit')` and saves with `updateEntity()` (ACL, validation, hooks, history; `NotModified` is success), with the same field definitions as `EntityTranslator` (attribute value fields from the loaded entity).
- **UI:** AtroCore has no per-record UI hook a module can add a dialog to without its own front end, so the files ride on its action framework: the action types `supertextExport` and `supertextImport` (handlers `POST /api/Action/{id}/supertextExport|supertextImport`, no `…Async`: a download can't run in the background). `SupertextExport::execute()` builds the file once (so *Nothing to translate.* and the like come back as the action's error) and `ActionService::afterExecuteNow` puts `link` = `api/supertext/export?entity=…&id=…&targets=…&format=…` into the result, which AtroCore's front end opens with `window.open()` (`executeActionRequest` in `client/src/views/record/detail.js` and `list.js`); the browser's AtroCore session cookies (`auth-username`, `auth-token`, accepted by `AuthMiddleware`) authenticate it. `SupertextImport` links to `api/supertext/import`, a self-contained server-rendered page (`ImportPage`, strings in `Action.json` → `messages` → `supertextFile…`) that posts `multipart/form-data` to itself and shows the summary. A cookie-authenticated POST must carry the form token `sha256("supertext-import|" + auth-token cookie)` (other sites can't read the cookie); header-authenticated API calls need none. `Accept: application/json` gets the summary as JSON.
- **Routes:** `ExportHandler` (`GET /supertext/export`) and `ImportHandler` (`GET`/`POST /supertext/import`), `#[Route]` with `skipActionHistory` (no request bodies in the action history). AtroCore validates requests and responses against the attribute (league/openapi-psr7-validator): the success content types are declared; the import has no `requestBody` (the validator would try to parse the multipart body), errors have no content schema.
- **Uploads** are read from the PSR-7 uploaded file in memory (size checked first, `UPLOAD_ERR_INI_SIZE` reported as too large) and never written anywhere.
- **Console:** `supertext export <Entity> <id> [languages] [--format=xliff|html] [--source=…] [--overwrite] [--output=<file>]` and `supertext import <file> [--include-changed] [--overwrite]`, as the system user, English summary.
- `tests/demo-check.sh` exports a product as the editor through the REST API, fills one `<target>`, imports it and checks the stored value, the 403 for a missing form token and the upload page.

## Supertext Plugin Health

[Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) is the dependency-free library every Supertext plugin uses for the version reports, the report of the API key check's result and the answer of the ping endpoint (see *Commando Center*); the key check itself is the SDK's. It lives in the Commando Center's repository (`plugin-health/`). The module ships a copy of its PHP part in `lib/supertext-plugin-health/` with its namespace changed from `Supertext\PluginHealth` to `SupertextTranslation\PluginHealth`, autoloaded like the SDK's copy (`composer.json`, `tests/bootstrap.php`, `tests/phpstan-bootstrap.php`). `COMMIT` and `VERSION` there name the revision.

- Never edit `lib/supertext-plugin-health/`. Fix it in Commando-Center-Supertext-Translation/plugin-health (all five languages, see its README), then run `scripts/vendor-plugin-health.sh <tag>` (e.g. `plugin-health-v0.1.0`; default `main`; `PLUGIN_HEALTH_DIR=/path/to/clone` uses a local clone as it is) and the tests.

## Language codes

Supertext accepts only the codes of `GET features` (the SDK's `Languages::TARGETS` / `SOURCES`): targets are regional only for German, English, French, Italian and Portuguese (`de-CH`, `en-US`, `pt-BR`), a script for Chinese and Serbian (`zh-Hans`, `sr-Latn`), a dialect for Swiss German (`gsw-u-sd-chzh`), and **bare for all others** (`es`, `nl`, `sl`, …); sources are bare (`en`). The old default regions (`es-ES`, `sl-SI`, `zh-CN`) are rejected by the live API; since SDK 0.4.0 only the SDK's obvious defaults apply (below).

`Settings::languageCode()` / `sourceCode()` (no AtroCore classes, `tests/unit/SettingsTest.php`) resolve every AtroCore language with `Languages::target($code, $mapping)` / `Languages::source($code, $mapping)`, using **one** mapping for both: the connection's combo boxes win (`LanguageMapping::fromData()`; only Supertext's codes count), then lines of the old text field `supertextLanguageCodes` (`"de_DE = de-CH"`), else an exact match (case and `_`/`-` ignored: `de_CH` → `de-CH`, `en_US` → source `en`), else (targets only, since SDK 0.4.0) the SDK's default (`de` → `de-DE`, `zh_CN` → `zh-Hans`, `es_ES` → `es`, `nl_NL` → `nl`; nothing for `de_LI`, `en_IE`, `fr_BE`, `sr_RS`), else `unsupported_language` (a `SupertextException` with key `unsupported_language` and `{language}`; nothing is sent). A chosen target is sent in its source form when the language is the source (`de-CH` for `de_DE` → source `de`, since SDK 0.2.1). A line of the old text field with a value that isn't in the SDK's list is sent as entered (the combo boxes only offer the list).

**The combo boxes** (`LanguageMapping`): `Listeners\Metadata` adds one Connection field per AtroCore language (main language and `inputLanguageList`, `ConnectionStatus::languages()`): `supertextCode` + the code in AtroCore's camel case (`de_DE` → `supertextCodeDeDe`, like AtroCore's multilingual fields), type `enum`, `notStorable` + `dataField` (stored in the connection's `data` JSON like the other settings), options `""` and `Languages::targetOptions()` (sorted by name), `default` `Languages::suggestTarget()` (so a new connection starts preselected). `Listeners\Language` labels them for every interface language: *Supertext language for German (de_DE)* (the language's name from AtroCore's languages), the tooltip, `""` as *— choose —* and each code as "Name (code)" (the SDK's English name; other interface languages use PHP's `Locale::getDisplayName()` where `intl` is installed, except the Swiss German dialects). `Listeners\ConnectionLayout` puts one row per language between the timeout and *Send version information*. On read, an empty combo box shows `suggestTarget()` (not saved: an empty one is translated with the same code). On save, `ConnectionService` refuses values outside the list with a `BadRequest` (AtroCore's own enum validation would too); `Settings` ignores them anyway.

**Migration of the old text field** (`LanguageMapping::migrate()`, on every read and save of a Supertext connection, saved with `skipAll`): a line for one of the languages whose combo box has never been saved becomes its value (in Supertext's spelling) if Supertext lists the code; a line for a language whose combo box exists is dropped (the combo box wins); lines for other languages or with unlisted codes stay in `supertextLanguageCodes`, which `Settings` still reads. So nothing configured is lost and the resolved codes don't change (`LanguageMappingTest`).

`EntityTranslator` resolves the source once (a source without a Supertext language fails the whole run) and each target in its loop (such a target fails only that language). Formal/informal is sent only where `Languages::supportsPoliteness($target)` isn't `false`; otherwise the language is translated with the default tone and its result carries `supertextPolitenessSkipped`, so the setting is never dropped silently. `Settings::languageStatus()` builds the connection's read-only **Languages** field (code, *no Supertext language*, *no formal/informal tone*, *not in the list*) and the `supertext check` output.

The demo's languages (`en_US`, `de_CH`, `fr_CH`, `it_CH`) are exact Supertext codes, so their combo boxes are preselected and need no choice; `tests/demo-check.sh` checks the preselection, the options, a refused value, a changed choice and the migration of an old line. The stand-in (`tests/docs/stand-in.mjs`) accepts only Supertext's codes, like the real API.

## Commando Center

Every Supertext plugin reports to the Supertext Commando Center (protocol: Commando-Center-Supertext-Translation → `docs/DEVELOPER.md` → *Report protocol*). The protocol is [Supertext Plugin Health](#supertext-plugin-health)'s `InstallationReporter`, with the SDK's `checkApiKey()` and `Protocol::apiKeyFingerprint()` as its key checker (`CallableKeyChecker`) and `Supertext::SDK_VERSION` as `sdk_version`; the module only supplies what AtroCore knows:

- `app/Translation/Reporting.php` (no AtroCore classes, `tests/unit/ReportingTest.php`): builds the reporter (`plugin.name` = `AtroPIM-Supertext-Translation`, the GitHub repository name), wraps every call so nothing ever throws, keeps the state through two closures (`ReportTransport` lets a test's SDK transport carry the reports too; without one, the key check uses the SDK's curl and the report Plugin Health's), and builds the texts of the read-only **API key check** field (the center's messages in en/de/fr/it, `Action.json` → `messages` → `supertextKeyCheck…`, with the bare URLs since the field is plain text).
- `app/Translation/ReportingFactory.php` (AtroCore glue): `siteUrl` = AtroCore's `siteUrl` (the request's host if empty), `plugin.version` = the module's Composer version (`moduleManager->getModule('SupertextTranslation')->getVersion()`, the same source *Updates & Modules* and `supertext check` show), `system` = `AtroPIM` and the Pim module's version (`AtroCore` and `SoftwarePackage::getCoreVersion()` without AtroPIM), runtime PHP. `callbackUrl` = `<siteUrl>/api/supertext/ping`. The API base URL of the key check is the connection's (so `SUPERTEXT_API_URL` and *Staging* apply).
- **State:** AtroCore's config file (`data/config.php`, key `supertextReporter`, the reporter's JSON as it is, the same JSON the SDK 0.1–0.2 reporter stored, so sites keep their history (no new `installed` report); written only when it changed, through `Config::save()`, which merges only the changed key). `Settings::getConfigData()` never sends it to the browser (only declared settings fields). It holds the key's SHA-256 fingerprint, never the key.
- **Setting:** `supertextVersionReports` on the connection (null = never saved = on), combined with `SUPERTEXT_VERSION_REPORTS` by `InstallationReporter::reportsEnabled()`. With several connections, the one saved or used counts; the ping follows the first connection.

When the reporter runs:

| Moment | Where | Reporter call |
| --- | --- | --- |
| Connection created or saved | `ConnectionService::afterCreateEntity` / `afterUpdateEntity` | `afterSettingsSaved` (result stored in the read-only field) |
| Connection shown | `ConnectionService::afterReadEntity` | `onRequest`, after the response (`ReportingFactory::defer()`) |
| *Test connection* | `ConnectionSupertext::testConnection` | `testConnection`; anything but `ok` is shown as the error |
| A translation (action, mass action, job, `supertext translate`) | `EntityTranslator::translate` → `ReportingFactory::afterTranslation()` | once per process, at its end: `authenticationFailed` if Supertext answered 401/403 (`markAuthenticationFailed()`), else `onRequest` |
| `supertext check` | `Console\Check` | `testConnection`, then `reportCheck` |

`defer()` registers a shutdown function that first ends the response (`fastcgi_finish_request()` under PHP-FPM; under mod_php it flushes the output and runs right after), so reports never delay or fail the work. Front-end requests never run it (AtroCore has none that run the module).

**Ping endpoint:** `app/Handlers/Supertext/PingHandler.php`, `#[Route(path: '/supertext/ping', methods: ['GET'], auth: false, skipActionHistory: true)]` → `GET /api/supertext/ping`. AtroCore's `AuthMiddleware` lets it through without credentials (`useNoAuth()`), no session, no CSRF; the API has no page cache. It answers `InstallationReporter::pingResponse()` exactly (status, headers, body as a Guzzle PSR-7 response); the 200, 400 and 404 answers are declared in the route attribute because AtroCore validates responses against it.

## Local development

The quickest setup is the demo image (below), which installs AtroPIM with the module. To work on the module in your own AtroCore project, add this repository as a Composer path repository and run AtroCore's update:

```json
"repositories": [{"type": "path", "url": "/path/to/AtroPIM-Supertext-Translation", "options": {"symlink": true}}]
```

```bash
php atrocore-installer.phar require supertext/atropim-supertext-translation:@dev
php console.php "clear cache"     # after changing metadata, layouts or listeners
```

New fields in `entityDefs` get their database columns from AtroCore's schema diff (`php console.php "sql diff --run"`; the installer's update runs it).

## Tests

```bash
phpunit                                                   # PHPUnit 11, no AtroCore needed
find app tests demo -name '*.php' -print0 | xargs -0 -n1 php -l
```

The unit tests cover the SDK wrapper (protocol, errors, segments, splitting into several documents), the planner, the settings and the language mapping (the combo boxes' definitions, labels, rows, the server-side check and the migration of the old text field; no guessing beyond the SDK's defaults, languages without a Supertext language, the **Languages** field), the reporting (each reporter call, state storage, a state stored by the SDK 0.2 reporter, off by setting or environment, the hour after a failed report, the ping answers, the key check texts; against a fake transport, never the live API or center), the translation files (`TranslationFileExchangeTest`: reference round trip, export with the collector's keys and the mapping, HTML with one language, nothing to translate, import with another plugin, an unknown reference or language, a record that isn't editable, `ok` written, `source_changed` only with the option, `untranslated`/`invalid_markup`/`missing` never written, kept fields, `maxLength`, a rejected save, files too large or invalid, the upload page and its form token), the console arguments and the interface languages (`InterfaceLanguagesTest`: the same keys, placeholders and links in all four languages, and an English text for every message key the code uses). `tests/demo-check.sh` is the end-to-end test: it starts the demo image twice against MySQL with the stand-in API (`tests/docs/stand-in.mjs`), checks the demo accounts, translates a product with the console command and the other one with the REST API as the editor, and checks the stored values; it also checks `supertext check`, the reports the stand-in received as Commando Center (`SUPERTEXT_REPORT_URL`: `installed` and `check`, plugin, system, site URL, callback, key check, never the key) and the public ping endpoint (200 with the challenge, 400, `Cache-Control: no-store`). CI (`.github/workflows/ci.yml`) runs both (lint and unit tests on PHP 8.4 and 8.5), plus PHPStan (see *Code quality and security checks*). When the demo job fails, its last step also posts the tails of the image build, `demo-check.sh`, the container log and the stand-in log as error annotations on the check run (readable through the checks API when the job log isn't).

## Demo (Railway)

`demo/` builds one image (`demo/Dockerfile`, build context = repository root; `railway.json` points Railway at it): PHP 8.4 + Apache with AtroCore 2.5.4, AtroPIM 1.16.10 and this module, plus AtroCore's job runner (`console.php cron` every minute). MySQL is a separate service.

On every start `demo/docker/entrypoint.sh`:

1. links `data/` and `upload/` to the volume at `/data` (AtroCore keeps its configuration, languages and the encryption key of connection passwords in `data/`, so without a volume a restart forgets them) and refreshes the module list and cache from the image;
2. `install.php`: creates the database `ATROPIM_DB_NAME` on the MySQL server if needed and installs AtroPIM on the first start, with `DEMO_ADMIN_EMAIL` as administrator (the installer empties that database, so it refuses `railway` and other shared names);
3. `sql diff --run` for columns of newer module versions;
4. `setup.php model`: the languages German, French and Italian (Switzerland) and the multilingual product attribute *Tasting notes*;
5. `setup.php content`: two sample products, the Supertext connection (no key: it uses `SUPERTEXT_API_KEY`), the action *Translate with Supertext* for products (*Execute as: Same user*), the role *Product editor (Supertext demo)* and the `DEMO_*` accounts.

Everything is created only if missing; existing records and accounts are never changed. Step 4 and 5 are separate processes because new languages and attributes change the metadata the next step needs.

| Variable | |
| --- | --- |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Administrator (user name = e-mail). Replaces AtroCore's installation screen: with `DEMO_*` set, it never appears. |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Role *Product editor (Supertext demo)*: reads and edits products (attribute values included) in every language, reads categories, brands, attributes, files and actions. AtroCore has no editor role out of the box. |
| `MYSQL_URL` | `mysql://user:password@host:3306/…`; the database name in the URL is ignored. Railway: `mysql://root:${{MySQL-8.MYSQL_ROOT_PASSWORD}}@mysql8.railway.internal:3306/railway`. |
| `ATROPIM_DB_NAME` | The demo's own database (default `atropim`). |
| `SUPERTEXT_API_KEY` | The Supertext key the demo's connection falls back to. |
| `SUPERTEXT_API_URL` | Only for tests against the stand-in. |
| `SUPERTEXT_VERSION_REPORTS` | `0` turns the Commando Center reports off (default on: the demo reports like any installation). |
| `SUPERTEXT_REPORT_URL` | Only for tests: where reports go instead of the Commando Center (the stand-in: `http://127.0.0.1:8765/report`). |
| `DEMO_SITE_URL` | Public URL, if it isn't `https://$RAILWAY_PUBLIC_DOMAIN`. |
| `PORT` | Set by Railway (default 8080). |

Passwords must meet AtroCore's rule (at least 8 characters with an upper-case letter, a digit and a special character); otherwise that account is skipped with a warning and the demo still starts. The log names variables, never values. Values live only in Railway's variables; `demo/.env.example` lists them.

Railway: project *supertext-cms-demos-php*, service *AtroPIM* (volume `atropim-data` at `/data`), database `atropim` on the shared *MySQL-8* service, <https://atropim-production.up.railway.app/>. Pushes to `main` deploy. The `DEMO_*` and `SUPERTEXT_API_KEY` variables reference the *Contao* service's; `DEMO_ADMIN_PASSWORD` is `${{Contao.DEMO_ADMIN_PASSWORD}}-A1`, because the shared password doesn't meet AtroCore's password rule. The first start takes about ten minutes (each new language rebuilds AtroCore's schema).

Locally:

```bash
docker build -f demo/Dockerfile -t supertext-atropim-demo .
docker run --rm -p 8080:8080 --env-file demo/.env -v atropim-data:/data supertext-atropim-demo
# http://localhost:8080 (the first start installs AtroPIM: about a minute)
docker exec <container> demo-console supertext check
```

## Docs screenshots

`tests/docs/screenshots.mjs` (Playwright) regenerates `docs/images/` from a **fresh** demo (no translations yet) whose `SUPERTEXT_API_URL` points at `tests/docs/stand-in.mjs`. The stand-in answers like the Supertext API and returns real German, French and Italian translations of the sample content (`tests/docs/samples.json`; new demo content needs entries there), so the guides never show placeholder text.

```bash
cd tests/docs && npm install
node stand-in.mjs &
# a fresh demo with SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/, SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report
# (the stand-in also answers reports, so screenshots never report to the live center) and DEMO_* set, on port 8095
BASE_URL=http://127.0.0.1:8095 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… node screenshots.mjs
```

The images are 1× and cropped to the relevant part. The connection's API URL isn't shown (the demo's connection is set to *Live*; the stand-in comes from the environment variable).

## Code quality and security checks

- **Checks** (`.github/workflows/checks.yml`): actionlint and zizmor lint the workflows on every push and pull request; dependency review fails a pull request that adds a package with a known vulnerability (moderate or worse). Third-party actions are pinned to commit SHAs (Dependabot keeps them current). Run the linters locally with `pip install actionlint-py zizmor`, then `actionlint` and `zizmor .github/workflows`.
- **Links** (`.github/workflows/links.yml`): lychee checks the links in all Markdown files weekly and whenever docs change on `main`. Broken links open (or update) the issue "Broken links in the docs". Links that can't work from CI (local addresses, pages behind a login, placeholders) are excluded in `.lycheeignore`.
- **PHPStan** (job `phpstan` in `ci.yml`, configuration in `phpstan.neon`): level 5 on `app/`. PHPStan needs AtroCore's classes, so the job installs AtroPIM from `demo/project/composer.json` (without scripts) and loads its autoloader through `tests/phpstan-bootstrap.php`. Locally, point `ATRO_VENDOR` at the `vendor/` folder of an AtroCore 2.4 / AtroPIM 1.16 installation: `ATRO_VENDOR=/path/to/atro/vendor phpstan analyse` (PHPStan 2.x, e.g. the `phpstan.phar` from its GitHub releases). Existing findings that aren't fixed yet are listed in `phpstan-baseline.neon` (regenerate with `phpstan analyse --generate-baseline` after fixing some); new code must not add any.
- **GitHub settings** (set by Remy's setup script, not in the repo): secret scanning with push protection (a push containing a known token format is rejected; findings under *Security → Secret scanning*) and CodeQL default setup (findings under *Security → Code scanning* and as pull request comments). CodeQL doesn't cover PHP, which is why this repo runs PHPStan.
- `.github/workflows/support-triage.yml`: support requests from the Supertext integrations website arrive as issues labelled `support` (contact details stay in the Supertext CMS). Claude classifies each one: answers questions (marked `<!-- supertext:reply -->`, emailed to the sender by the CMS) and closes them, fixes bugs as pull requests for review (never merged automatically), labels feature requests `feature-request`. Only people with triage rights can add labels, so outsiders can't start it. Needs the organisation secret `ANTHROPIC_API_KEY`; the workflow is the same in every Supertext repo.

Before starting work in this repo, look at its open findings: code scanning alerts, secret scanning alerts, Dependabot pull requests and the issue "Broken links in the docs".

## Releasing

Releases are published by `.github/workflows/release.yml` when the version is officially bumped; nobody tags or creates releases by hand.

1. Move the *Unreleased* entries in `CHANGELOG.md` under a new `## X.Y.Z — YYYY-MM-DD` section, and keep an empty *Unreleased* above it.
2. There is no version field to change: Composer takes the version from the Git tag the workflow creates (don't add `version` to `composer.json`). AtroCore shows it under *Administration → Updates & Modules* (from Composer's lock file); `supertext check` prints it and links X.Y.Z versions to their GitHub release.
3. Push to `main`. The workflow tags `vX.Y.Z` and creates the GitHub release with the CHANGELOG section as notes (0.x versions as pre-releases). A push that adds no new version does nothing, and a version that is already released is skipped. After fixing a failed run, start it again with *Run workflow* on the *Release* workflow.

## Conventions

- PSR-12, PHP 8.4 (and 8.5), strict types; keep `app/Api/`, `Planner`, `TextUnit`, `Settings`, `Messages`, `Reporting` and `CommandArguments` free of AtroCore classes (the unit tests run without AtroCore).
- Interface languages: every label, tooltip, option and message goes into all four `i18n` folders (`en_US`, `de_DE`, `fr_FR`, `it_IT`) in the same commit. Formal address (Sie, vous, Lei), AtroCore's own terms in each language (e.g. *Verwaltung → Verbindungen*, *Administration → Connexions*, *Amministrazione → Connessioni*, *enregistrement*, *action de masse*), and never translate "Supertext", `{placeholders}` or URLs. New messages: a snake_case key (`$messages->text('no_text')`) whose text is `messages` → `supertextNoText` in `Action.json`.
- New action or connection options: `entityDefs/Action.json` or `Connection.json`, the layout listener, all four `i18n` folders (label, tooltip, options) **and** the settings tables in [INSTALLATION.md](INSTALLATION.md#settings).
- The module id `SupertextTranslation`, the action type `supertextTranslate`, the connection type `supertext` and the field names `supertext*` are stored in users' databases; renaming them is a breaking change.
- Keep the three docs in `docs/` current with every change (see `CLAUDE.md`).

## Known limitations / roadmap

- Reviewers' corrections (*Translation review*) reach AtroPIM only with the next translation, and only with *Overwrite existing translations* (no push, no change detection). The review service needs a live API key.
- Each run translates one record per language request; a mass action over many records takes a while (use *Execute in background*).
- Only multilingual text fields. Not yet: option labels of list fields, file names and descriptions, translatable labels of AtroCore's metadata.
- Source and target languages are set per action, not chosen by the editor when clicking (also for *Export for translation*: one action per language set or format).
- *Export for translation* exports one record per click (no mass export into one file yet).
- The language mapping's combo boxes follow AtroCore's languages: a language added under *Administration → Languages* gets its combo box after the metadata cache is rebuilt (AtroCore does that when languages change); a removed language's saved choice stays in the connection's `data` unused.
- Not on a package registry yet (installed from GitHub).
- Human (professional) translation orders are not supported yet (the WordPress plugin has them).
