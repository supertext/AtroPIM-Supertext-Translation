# Working on this repository

Part of Supertext's translation plugins project: Supertext AI translation for the top open source CMS, plus PIM systems (Akeneo, AtroPIM, Pimcore). Each system has its own repo named `Supertext/<System>-Supertext-Translation`. This one is the **AtroPIM** module (AtroCore 2.4 / AtroPIM 1.16, PHP 8.4; Composer package `supertext/atropim-supertext-translation`, AtroCore module id and namespace `SupertextTranslation`).

## Documentation rule (always)

Every plugin repo keeps three guides, and **every change that affects behaviour, settings, installation or the code structure updates them in the same commit**:

| File | Audience | Must cover |
| --- | --- | --- |
| `docs/INSTALLATION.md` | Administrators | Requirements, install/update/uninstall, API key, language setup, all settings, troubleshooting |
| `docs/USER_GUIDE.md` | Editors | How to translate and review in the CMS's own UI, what is and isn't translated, what errors mean |
| `docs/DEVELOPER.md` | Developers | Architecture, Supertext API protocol, local setup, tests, CI/deploy, releasing, known limitations/roadmap |

Also: `README.md` stays a short overview linking the three guides, and `CHANGELOG.md` gets an entry under *Unreleased* for every user-visible change. Before finishing any task, check the docs still match the code.

## Supertext account and API key links (always)

Everywhere an administrator enters or is told about the API key — the settings field's help text, the "no API key" / "authentication failed" messages, `docs/INSTALLATION.md`, `README.md` and the demo's `.env.example` — show both links (same as the WordPress plugin):

- Create a Supertext account (or log in): https://www.supertext.com/person/en/account/signin
- Generate the AI API key: https://www.supertext.com/en/integrations/api (supertext.com → Integrations → API; requires the **Admin** role)

Wording: "No Supertext account yet? Create one at supertext.com. Generate your API key at supertext.com → Integrations → API (requires the Admin role)." In the UI, links open in a new tab (`target="_blank" rel="noopener"`); where the CMS shows plain text only, use the bare URLs.

## UI languages (always)

The plugin's own UI (buttons, panels, dialogs, settings, permissions, messages) is available in English, German, French and Italian through the CMS's own translation mechanism, so it follows the user's back-end language. New or changed strings get all four languages in the same commit. Formal address (Sie, vous, Lei), the CMS's own terms in each language, "Supertext", placeholders and URLs never translated.

## Plugin version on the settings screen (always)

Where the CMS doesn't show the plugin's version itself, the plugin's own settings or status screen does (CLI-only plugins print it in their check command). It is read at runtime from the official version source (see *Releases*), never a second hardcoded copy, and links to the GitHub release when it is an X.Y.Z version.

## Plugin list (always)

Every plugin repo's `README.md` ends with the same list of all Supertext plugins, between the `<!-- supertext-plugins:start -->` and `<!-- supertext-plugins:end -->` markers (just before *License* if there is one). It has two tables, **Content management systems (CMS)** and **Product information management (PIM)**, sorted alphabetically. Each row has the system, the link, one sentence on the **type of integration** (plugin, extension, module, bundle, package, connector service, …, and how it's installed or hooked in) and what it does. Plugins still being built are listed with *In development*; replace that with the real description when the plugin works.

