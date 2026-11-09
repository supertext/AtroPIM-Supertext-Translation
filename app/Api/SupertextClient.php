<?php

/**
 * @package     Supertext Translation for AtroPIM
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace SupertextTranslation\Api;

use SupertextTranslation\Sdk\Http\HttpTransport;
use SupertextTranslation\Sdk\V1\Client;
use SupertextTranslation\Sdk\V1\HtmlDocument;
use SupertextTranslation\Sdk\V1\Protocol;
use SupertextTranslation\Sdk\V1\Segment;
use SupertextTranslation\Sdk\V1\SupertextException as SdkException;

/**
 * Supertext AI file translation API v1 (https://api.supertext.com/v1/), through the
 * Supertext PHP SDK bundled in lib/supertext-sdk (namespace SupertextTranslation\Sdk\V1).
 *
 * The SDK does the protocol (upload one HTML document, poll its status, download the
 * translation, delete the file), the auth header, HTTP 429 retries and the data-st-id
 * document. Language codes are sent as given: Settings resolves them (Languages, no guessing). This class keeps the module's interface: HTTP goes through a
 * transport callable (the SDK's CurlTransport, or a fake callable in tests), and SDK errors come out
 * as this module's SupertextException with a key Translation\Messages translates.
 *
 * Has no AtroCore dependencies.
 */
final class SupertextClient
{
    public const LIVE = Protocol::LIVE_BASE_URL;
    public const STAGING = 'https://api.staging.supertext.com/v1/';
    public const TESTING = 'https://api.testing.supertext.com/v1/';

    /**
     * Supertext's review service (translation proxy of Supertext Localization Review). It speaks the
     * same API and forwards to the live Supertext API, keeping every translation so reviewers can
     * correct them on the website. Used for target languages with *Translation review* on.
     */
    public const REVIEW_URL = 'https://localizationreview-production.up.railway.app/v1/';

    /** The User-Agent's name; the module's version follows it ("AtroPIM-Supertext-Translation/0.2.0"). */
    public const USER_AGENT = 'AtroPIM-Supertext-Translation';

    /** Stay well below the API's 1,000,000 character limit per document. */
    public const MAX_DOCUMENT_CHARACTERS = Protocol::DEFAULT_MAX_CHARACTERS;

    /**
     * SDK error code → [key, English message]. The UI shows the key's text
     * (Action.json in app/Resources/i18n, see Translation\Messages) instead of the message.
     */
    private const KEYS = [
        SdkException::MISSING_API_KEY => ['no_api_key', 'No Supertext API key is configured.'],
        SdkException::AUTHENTICATION_FAILURE => ['auth_failed', 'Authentication failed. Please check the Supertext API key.'],
        SdkException::INVALID_LANGUAGE_PAIR => ['invalid_language_pair', 'Supertext does not support this language pair (%s → %s).'],
        SdkException::UNSUPPORTED_LANGUAGE => ['unsupported_language', 'The language %s has no Supertext language yet. Choose one for it in the Supertext connection (Administration → Connections).'],
        SdkException::UNSUPPORTED_FEATURE => ['unsupported_feature', 'Supertext offers no formal or informal tone for this language. Set the form of address to Default.'],
        SdkException::NOT_FOUND => ['not_found', 'The requested Supertext resource was not found.'],
        SdkException::PAYLOAD_TOO_LARGE => ['too_large', 'The content is too large for Supertext to translate in one go.'],
        SdkException::TOO_MANY_REQUESTS => ['rate_limited', 'Too many requests to Supertext. Please try again shortly.'],
        SdkException::SERVICE_UNAVAILABLE => ['unavailable', 'The Supertext service is currently unavailable.'],
        SdkException::UNEXPECTED_STATUS => ['http_status', 'Supertext answered with HTTP %s.'],
        SdkException::TRANSPORT_ERROR => ['unreachable', 'The Supertext service could not be reached.'],
        SdkException::NO_FILE_ID => ['no_file_id', 'Supertext did not return a file id.'],
        SdkException::TRANSLATION_ERROR => ['failed', 'Supertext could not translate the document.'],
        SdkException::QUOTA_EXCEEDED => ['limit_exceeded', 'Your Supertext translation limit is exceeded. Please upgrade your subscription.'],
        SdkException::FILE_DELETED => ['deleted', 'The document was deleted at Supertext before it could be downloaded.'],
        SdkException::TIMEOUT => ['timeout', 'Timed out waiting for the Supertext translation.'],
        SdkException::INCOMPLETE_RESPONSE => ['empty', 'The translated document was empty.'],
    ];

    private readonly Client $sdk;
    private readonly string $apiKey;

    /** @var (\Closure(): self)|null builds the client for the review service (null: none) */
    private readonly ?\Closure $makeReviewClient;

    private ?self $reviewClient = null;

