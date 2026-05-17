<?php

declare(strict_types=1);

namespace TikTokLive\Http\Routes;

use TikTokLive\Config;
use TikTokLive\Exception\SignApiException;
use TikTokLive\Http\WebClient;
use TikTokLive\Protobuf\Codec;

/**
 * Hit EulerStream's /webcast/fetch endpoint to obtain a signed WebSocket URL
 * + the initial ProtoMessageFetchResult payload. The response carries:
 *   - x-set-tt-cookie  → cookies the WebSocket handshake must carry
 *   - x-room-id        → may overwrite the room id we passed in
 *   - body (protobuf)  → ProtoMessageFetchResult bytes
 */
final class FetchSignedWebSocketFromEuler
{
    public function __construct(private readonly WebClient $client)
    {
    }

    /**
     * @param array{
     *   roomId?:?string, uniqueId?:?string,
     *   sessionId?:?string, ttTargetIdc?:?string
     * } $opts
     *
     * @return array{
     *   wsUrl:string, cursor:string, internalExt:string,
     *   wsParams:array<string,string>, needsAck:bool,
     *   heartBeatDuration:int,
     *   messages: list<array{type:string,payload:string}>,
     *   roomId:?string
     * }
     */
    public function call(array $opts): array
    {
        $roomId = $opts['roomId'] ?? null;
        $uniqueId = $opts['uniqueId'] ?? null;

        if ($roomId === null && $uniqueId === null) {
            throw new \InvalidArgumentException('Either roomId or uniqueId must be provided.');
        }
        if ($roomId !== null && $uniqueId !== null) {
            throw new \InvalidArgumentException('roomId and uniqueId are mutually exclusive.');
        }

        $params = [
            'client' => Config::SIGN_CLIENT_NAME,
            'user_agent' => Config::DEFAULT_USER_AGENT,
            'client_enter' => 'true',
            'platform' => 'web',
        ];
        if ($roomId !== null) {
            $params['room_id'] = $roomId;
        }
        if ($uniqueId !== null) {
            $params['unique_id'] = $uniqueId;
        }
        if (!empty($opts['sessionId'])) {
            $params['session_id'] = $opts['sessionId'];
        }
        if (!empty($opts['ttTargetIdc'])) {
            $params['tt_target_idc'] = $opts['ttTargetIdc'];
        }

        $raw = $this->client->getEulerRaw('webcast/fetch', $params);
        $status = $raw['status'];
        $logId = $raw['headers']['X-Request-Id'][0] ?? ($raw['headers']['x-request-id'][0] ?? null);

        if ($status === 429) {
            $err = new SignApiException(self::extractMessage($raw['body']) ?? 'Sign-server rate-limited (429).');
            $err->statusCode = 429;
            $err->logId = $logId;
            throw $err;
        }
        if ($status === 402) {
            $err = new SignApiException(self::extractMessage($raw['body']) ?? 'Premium feature required at sign-server (402).');
            $err->statusCode = 402;
            $err->logId = $logId;
            throw $err;
        }
        if ($status !== 200) {
            $err = new SignApiException("Sign-server returned HTTP $status — " . substr($raw['body'], 0, 256));
            $err->statusCode = $status;
            $err->logId = $logId;
            throw $err;
        }

        // Apply cookies returned via the special x-set-tt-cookie header (these are
        // the cookies TikTok wants on the WebSocket handshake).
        $cookieHeader = $raw['headers']['x-set-tt-cookie'][0]
            ?? $raw['headers']['X-Set-Tt-Cookie'][0]
            ?? null;
        if ($cookieHeader === null || $cookieHeader === '') {
            $err = new SignApiException('Sign-server did not return x-set-tt-cookie.');
            $err->statusCode = $status;
            $err->logId = $logId;
            throw $err;
        }
        $this->client->cookieJar->processSetCookieHeader($cookieHeader);

        $roomIdHeader = $raw['headers']['x-room-id'][0] ?? $raw['headers']['X-Room-Id'][0] ?? null;
        if (is_string($roomIdHeader) && $roomIdHeader !== '') {
            $this->client->roomId = $roomIdHeader;
        }

        $decoded = Codec::decodeFetchResult($raw['body']);
        return [
            'wsUrl' => $decoded['wsUrl'],
            'cursor' => $decoded['cursor'],
            'internalExt' => $decoded['internalExt'],
            'wsParams' => $decoded['wsParams'],
            'needsAck' => $decoded['needsAck'],
            'heartBeatDuration' => $decoded['heartBeatDuration'],
            'messages' => $decoded['messages'],
            'roomId' => is_string($roomIdHeader) ? $roomIdHeader : null,
        ];
    }

    private static function extractMessage(string $body): ?string
    {
        $j = json_decode($body, true);
        if (is_array($j) && isset($j['message']) && is_string($j['message'])) {
            return $j['message'];
        }
        return null;
    }
}
