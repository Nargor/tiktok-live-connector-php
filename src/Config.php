<?php

declare(strict_types=1);

namespace TikTokLive;

final class Config
{
    public const VERSION = '0.1.0';

    public const TIKTOK_HOST_WEB = 'www.tiktok.com';
    public const TIKTOK_HOST_WEBCAST = 'webcast.tiktok.com';
    public const TIKTOK_HTTP_ORIGIN = 'https://www.tiktok.com';

    public const SIGN_API_BASE = 'https://tiktok.eulerstream.com';
    public const SIGN_CLIENT_NAME = 'ttlive-php';

    public const DEFAULT_WS_PARAMS_APPEND = '&version_code=270000';

    public const DEFAULT_USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

    /**
     * @return array<string,string>
     */
    public static function defaultHttpHeaders(): array
    {
        return [
            'Connection' => 'keep-alive',
            'Cache-Control' => 'max-age=0',
            'User-Agent' => self::DEFAULT_USER_AGENT,
            'Accept' => 'text/html,application/json,application/protobuf',
            'Referer' => 'https://www.tiktok.com/',
            'Origin' => 'https://www.tiktok.com',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip, deflate',
            'Sec-Fetch-Site' => 'same-site',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Ua-Mobile' => '?0',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function defaultClientParams(): array
    {
        return [
            'aid' => '1988',
            'app_language' => 'en',
            'app_name' => 'tiktok_web',
            'browser_language' => 'en-DE',
            'browser_name' => 'Mozilla',
            'browser_online' => 'true',
            'browser_platform' => 'Win32',
            'browser_version' => '5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
            'cookie_enabled' => 'true',
            'device_platform' => 'web_pc',
            'focus_state' => 'true',
            'from_page' => 'user',
            'history_len' => '10',
            'is_fullscreen' => 'false',
            'is_page_visible' => 'true',
            'screen_height' => '1080',
            'screen_width' => '1920',
            'tz_name' => 'Europe/Berlin',
            'referer' => 'https://www.tiktok.com/',
            'root_referer' => 'https://www.tiktok.com/',
            'channel' => 'tiktok_web',
            'data_collection_enabled' => 'true',
            'os' => 'windows',
            'priority_region' => 'DE',
            'region' => 'DE',
            'user_is_login' => 'true',
            'webcast_language' => 'en',
            'device_id' => self::generateDeviceId(),
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function defaultWsParams(): array
    {
        return [
            'version_code' => '180800',
            'aid' => '1988',
            'app_language' => 'en',
            'app_name' => 'tiktok_web',
            'browser_platform' => 'Win32',
            'browser_language' => 'en-DE',
            'browser_name' => 'Mozilla',
            'browser_version' => '5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
            'browser_online' => 'true',
            'cookie_enabled' => 'true',
            'tz_name' => 'Europe/Berlin',
            'device_platform' => 'web',
            'identity' => 'audience',
            'live_id' => '12',
            'webcast_language' => 'en',
            'ws_direct' => '0',
            'sup_ws_ds_opt' => '1',
            'update_version_code' => '2.0.0',
            'did_rule' => '3',
            'screen_height' => '1080',
            'screen_width' => '1920',
            'heartbeat_duration' => '0',
            'resp_content_type' => 'protobuf',
            'history_comment_count' => '6',
            'client_enter' => '1',
            'last_rtt' => (string) (100 + random_int(0, 100)),
        ];
    }

    public static function generateDeviceId(): string
    {
        $digits = '';
        for ($i = 0; $i < 19; $i++) {
            $digits .= (string) random_int(0, 9);
        }
        return $digits;
    }
}
