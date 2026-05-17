<?php

declare(strict_types=1);

namespace TikTokLive\Http\Routes;

use TikTokLive\Config;
use TikTokLive\Http\WebClient;

final class FetchRoomInfoFromApiLive
{
    public function __construct(private readonly WebClient $client)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function call(string $uniqueId): array
    {
        $params = $this->client->clientParams;
        $params['uniqueId'] = $uniqueId;
        $params['sourceType'] = '54';

        $data = $this->client->getJson(Config::TIKTOK_HOST_WEB, 'api-live/user/room/', $params);

        if (isset($data['statusCode']) && $data['statusCode'] !== 0) {
            $msg = isset($data['message']) && is_string($data['message']) ? $data['message'] : 'Unknown';
            throw new \RuntimeException("TikTok API error {$data['statusCode']} ($msg)");
        }
        if (!isset($data['data']['user']['roomId'])) {
            throw new \RuntimeException('Invalid response from api-live/user/room/ — no roomId.');
        }
        return $data;
    }
}