When a plugin is added, renamed or its description changes, update the list here **and** in every repo: all `*-Supertext-Translation` repos (WordPress: `Wordpress-Polylang-Supertext-Translation`) and `supertext-aem-connector`. A new plugin adds its own row and gets the list in its README from the start (as of October 2026 the Pimcore repo doesn't have it yet: add it when its README is written). Drupal's module lives on drupal.org (maintained by MD Systems), so it is listed but doesn't carry the list.

Current block (copy exactly, between the markers):

```markdown
## Supertext plugins for other systems

Supertext offers AI and professional translation plugins for these systems:

### Content management systems (CMS)

| System | Plugin | Type of integration | What it does |
| --- | --- | --- | --- |
| Adobe Experience Manager | [supertext-aem-connector](https://github.com/Supertext/supertext-aem-connector) | Translation connector: two AEM content packages for AEM's Translation Integration Framework. | Sends AEM translation projects to Supertext and imports the results |
| Contao | [Contao-Supertext-Translation](https://github.com/Supertext/Contao-Supertext-Translation) | Contao bundle (Composer) that adds a back-end action. | *Translate with Supertext* in the site structure: pages or whole websites into other languages |
| Craft CMS | [CraftCms-Supertext-Translation](https://github.com/Supertext/CraftCms-Supertext-Translation) | Craft plugin (Composer) with a panel on the entry page. | Translates entries into your other sites, Matrix and rich text included |
| Directus | [Directus-Supertext-Translation](https://github.com/Supertext/Directus-Supertext-Translation) | Directus extension bundle (npm): interface, endpoint, Flow operation and module. | *Translate with Supertext* box on the item form, fills the Translations field |
| django CMS | [djangoCMS-Supertext-Translation](https://github.com/Supertext/djangoCMS-Supertext-Translation) | Django app (Python package) that adds a toolbar entry. | Translates pages and their plugins from the toolbar |
| Drupal | [tmgmt_supertext_ai](https://www.drupal.org/project/tmgmt_supertext_ai) | Drupal module: a translator provider for the Translation Management Tool (TMGMT), by MD Systems. | Translates TMGMT jobs with Supertext AI |
| Ghost | [Ghost-Supertext-Translation](https://github.com/Supertext/Ghost-Supertext-Translation) | Separate connector service (Ghost has no admin plugins): works through internal tags, webhooks and the Admin API. | Tag a post `#translate-…` and a translated draft appears |
| Grav | [Grav-Supertext-Translation](https://github.com/Supertext/Grav-Supertext-Translation) | Grav 2 plugin with an Admin2 panel. | Supertext panel in the page editor, Markdown kept intact |
| Joomla | [Joomla-Supertext-Translation](https://github.com/Supertext/Joomla-Supertext-Translation) | Joomla system plugin (installable package). | Translates articles into linked, unpublished language versions |
| Magnolia | [Magnolia-Supertext-Translation](https://github.com/Supertext/Magnolia-Supertext-Translation) | Magnolia module (Maven jar) that adds an action to the Pages app and a settings app. | *Translate with Supertext* in the page list and page editor: pages and their components into the site's languages |
| Neos | [Neos-Supertext-Translation](https://github.com/Supertext/Neos-Supertext-Translation) | Neos package (Composer) that hooks into the content repository; no new UI. | Translates automatically when an editor creates a page in another language |
| Orchard Core | [OrchardCore-Supertext-Translation](https://github.com/Supertext/OrchardCore-Supertext-Translation) | Orchard Core module (.NET) with an admin page and a localization hook. | Translates content items into other cultures, on demand or on localization |
| Payload CMS | [Payload-Supertext-Translation](https://github.com/Supertext/Payload-Supertext-Translation) | Payload plugin (npm) added to `payload.config`. | *Translate* button for localized collections and globals |
| Silverstripe | [Silverstripe-Supertext-Translation](https://github.com/Supertext/Silverstripe-Supertext-Translation) | Silverstripe module (Composer) on top of Fluent. | Supertext tab translates pages and Elemental blocks into Fluent locales |
| Strapi | [Strapi-Supertext-Translation](https://github.com/Supertext/Strapi-Supertext-Translation) | Strapi 5 plugin (npm) with a Content Manager panel. | Translates entries into other locales from the Content Manager |
| TYPO3 | [Typo3-Supertext-Translation](https://github.com/Supertext/Typo3-Supertext-Translation) | TYPO3 extension (Composer) that hooks into TYPO3's own localization; no new UI. | Translates pages and content elements as editors localize them |
| Umbraco | [Umbraco-Supertext-Translation](https://github.com/Supertext/Umbraco-Supertext-Translation) | Umbraco package (NuGet) with a backoffice extension. | *Translate with Supertext* for pages, block lists and grids included |
| Wagtail | [Wagtail-Supertext-Translation](https://github.com/Supertext/Wagtail-Supertext-Translation) | Python package: a machine translator for wagtail-localize. | Translates pages and snippets inside wagtail-localize's editor |
| WordPress (Polylang) | [Wordpress-Polylang-Supertext-Translation](https://github.com/Supertext/Wordpress-Polylang-Supertext-Translation) | WordPress plugin: a machine-translation service for Polylang Pro, plus professional translation orders. | AI translation next to DeepL in Polylang, and human translation orders |

### Product information management (PIM)

| System | Plugin | Type of integration | What it does |
| --- | --- | --- | --- |
| Akeneo PIM | [Akeneo-Supertext-Translation](https://github.com/Supertext/Akeneo-Supertext-Translation) | Symfony bundle (Composer) for the Community Edition, with an action on the product edit form and a System page. | *Translate with Supertext* for products and product models, into your other locales |
| AtroPIM | [AtroPIM-Supertext-Translation](https://github.com/Supertext/AtroPIM-Supertext-Translation) | AtroCore module (Composer) that adds an action type and a Supertext connection type. | *Translate with Supertext* button and mass action for products and other records, into your other languages |
| Pimcore | [Pimcore-Supertext-Translation](https://github.com/Supertext/Pimcore-Supertext-Translation) | Pimcore bundle (Composer) with a Pimcore Studio panel. | *In development:* translates documents and data objects into the other languages |
```

## Releases (always)

Every CMS plugin repo has `.github/workflows/release.yml` (since v0.1.0, October 2026). It publishes a GitHub release only when the version is officially bumped: a new `## X.Y.Z — YYYY-MM-DD` (or `## [X.Y.Z] - YYYY-MM-DD` in Keep-a-Changelog repos) section at the top of `CHANGELOG.md`, below an empty *Unreleased*, with every file in the workflow's `VERSION_FILES` carrying the same number. Then it tags `vX.Y.Z`, creates the release with that CHANGELOG section as notes (0.x as pre-releases) and attaches the installable file where there is one (Joomla `plg_system_supertext-X.Y.Z.zip` via `./build.sh`, Grav `supertext-translation-X.Y.Z.zip`, Magnolia `magnolia-supertext-translation-X.Y.Z.jar` via Maven). Pushes without a new version release nothing; released versions are skipped; a mismatching version file fails the run. Never tag or create releases by hand (Claude sessions can't anyway: HTTP 403). Each repo's `docs/DEVELOPER.md` → *Releasing* lists its steps. New plugins (Pimcore, …) copy the workflow from an existing repo and set `VERSION_FILES`.

Official version files (where each CMS reads the version):

| Plugin | Version file(s) | Notes |
| --- | --- | --- |
| TYPO3 | `ext_emconf.php` | shown in the extension manager |
| Joomla | `plugin/supertext.xml`, `plugin/media/joomla.asset.json` | XML version shown in the extension manager |
| Grav | `blueprints.yaml` | shown in the admin's plugin list |
| Umbraco | `.csproj` (NuGet), `umbraco-package.json` | package version shown under Settings → Packages |
| Orchard Core | `.csproj` (NuGet), `Manifest.cs` | also shown on the Supertext settings page |
| Magnolia | `pom.xml`, `demo/pom.xml` (`<version>` and `<supertext.version>`) | the module descriptor gets it at build time; the Supertext app shows the jar's `Implementation-Version` |
| Directus, Strapi, Payload, Ghost | `package.json` | Directus, Strapi and Ghost show it on their Supertext page |
| django CMS, Wagtail | `<package>/__init__.py` `__version__` | shown on the Supertext settings page |
| Akeneo PIM, AtroPIM, Contao, Craft CMS, Neos, Silverstripe | none: the Git tag | Composer takes the version from the tag; don't add `version` to `composer.json`. Silverstripe shows it in the Supertext section, Neos in `supertext:check`, Akeneo on System → Supertext and in `supertext:check`, AtroPIM under Administration → Updates & Modules and in `supertext check` |

## Repo setup (always)

Every Supertext plugin repo has, and a new one gets from the start:

- `LICENSE` matching the license its manifest declares (`composer.json`, `package.json`, `pyproject.toml`, `.csproj`, plugin header).
- `SECURITY.md`: report vulnerabilities privately through GitHub's private vulnerability reporting or support@supertext.com, never in public issues.
- `.github/dependabot.yml`: weekly updates for its package ecosystem and GitHub Actions, minor and patch updates grouped into one pull request.
- `.github/workflows/checks.yml` (actionlint + zizmor on every push and PR, dependency review on PRs) and `.github/workflows/links.yml` (lychee weekly and on docs changes; broken links open the issue "Broken links in the docs"). Third-party actions are pinned to commit SHAs.
- `.github/workflows/dependabot-merge.yml`: merges Dependabot's minor and patch package updates once all other checks pass (built-in token, so GitHub Actions updates and majors stay for a person). See `docs/DEVELOPER.md` → *Dependency updates*.
- PHP repos: PHPStan in CI (`phpstan.neon`, baseline in `phpstan-baseline.neon`).
- On GitHub: the About box filled in (one-sentence description, website https://www.supertext.com, topics), `main` protected against force-pushes and deletion, Wiki and Projects off, Dependabot alerts and private vulnerability reporting on, secret scanning with push protection and CodeQL default setup on, and the Supertext social preview image.
- A row in the plugin list (see *Plugin list*) and in the org profile (`Supertext/.github` → `profile/README.md`).

Claude sessions can't change GitHub repo settings (HTTP 403): add a new repo to Remy's setup script (`set-github-about`) instead of trying.

## Checks and alerts (always)

Before starting work in a repo, look at its open findings and fix what the task touches or what is quick: code scanning alerts (`gh api 'repos/Supertext/<Repo>/code-scanning/alerts?state=open'`), secret scanning alerts (`…/secret-scanning/alerts?state=open`), open Dependabot PRs and the issue "Broken links in the docs". New workflows and workflow changes must pass actionlint and zizmor; PHP code must pass PHPStan at the repo's level. See `docs/DEVELOPER.md` → *Code quality and security checks*.

## Demo accounts rule (always)

Every demo must be usable right after deployment, without anyone registering in a browser. On **every start**, the demo creates these accounts if they don't exist yet:

| Variables | Account |
| --- | --- |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Full administrator (for Supertext staff) |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Editor-level account that can translate content in every demo language; used for automated tests and screenshots. Where the CMS has no editor role that works out of the box, use the closest role and document it. |

- Existing accounts are never modified: no password resets from variables, no duplicates on restart.
- A password that doesn't meet the CMS's own password rules skips that account with a clear warning in the log. The demo still starts.
- Values live only in the hosting platform's variables (Railway). Never in the repo, in chat or in logs. Log the variable name, never the password.
- If the CMS has a first-run "create admin" screen, these accounts replace it. Document that once `DEMO_*` is set, the screen no longer appears.
- If a demo already used CMS-specific names (e.g. `TYPO3_ADMIN_*`, `PAYLOAD_ADMIN_*`), keep them as fallbacks for `DEMO_ADMIN_*`.
- The demo also seeds its target languages and at least one sample entry in the source language, and makes sure the editor account can access every target language.
- Document the variables in `docs/DEVELOPER.md` (demo section) and in the demo's `.env.example`.

## Screenshots rule (always)

The user guide and installation guide of every plugin include screenshots of the real UI: at least the translate action before and after translating, a translated result, the overwrite or retranslate warning if there is one, the plugin's settings or configuration screen, and the CMS's language setup. Screenshots are taken from the repo's own demo with the headless browser, by a committed script (e.g. `npm run docs:screenshots`), against a stand-in API that returns real translations for the sample content, so the guides never show placeholder text. Use no real customer data, no secrets, no local URLs (show the live API endpoint). Keep the images small (1× scale, cropped to the relevant part), store them in `docs/images/`, give each one descriptive alt text, and regenerate them in the same commit whenever the UI they show changes.

## Shared Supertext protocol

AI file translation API v1, same as the WordPress plugin: POST HTML file → poll status → GET translation → DELETE. Details in `docs/DEVELOPER.md`. Never commit API keys; use the `SUPERTEXT_API_KEY` environment variable or the CMS's settings.

Lessons from testing against the live API (October 2026), to apply in every plugin:

- **Auth header:** `Authorization: Supertext-Auth-Key <key>`. The header name must be `Authorization` (`Authentication` gets 403; no prefix gets 400). Supertext shows the key with the prefix, so strip a pasted `Supertext-Auth-Key ` and always send exactly one.
- **Rate limit:** the API limits requests per second per key (HTTP 429, `RATE_LIMIT_EXCEEDED`). Translating into several languages at once hits it. Retry a 429 up to 4 times (`Retry-After`, else 1/2/4/8 s with jitter).
- **Rich text:** each element carrying `data-st-id` is translated on its own. Send a whole paragraph (heading, list item) as **one** `data-st-id` element with formatting and links as inline tags (`<b>`, `<i>`, `<a href>`), and map them back to the CMS's rich-text nodes. Never give each formatted run its own `data-st-id`: sentences break at the formatting (lower-case starts, words moved outside the tags).
- **Language codes:** no guessing beyond the SDK's defaults. Supertext accepts only the codes of `GET features` (the SDK's `Languages::TARGETS`): targets are regional only for de/en/fr/it/pt (`de-CH`, `en-US`, `pt-BR`), `zh-Hans`/`zh-Hant`, `sr-Latn`/`sr-Cyrl`, `gsw-u-sd-chzh`/`chbe`, and bare for all others (`es`, `nl`, `sl`); sources are bare (`en`). Resolve every CMS language with `Languages::target()`/`source()` and the plugin's mapping (a combo box per AtroCore language on the Supertext connection, `supertextCode<Language>` enum fields from `app/Translation/LanguageMapping.php`, options `targetOptions()`, preselected with `suggestTarget()`; lines of the old text field `supertextLanguageCodes` still count and are migrated into them), else an exact match (case and `_`/`-` ignored), else the SDK default (de→de-DE, en→en-US, fr→fr-FR, it→it-IT, pt→pt-PT, zh_CN→zh-Hans, zh_TW→zh-Hant, es_ES→es …), else `unsupported_language` (nothing sent; de_LI, en_IE, fr_BE, gsw need a choice). See `docs/DEVELOPER.md` → *Language codes*.

## Demo and CI lessons (October 2026)

- **Railway builds from a `git archive` snapshot**, so `.gitattributes` `export-ignore` applies: never export-ignore `demo/` or anything the Dockerfile copies, or Railway reports "couldn't locate the dockerfile".
- **PHP + Apache images on Railway:** remove `mpm_event`/`mpm_worker` from `mods-enabled` in the entrypoint as well as in the Dockerfile, or Apache fails with "More than one MPM loaded".
- **Railway's GitHub access:** configured at https://github.com/organizations/Supertext/settings/installations → Railway → Configure. If a repo's pushes don't deploy, check that page; after its access changes, reconnect the service's source once (connect-service-source) so Railway re-creates its push trigger.
- **Demo-check scripts with `set -o pipefail`:** never `docker logs … | grep -q`. grep closes the pipe early, the pipeline fails with SIGPIPE (141), and the password-leak check can pass although a password is in the log. Capture the log first (`logs=$(docker logs demo 2>&1)`, then `grep -qF … <<< "$logs"`), or grep without `-q`.
- **Railway databases:** Postgres and MySQL services are shared; each demo uses its own database on them (`<CMS>_DB_NAME`). Silverstripe 6 has no PostgreSQL adapter, so it uses MySQL.
- **Checking releases from a Claude session:** `gh release view` uses GraphQL, which is blocked here; use `gh api repos/<owner>/<repo>/releases/tags/<tag>`. The Actions API is blocked too.
- **Docker builds in a Claude session:** `npm ci` / `pip install` inside `docker build` can't reach the registries through the proxy, and Docker Hub rate-limits pulls (`mirror.gcr.io` works). For screenshots, run the demo directly on the machine or build the image from prebuilt output.
- **Docker builds through the session proxy (Akeneo, October 2026):** containers trust the proxy only with its CA (`/root/.ccr/ca-bundle.crt`) baked into a local copy of the base image and `--network host --build-arg HTTPS_PROXY=…` (not `HTTP_PROXY`: apt's plain-HTTP mirrors then fail with 405). PECL downloads and GitHub archive/API downloads (Composer dists) still fail; clone with git on the host instead (`composer install --prefer-source`, then copy `vendor/` into a local-only Dockerfile variant). Elasticsearch on the session machine needs `cluster.routing.allocation.disk.threshold_enabled=false`.
- **Java/Maven (Magnolia, October 2026):** Maven on the session machine reaches Magnolia's Nexus and the Vaadin add-ons repository; build the war on the host and run it in the stock Tomcat image (mount the exploded webapp) instead of building the image. Magnolia webapps must import the `magnolia-bundle-parent` BOM, or Maven mixes Magnolia versions and Magnolia refuses to start.
- **AtroCore (AtroPIM, October 2026):** AtroCore's installer empties its database, so a demo gets its own database and refuses shared names. Console subprocesses use `$_` as PHP binary unless `PHP_PATH` is set (wrong under `runuser`). `createEntity()` of a record service returns the id, not the entity. A record created in the same PHP process ignores a second `updateEntity()` with attribute values (`NotModified`): pass `__attributes` and the values on create, and add languages/attributes in a separate process from the records that use them.

## This repo

- Before committing: `phpunit` (PHPUnit 11; no AtroCore install needed) and PHP lint (`find app tests demo -name '*.php' -print0 | xargs -0 -n1 php -l`). CI also runs PHPStan (level 5, needs AtroCore's classes: see `docs/DEVELOPER.md` → *Code quality and security checks*), builds the demo image and runs `tests/demo-check.sh` against MySQL 8.4 and the stand-in.
- AtroCore loads the module by convention from `composer.json` → `extra.atroId` (`SupertextTranslation`): `app/Module.php`, `app/Listeners/<Target>.php` (the file name is the event target: `Metadata`, `Language`, `ActionLayout`, `ConnectionLayout`, `ActionService`, `ConnectionService`), `app/Handlers/` (routes from `#[Route]`), `app/Resources/metadata/`, `app/Resources/i18n/`.
- The UI is AtroCore's own: an action type (`app/ActionTypes/SupertextTranslate.php`) and a connection type (`app/ConnectionType/ConnectionSupertext.php`); there is no custom front end. New options go in `app/Resources/metadata/entityDefs/Action.json` or `Connection.json`, the layout listener, all four `i18n` folders (`en_US`, `de_DE`, `fr_FR`, `it_IT`: label, tooltip, options) **and** the settings tables in `docs/INSTALLATION.md`. Messages editors see go through `app/Translation/Messages.php` with their texts in `i18n/*/Action.json` → `messages` (`InterfaceLanguagesTest` checks all four).
- Export for translation / Import translation (XLIFF and HTML, SDK `TranslationFiles`): `app/Translation/TranslationFileExchange.php` (no AtroCore classes; reference `<Entity>:<id>:<source>:<target>`, plugin `AtroPIM-Supertext-Translation`), glue in `AtroRecordSource.php`, `ImportPage.php`, the action types `supertextExport`/`supertextImport` and the routes `/api/supertext/export` and `/api/supertext/import`; see `docs/DEVELOPER.md` → *Translation files*.
- *Translation review* is a checkbox per language next to its combo box on the Supertext connection (`supertextReview<Language>`, `LanguageMapping::reviewField()`, `Settings::review()`); review service `supertextReviewUrl` / `SUPERTEXT_REVIEW_URL` (default `SupertextClient::REVIEW_URL`, '' = off); `EntityTranslator` uses `SupertextClient::forReview()`. Key check, reports and translation files never use it. See `docs/DEVELOPER.md` → *Supertext API protocol*.
- Field rules live in `app/Translation/Planner.php` and `EntityTranslator.php`; keep "Field rules" in `docs/DEVELOPER.md` and "What is translated" in `docs/USER_GUIDE.md` in sync.
- Keep `app/Api/`, `Planner`, `TextUnit`, `Settings`, `LanguageMapping`, `Messages`, `Reporting` and `CommandArguments` free of AtroCore classes (unit tests run without AtroCore).
- Commando Center: version reports, the automatic API key check and the public ping `GET /api/supertext/ping` go through Supertext Plugin Health's `InstallationReporter` with the SDK's key check (`app/Translation/Reporting.php`, AtroCore glue in `ReportingFactory.php`, state in AtroCore's config key `supertextReporter`); nothing about reporting may fail a save or a translation. Local tests set `SUPERTEXT_REPORT_URL` to the stand-in (`/report`), never the live center. See `docs/DEVELOPER.md` → *Commando Center*.
- All Supertext API calls go through the Supertext PHP SDK, shipped in `lib/supertext-sdk` with its namespace changed to `SupertextTranslation\Sdk` and wrapped by `app/Api/SupertextClient.php`. Never edit `lib/supertext-sdk`: fix the SDK in `Supertext/Supertext-API-SDK-and-Guide` and run `scripts/vendor-sdk.sh`. Every SDK error code needs a key in `SupertextClient::KEYS` and its text in all four `i18n/*/Action.json`. The demo Dockerfile copies `lib/`.
- The Commando Center reporter is Supertext Plugin Health, shipped in `lib/supertext-plugin-health` with its namespace changed to `SupertextTranslation\PluginHealth`. Never edit `lib/supertext-plugin-health`: fix it in `Supertext/Commando-Center-Supertext-Translation` → `plugin-health/` and run `scripts/vendor-plugin-health.sh <tag>`. See `docs/DEVELOPER.md` → *Supertext Plugin Health*.
- The module id `SupertextTranslation`, the action type `supertextTranslate`, the connection type `supertext` and the `supertext*` field names are stored in users' databases; renaming them is a breaking change.
- `demo/` is the Railway demo (`railway.json` → `demo/Dockerfile`, context = repo root; volume at `/data` for AtroCore's `data/` and `upload/`). Demo-only code is `demo/docker/install.php` and `setup.php`; they only create what's missing. Demo secrets live only in Railway variables. `docker exec demo demo-console supertext check` runs console commands as www-data.
- Test UI changes in the demo and regenerate the screenshots they affect (`tests/docs/screenshots.mjs`, against a fresh demo and `tests/docs/stand-in.mjs`; new demo content needs entries in `tests/docs/samples.json`).
- Movies (`docs/DEVELOPER.md` → *Demo movie*): `tests/docs/movie.mjs` (`npm run movie`) records the README's `docs/images/demo.gif` and a voiced MP4, `tests/docs/movie-install.mjs` (`npm run movie:install`) the install and configuration movie (MP4 only); both with the kit in `tests/docs/movie/`, against a fresh demo and the stand-in. Re-record when the screens they show change; MP4s aren't committed.
