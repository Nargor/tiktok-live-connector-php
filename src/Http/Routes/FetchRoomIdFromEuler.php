<?php

declare(strict_types=1);

namespace TikTokLive\Http\Routes;

use TikTokLive\Http\WebClient;

/**
 * Last-resort room-id lookup using the EulerStream sign server's /webcast/room_id
 * endpoint. The free tier works but rate-limits heavily.
 */
final class FetchRoomIdFromEuler
{
    public function __construct(private readonly WebClient $client)
    {
    }

    /**
     * @return array{room_id:?string,is_live:bool,code:int,message:?string,ok:bool}
     */
    public function call(string $uniqueId): array
    {
        $data = $this->client->getEulerJson('webcast/room_id', ['uniqueId' => $uniqueId]);

        $status = (int) ($data['__status'] ?? 0);
        $code = isset($data['code']) ? (int) $data['code'] : $status;
        return [
            'room_id' => isset($data['room_id']) ? (string) $data['room_id'] : null,
            'is_live' => (bool) ($data['is_live'] ?? false),
            'code' => $code,
            'message' => isset($data['message']) ? (string) $data['message'] : null,
            'ok' => $status >= 200 && $status < 300 && $code !== 401 && $code !== 402 && $code !== 403,
        ];
    }
}
