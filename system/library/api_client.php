<?php
/**
 * Thin cURL-based HTTP client for the TackQuote API.
 *
 * Mirrors Tack_Api_Client from the TackQuote WooCommerce plugin
 * (integrations/wordpress/tack-quotes/includes/class-tack-api-client.php)
 * and TackApiClient from the PrestaShop module
 * (integrations/prestashop/modules/tackquotes/classes/TackApiClient.php):
 * same headers, same auth scheme (Bearer + X-Api-Key), same JSON contract.
 *
 * Autoloading (verified against OpenCart 4.0.2.3 source, not assumed):
 *   - `catalog/controller/startup/extension.php` registers
 *     `Opencart\System\Library\Extension\<Code>` => `extension/<code>/system/library/`
 *     for every installed extension, so the namespace below is the one OpenCart
 *     actually resolves for an extension-shipped library.
 *     https://github.com/opencart/opencart/blob/4.0.2.3/upload/catalog/controller/startup/extension.php
 *   - `system/engine/autoloader.php` maps the remainder of the class name to a
 *     file with `strtolower(preg_replace('~([a-z])([A-Z]|[0-9])~', '\1_\2', …))`,
 *     i.e. `ApiClient` => `api_client.php` — NOT `apiclient.php`. This file was
 *     previously named `apiclient.php`, which that rule can never find.
 *     https://github.com/opencart/opencart/blob/4.0.2.3/upload/system/engine/autoloader.php
 *
 * OpenCart 3.x does not autoload this namespace at all; see README.md
 * "OpenCart 3.x" for the adaptation an OC3 install requires.
 */

namespace Opencart\System\Library\Extension\Tack;

class ApiClient
{
    /** @var string */
    protected string $baseUrl;

    /** @var string */
    protected string $apiKey;

    /**
     * @param string $baseUrl e.g. https://api.tackquote.com/v1 (no trailing slash).
     * @param string $apiKey  Tenant's TackQuote API key.
     */
    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /** Outcomes of checkConnection(). */
    public const CONNECTION_OK = 'ok';
    public const CONNECTION_KEY_REJECTED = 'key_rejected';
    public const CONNECTION_KEY_UNCHECKED = 'key_unchecked';
    public const CONNECTION_FAILED = 'failed';
    public const CONNECTION_WRONG_ADDRESS = 'wrong_address';

    /**
     * Perform an authenticated request against the Tack API.
     *
     * @param string     $method HTTP method.
     * @param string     $path   Path beginning with '/'.
     * @param array|null $body   Optional JSON-encodable body.
     *
     * @return array|string Decoded response array on 2xx, or an error message string.
     */
    public function request(string $method, string $path, ?array $body = null)
    {
        if ($this->apiKey === '') {
            return 'No TackQuote API key configured.';
        }

        $result = $this->exchange($method, $path, $body);

        return $result['error'] !== null ? $result['error'] : $result['data'];
    }

    /**
     * The raw cURL call and nothing else: the one seam the tests replace, so every
     * byte after it (JSON decoding, status handling) is the shipped code.
     *
     * `error` is set when no HTTP answer arrived at all (DNS, refused, TLS, timeout).
     *
     * `$base` replaces the configured API URL (only the origin's own /health probe in
     * checkConnection() uses it); null = the API URL.
     *
     * @return array{error: string|null, status: int, body: string}
     */
    protected function transport(string $method, string $path, ?array $body = null, ?string $base = null): array
    {
        $url = ($base ?? $this->baseUrl) . $path;

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'X-Api-Key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: TackQuote-OpenCart/1.0.0',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return ['error' => sprintf('Connection error: %s', $error), 'status' => 0, 'body' => ''];
        }

