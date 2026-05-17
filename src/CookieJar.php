<?php

declare(strict_types=1);

namespace TikTokLive;

final class CookieJar
{
    /** @var array<string,string> */
    private array $cookies;

    /**
     * @param array<string,string> $initial
     */
    public function __construct(array $initial = ['tt-target-idc' => 'useast1a'])
    {
        $this->cookies = $initial;
    }

    public function setSession(?string $sessionId, ?string $ttTargetIdc): void
    {
        if ($sessionId !== null && $ttTargetIdc === null) {
            throw new \InvalidArgumentException('tt-target-idc is required when sessionId is set.');
        }
        if ($sessionId !== null) {
            $this->cookies['sessionid'] = $sessionId;
            $this->cookies['sessionid_ss'] = $sessionId;
            $this->cookies['sid_tt'] = $sessionId;
            $this->cookies['sid_guard'] = $sessionId;
        }
        if ($ttTargetIdc !== null) {
            $this->cookies['tt-target-idc'] = $ttTargetIdc;
        }
    }

    public function getSessionId(): ?string
    {
        return $this->cookies['sessionid']
            ?? $this->cookies['sessionid_ss']
            ?? $this->cookies['sid_tt']
            ?? $this->cookies['sid_guard']
            ?? null;
    }

    public function getTtTargetIdc(): ?string
    {
        return $this->cookies['tt-target-idc'] ?? null;
    }

    public function set(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    /**
     * Process a single Set-Cookie header value.
     */
    public function processSetCookieHeader(string $header): void
    {
        // Multiple cookies joined by commas can appear from Guzzle if the
        // sign-server set-cookie comes as a comma-joined string; split safely.
        foreach (preg_split('/,(?=[^;]*=)/', $header) ?: [$header] as $cookieStr) {
            $cookieStr = trim($cookieStr);
            if ($cookieStr === '') {
                continue;
            }
            $nameValuePart = explode(';', $cookieStr, 2)[0];
            $parts = explode('=', $nameValuePart, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $name = urldecode(trim($parts[0]));
            if ($name === '') {
                continue;
            }
            $this->cookies[$name] = $parts[1];
        }
    }

    public function getCookieString(): string
    {
        $parts = [];
        foreach ($this->cookies as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $parts[] = $name . '=' . $value;
        }
        return implode('; ', $parts);
    }

    /**
     * @return array<string,string>
     */
    public function all(): array
    {
        return $this->cookies;
    }
}