    /**
     * @param HttpTransport|callable(string $method, string $url, array<string, string> $headers, ?string $body): array{status: int, body: string, headers: array<string, string>} $transport
     * @param callable(float $seconds): void|null $sleep tests: the SDK's clock then counts the slept time
     * @param string $reviewUrl     the review service for languages with Translation review ('' = none)
     * @param string $pluginVersion the module's version for the User-Agent ('' = "dev")
     */
    public function __construct(
        string $apiKey,
        string $baseUrl,
        HttpTransport|callable $transport,
        private readonly int $pollTimeout = 180,
        float $pollInterval = 2.0,
        ?callable $sleep = null,
        string $reviewUrl = '',
        string $pluginVersion = '',
    ) {
        $this->apiKey = self::normalizeKey($apiKey);
        $baseUrl      = $baseUrl !== '' ? $baseUrl : self::LIVE;
        $reviewUrl    = trim($reviewUrl);

        $this->makeReviewClient = $reviewUrl === '' || rtrim($reviewUrl, '/') === rtrim($baseUrl, '/')
            ? null
            : static fn (): self => new self($apiKey, $reviewUrl, $transport, $pollTimeout, $pollInterval, $sleep, '', $pluginVersion);

        $elapsed = 0.0;
        $sdkSleep = $sleep === null ? null : static function (float $seconds) use ($sleep, &$elapsed): void {
            $elapsed += $seconds;
            $sleep($seconds);
        };
        $clock = $sleep === null ? null : static function () use (&$elapsed): float {
            return $elapsed;
        };

        $this->sdk = new Client(
            $this->apiKey,
            baseUrl: $baseUrl,
            pollInterval: $pollInterval,
            timeout: (float) $pollTimeout,
            requestTimeout: 60.0,
            // The module's version too, so Supertext and the review service see which release calls.
            userAgent: self::USER_AGENT . '/' . ($pluginVersion !== '' ? $pluginVersion : 'dev'),
            transport: $transport instanceof HttpTransport ? $transport : new CallableTransport($transport),
            sleep: $sdkSleep,
            clock: $clock,
        );
    }

    /** Supertext shows the key as "Supertext-Auth-Key <key>"; accept it with or without that prefix. */
    public static function normalizeKey(string $key): string
    {
        return (string) preg_replace('/^Supertext-Auth-Key\s+/i', '', trim($key));
    }

    public static function baseUrlFor(string $environment, string $customEndpoint = ''): string
    {
        if (trim($customEndpoint) !== '') {
            return trim($customEndpoint);
        }

        return match ($environment) {
            'staging' => self::STAGING,
            'testing' => self::TESTING,
            default   => self::LIVE,
        };
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * The client for a target language: the review service when its *Translation review* is on
     * (same key, timeouts, transport and User-Agent; created once), else this one. Without a review
     * URL, always this one. A document is uploaded, polled, downloaded and deleted by the same client.
     */
    public function forReview(bool $review): self
    {
        if (!$review || $this->makeReviewClient === null) {
            return $this;
        }

        return $this->reviewClient ??= ($this->makeReviewClient)();
    }

    /**
     * Translates a complete HTML document and returns the translated HTML.
     *
     * @param string $targetLanguage a Supertext target code, sent as it is (e.g. "de-CH", "es"; see Settings::languageCode())
     * @param string $sourceLanguage a Supertext source code, sent as it is (e.g. "en"). Empty: auto-detect.
     * @param string $politeness     "default", "more" (formal) or "less" (informal)
     */
    public function translateDocument(string $html, string $targetLanguage, string $sourceLanguage = '', string $politeness = 'default'): string
    {
        if (\function_exists('set_time_limit')) {
            @set_time_limit($this->pollTimeout + 60);
        }

        return $this->call(fn (): string => $this->sdk->translateHtml($html, $targetLanguage, $sourceLanguage !== '' ? $sourceLanguage : null, $politeness));
    }

    /**
     * Translates keyed segments, packed into as few documents as the size limit allows.
     * A segment missing from Supertext's answer is left out of the result.
     *
     * @param array<int|string, array{text: string, html: bool}> $segments
     * @return array<int|string, string> segment key => translation
     */
    public function translateSegments(array $segments, string $targetLanguage, string $sourceLanguage = '', string $politeness = 'default', int $maxCharacters = self::MAX_DOCUMENT_CHARACTERS): array
    {
        $toSend = [];
        foreach ($segments as $key => $segment) {
            $item = $segment['html'] ? Segment::html($segment['text']) : Segment::text($segment['text']);
            if (!$item->isBlank()) {
                $toSend[$key] = $item;
            }
        }

        $result = [];
        foreach (Client::batches($toSend, $maxCharacters) as $batch) {
            $keys = array_keys($batch);
            $list = array_values($batch);
            $html = $this->translateDocument(HtmlDocument::build($list), $targetLanguage, $sourceLanguage, $politeness);
            foreach (HtmlDocument::parse($html, $list) as $index => $text) {
                if ($text !== null) {
                    $result[$keys[$index]] = $text;
                }
            }
        }

        return $result;
    }

    /** Cost-free check that the key is accepted. */
    public function validateApiKey(): void
    {
        $this->call(fn () => $this->sdk->validateApiKey());
    }

    /** Seconds to wait before retry $attempt (0-based): Retry-After if sent, else 1, 2, 4, 8 plus jitter. */
    public static function retryDelay(int $attempt, string $retryAfter = ''): float
    {
        return Protocol::retryDelayMs($attempt, $retryAfter !== '' ? $retryAfter : null) / 1000;
    }

    /**
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function call(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (SdkException $e) {
            throw self::fromSdk($e);
        }
    }

    /** An SDK error as this module's exception, with the key and params Translation\Messages shows. */
    public static function fromSdk(SdkException $e): SupertextException
    {
        [$key, $message] = self::KEYS[$e->errorCode] ?? ['', $e->getMessage()];
        $detail = (string) $e->detail;
        $params = match ($e->errorCode) {
            SdkException::UNEXPECTED_STATUS => ['status' => (int) $e->status],
            SdkException::INVALID_LANGUAGE_PAIR => ['source' => (string) $e->sourceLang ?: '?', 'target' => (string) $e->targetLang ?: '?'],
            SdkException::UNSUPPORTED_LANGUAGE => ['language' => (string) ($e->targetLang ?? $e->sourceLang) ?: '?'],
            default => [],
        };
        $message = $params === [] ? $message : sprintf($message, ...array_values($params));
        if ($detail !== '') {
            $message .= ' (' . $detail . ')';
        }

        return new SupertextException($message, (int) $e->status, $e, $key, $params, $detail);
    }
}