        return ['error' => null, 'status' => $httpCode, 'body' => (string) $response];
    }

    /**
     * One HTTP exchange, keeping the status code that request() folds into a string.
     *
     * `status` is 0 when no HTTP answer arrived at all (DNS, refused, TLS, timeout).
     * `error` is null exactly when the status is 2xx.
     *
     * @return array{status: int, data: array, error: string|null}
     */
    protected function exchange(string $method, string $path, ?array $body = null, ?string $base = null): array
    {
        $raw = $this->transport($method, $path, $body, $base);
        if ($raw['error'] !== null) {
            return ['status' => 0, 'data' => [], 'error' => $raw['error']];
        }

        $httpCode = $raw['status'];
        $data = json_decode($raw['body'], true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = is_array($data) && isset($data['message'])
                ? (is_array($data['message']) ? implode(', ', $data['message']) : (string) $data['message'])
                : sprintf('TackQuote API returned HTTP %d.', $httpCode);

            return ['status' => $httpCode, 'data' => is_array($data) ? $data : [], 'error' => $message];
        }

        return ['status' => $httpCode, 'data' => is_array($data) ? $data : [], 'error' => null];
    }

    /**
     * Connectivity + API-key check for the admin "Test connection" button.
     *
     * Calls `GET /integrations/opencart/ping`, which TackQuote authenticates with the
     * store's API key. A 2xx carrying `"ok": true` is CONNECTION_OK: a lapsed TackQuote account still
     * answers 200 (with a reduced `capabilities` list), and its key is valid.
     *
     * A 2xx counts only when the body is the ping's own `{"ok": true}`.
     * 401 means TackQuote rejected the key: CONNECTION_KEY_REJECTED, no fallback. A 403
     * comes with a valid key (a plan limit, an unresolved account): CONNECTION_FAILED
     * with TackQuote's message, no fallback.
     * This used to fall back to `GET /health` on ANY ping failure, and /health answers
     * 200 without a key, so a wrong or revoked key reported "Connected".
     *
     * `/health` is tried only when the ping failure says nothing about the key: no HTTP
     * answer (network error, timeout), a 404 or a 5xx. A /health answer counts only when
     * it is TackQuote's own body (isTackQuoteHealth()); any other 2xx is CONNECTION_FAILED.
     * - No answer or 5xx, then TackQuote's /health: CONNECTION_KEY_UNCHECKED, never OK.
     * - 404: every current TackQuote API has the ping route, so a 404 means the request
     *   did not reach it. When TackQuote's /health answers at the origin (or at the API
     *   URL when that does not end in /v1) the API URL setting is wrong, usually missing
     *   `/v1`: CONNECTION_WRONG_ADDRESS. Only when the API URL already ends in /v1 and
     *   TackQuote's /health answers there is it a TackQuote API without the ping route:
     *   CONNECTION_KEY_UNCHECKED.
     * Every other status (400, 403, 429, ...) is CONNECTION_FAILED with TackQuote's message.
     *
     * @return array{result: string, message: string} `message` is the ping's own error
     *                                                text ('' on success).
     */
    public function checkConnection(): array
    {
        if ($this->apiKey === '') {
            return ['result' => self::CONNECTION_FAILED, 'message' => 'No TackQuote API key configured.'];
        }

        $ping = $this->exchange('GET', '/integrations/opencart/ping');
        $status = $ping['status'];

        if ($ping['error'] === null) {
            // The ping answers {"ok": true, "tenantId": ..., "capabilities": [...]}
            // (opencart-plugin.controller.ts). A 2xx without it is not TackQuote's
            // ping: a wrong API URL serving a web page must not read as connected.
            if (isset($ping['data']['ok']) && $ping['data']['ok'] === true) {
                return ['result' => self::CONNECTION_OK, 'message' => ''];
            }

            return [
                'result' => self::CONNECTION_FAILED,
                'message' => 'The TackQuote API URL answered, but not as the TackQuote API. Check the URL (default https://api.tackquote.com/v1).',
            ];
        }

        // A wrong, revoked or expired key is ALWAYS 401 `code: api_key_invalid`
        // (ApiKeyGuard). A 403 comes with a VALID key: a plan limit (PLAN_LIMIT_EXCEEDED,
        // API calls per month) or an account TackQuote cannot resolve. It falls through
        // to CONNECTION_FAILED with TackQuote's own message, and never to /health.
        if ($status === 401) {
            return ['result' => self::CONNECTION_KEY_REJECTED, 'message' => (string) $ping['error']];
        }

        if ($status === 404) {
            $atApiUrl = $this->isTackQuoteHealth($this->exchange('GET', '/health'));
            if ($atApiUrl && preg_match('~/v1$~i', $this->baseUrl)) {
                return ['result' => self::CONNECTION_KEY_UNCHECKED, 'message' => (string) $ping['error']];
            }
            if ($atApiUrl) {
                return ['result' => self::CONNECTION_WRONG_ADDRESS, 'message' => (string) $ping['error']];
            }
            $origin = $this->originOf($this->baseUrl);
            if ($origin !== '' && $origin !== $this->baseUrl
                && $this->isTackQuoteHealth($this->exchange('GET', '/health', null, $origin))) {
                return ['result' => self::CONNECTION_WRONG_ADDRESS, 'message' => (string) $ping['error']];
            }
        } elseif ($status === 0 || $status >= 500) {
            if ($this->isTackQuoteHealth($this->exchange('GET', '/health'))) {
                return ['result' => self::CONNECTION_KEY_UNCHECKED, 'message' => (string) $ping['error']];
            }
        }

        return ['result' => self::CONNECTION_FAILED, 'message' => (string) $ping['error']];
    }

    /**
     * True only for TackQuote's own `GET /health` answer: a 2xx whose body is
     * `{"status": "ok", "database": "connected", "uptime": <seconds>, ...}` (the health
     * handler in tack apps/api/src/main.ts, served at both /health and /v1/health). Any
     * other 2xx (a shop's web page, another service's health check) proves nothing about
     * TackQuote.
     *
     * @param array{status: int, data: array, error: string|null} $exchange
     */
    protected function isTackQuoteHealth(array $exchange): bool
    {
        $d = $exchange['data'];

        return $exchange['error'] === null
            && isset($d['status'], $d['database'], $d['uptime'])
            && $d['status'] === 'ok'
            && $d['database'] === 'connected'
            && (is_int($d['uptime']) || is_float($d['uptime']));
    }

    /** `scheme://host[:port]` of a URL, or '' when it has no scheme or host. */
    protected function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Kept for callers written against 1.3.1 and earlier.
     *
     * @return true|string True only when TackQuote accepted the API key; otherwise a
     *                     message. A reachable API with an unchecked key is NOT true.
     */
    public function testConnection()
    {
        $check = $this->checkConnection();

        switch ($check['result']) {
            case self::CONNECTION_OK:
                return true;
            case self::CONNECTION_KEY_REJECTED:
                return 'TackQuote rejected this API key. Check it in TackQuote under Settings > Developer > API keys.';
            case self::CONNECTION_KEY_UNCHECKED:
                return 'TackQuote is reachable, but the API key could not be checked.';
            case self::CONNECTION_WRONG_ADDRESS:
                return 'TackQuote answered, but not at the API address: check the TackQuote API URL setting (it should end in /v1).';
            default:
                return $check['message'];
        }
    }

    /**
     * Create a quote request from a product page.
     *
     * Calls `POST /integrations/opencart/quote-requests`
     * (`OpenCartPluginController::quoteRequest`), the same JSON contract used
     * by the WooCommerce/PrestaShop plugin endpoints:
     * `{ buyerEmail, note, source: "opencart", currency?,
     *    firstName?, lastName?, companyName?, phone?,
     *    lineItems: [{ sku, name, quantity, unitPrice, externalProductId }] }`,
     * expecting back `{ id, quoteNumber, portalUrl, company, awaitingApproval }`.
     *
     * The identity fields are OPTIONAL and must be OMITTED rather than sent
     * empty: Tack treats a blank as "the shopper supplied nothing" and leaves
     * the buyer's name columns NULL, which is the honest record. It no longer
     * invents a first name from the email local part when they are absent.
     *
     * `companyName` is not just a label — the endpoint resolves it to a real
     * company and applies the seller's registration policy, so a store whose
     * TackQuote tenant requires company details (or a business email address)
     * can refuse a submission that carries a company name without them.
     *
     * @param array $payload
     *
     * @return array|string Decoded response, or an error message.
     */
    public function createQuoteRequest(array $payload)
    {
        return $this->request('POST', '/integrations/opencart/quote-requests', $payload);
    }
}
