<?php

declare(strict_types=1);

namespace TikTokLive\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use TikTokLive\Config;
use TikTokLive\CookieJar;

/**
 * Synchronous HTTP client used for all *non-WebSocket* TikTok / EulerStream calls.
 *
 * The original library does this with axios + interceptors that auto-merge cookies
 * from a custom jar. We keep that contract by attaching our CookieJar to every
 * request and reading Set-Cookie from every response. Async I/O isn't worth the
 * complexity here — these are short one-shot fetches that happen before the
 * WebSocket loop starts.
 */
final class WebClient
{
    private Client $http;
    public CookieJar $cookieJar;
    /** @var array<string,string> */
    public array $clientParams;
    public string $roomId = '';

    public ?string $signApiKey;
    public string $signApiBase;

    /**
     * @param array<string,string> $extraHeaders
     * @param array<string,string> $extraClientParams
     */
    public function __construct(
        array $extraHeaders = [],
        array $extraClientParams = [],
        ?string $signApiKey = null,
        ?string $signApiBase = null,
        int $timeoutSeconds = 15
    ) {
        $this->cookieJar = new CookieJar();
        $this->clientParams = array_merge(Config::defaultClientParams(), $extraClientParams);
        $this->signApiKey = $signApiKey;
        $this->signApiBase = $signApiBase ?? Config::SIGN_API_BASE;

        $this->http = new Client([
            'timeout' => $timeoutSeconds,
            'http_errors' => false,
            'headers' => array_merge(Config::defaultHttpHeaders(), $extraHeaders),
            // Let Guzzle decode gzip; raw bodies aren't needed for these calls.
            'decode_content' => true,
        ]);
    }

    /**
     * GET against the main TikTok website. Returns the raw HTML body.
     */
    public function getHtml(string $path): string
    {
        $url = 'https://' . Config::TIKTOK_HOST_WEB . '/' . ltrim($path, '/');
        $response = $this->request('GET', $url);
        return (string) $response->getBody();
    }

    /**
     * GET against the internal TikTok JSON API (api-live, etc).
     *
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    public function getJson(string $host, string $path, array $params): array
    {
        $url = 'https://' . $host . '/' . ltrim($path, '/') . '?' . http_build_query($params);
        $response = $this->request('GET', $url);
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Non-JSON response from $url");
        }
        return $decoded;
    }

    /**
     * GET against the EulerStream sign server. Returns raw bytes + headers.
     *
     * @param array<string,string|int|bool> $params
     * @return array{status:int,body:string,headers:array<string,array<int,string>>}
     */
    public function getEulerRaw(string $path, array $params): array
    {
        if ($this->signApiKey !== null && $this->signApiKey !== '') {
            $params['apiKey'] = $this->signApiKey;
        }
        $url = rtrim($this->signApiBase, '/') . '/' . ltrim($path, '/') . '?' . http_build_query($params);

        $headers = [
            'User-Agent' => 'tiktok-live-php/' . Config::VERSION . ' php-' . PHP_VERSION,
        ];
        if ($this->signApiKey !== null && $this->signApiKey !== '') {
            $headers['x-api-key'] = $this->signApiKey;
        }
        $response = $this->request('GET', $url, $headers);

        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
            'headers' => $response->getHeaders(),
        ];
    }

    /**
     * GET against EulerStream that returns JSON (room_id endpoint etc).
     *
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    public function getEulerJson(string $path, array $params): array
    {
        $raw = $this->getEulerRaw($path, $params);
        $decoded = json_decode($raw['body'], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Non-JSON response from EulerStream $path");
        }
        $decoded['__status'] = $raw['status'];
        return $decoded;
    }

    /**
     * @param array<string,string> $extraHeaders
     */
    private function request(string $method, string $url, array $extraHeaders = []): ResponseInterface
    {
        $headers = $extraHeaders;
        $cookieString = $this->cookieJar->getCookieString();
        if ($cookieString !== '') {
            $headers['Cookie'] = $cookieString;
        }
        try {
            $response = $this->http->request($method, $url, ['headers' => $headers]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("HTTP $method $url failed: " . $e->getMessage(), 0, $e);
        }

        foreach ($response->getHeader('Set-Cookie') as $setCookie) {
            $this->cookieJar->processSetCookieHeader($setCookie);
        }
        return $response;
    }
}
