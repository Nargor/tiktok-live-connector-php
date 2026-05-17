<?php

declare(strict_types=1);

namespace TikTokLive;

use Evenement\EventEmitter;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use TikTokLive\Event\WebcastEvent;
use TikTokLive\Exception\AlreadyConnectedException;
use TikTokLive\Exception\FetchRoomIdException;
use TikTokLive\Exception\UserOfflineException;
use TikTokLive\Http\Routes\FetchRoomIdFromEuler;
use TikTokLive\Http\Routes\FetchRoomInfoFromApiLive;
use TikTokLive\Http\Routes\FetchRoomInfoFromHtml;
use TikTokLive\Http\Routes\FetchSignedWebSocketFromEuler;
use TikTokLive\Http\WebClient;
use TikTokLive\Protobuf\Codec;
use TikTokLive\Ws\TikTokWsClient;

/**
 * Top-level TikTok LIVE client.
 *
 * Usage:
 *   $loop = \React\EventLoop\Loop::get();
 *   $conn = new TikTokLiveConnection('officialgeilegisela', $loop);
 *   $conn->on(WebcastEvent::CHAT, fn ($e) => ...);
 *   $conn->connect();
 *   $loop->run();
 */
final class TikTokLiveConnection extends EventEmitter
{
    public readonly string $uniqueId;
    public readonly LoopInterface $loop;
    public WebClient $webClient;
    public ?TikTokWsClient $wsClient = null;

    private bool $connecting = false;
    private bool $connected = false;
    public ?string $roomId = null;

    /** @var array{
     *    sessionId:?string, ttTargetIdc:?string,
     *    signApiKey:?string, disableEulerFallbacks:bool,
     *    fetchRoomInfoOnConnect:bool, connectWithUniqueId:bool
     *  }
     */
    public array $options;

    /**
     * @param array{
     *   sessionId?:?string, ttTargetIdc?:?string, signApiKey?:?string,
     *   disableEulerFallbacks?:bool, fetchRoomInfoOnConnect?:bool,
     *   connectWithUniqueId?:bool
     * } $options
     */
    public function __construct(
        string $uniqueId,
        ?LoopInterface $loop = null,
        array $options = [],
    ) {
        $this->uniqueId = Utilities::validateAndNormalizeUniqueId($uniqueId);
        $this->loop = $loop ?? Loop::get();
        $this->options = array_merge([
            'sessionId' => null,
            'ttTargetIdc' => null,
            'signApiKey' => null,
            'disableEulerFallbacks' => false,
            'fetchRoomInfoOnConnect' => true,
            'connectWithUniqueId' => false,
            // When true (Node lib default), historical messages from /webcast/fetch
            // and the first WebSocket frame both fire — which TikTok re-delivers, so
            // you see each old chat twice. Set false to suppress the HTTP-fetched
            // batch and only stream realtime.
            'processInitialData' => true,
        ], $options);

        // Mirror the Node lib: if caller didn't pass a key, fall back to the
        // SIGN_API_KEY env var. Null means "use EulerStream free tier".
        if ($this->options['signApiKey'] === null) {
            $envKey = getenv('SIGN_API_KEY');
            if (is_string($envKey) && $envKey !== '') {
                $this->options['signApiKey'] = $envKey;
            }
        }

        $this->webClient = new WebClient(
            signApiKey: $this->options['signApiKey']
        );
        $this->webClient->cookieJar->setSession(
            $this->options['sessionId'],
            $this->options['ttTargetIdc'],
        );
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Connect to TikTok LIVE. Returns a promise that resolves when the WebSocket
     * is open and the initial fetch result has been processed.
     */
    public function connect(?string $roomId = null): PromiseInterface
    {
        if ($this->connected) {
            throw new AlreadyConnectedException('Already connected.');
        }
        if ($this->connecting) {
            throw new AlreadyConnectedException('Already connecting.');
        }
        $this->connecting = true;

        $deferred = new Deferred();
        try {
            // 1) Resolve room id via scraping (unless explicit / connectWithUniqueId).
            if (!$this->options['connectWithUniqueId']) {
                $this->roomId = $roomId ?? $this->fetchRoomId();
                $this->webClient->roomId = $this->roomId;

                if ($this->options['fetchRoomInfoOnConnect']) {
                    $info = $this->fetchRoomInfoSafe();
                    if ($info !== null && self::isOffline($info)) {
                        throw new UserOfflineException("User @{$this->uniqueId} is not currently live.");
                    }
                }
            }

            // 2) Get signed WebSocket URL + initial payload.
            $route = new FetchSignedWebSocketFromEuler($this->webClient);
            $signed = $route->call([
                'roomId' => $this->options['connectWithUniqueId'] ? null : $this->roomId,
                'uniqueId' => $this->options['connectWithUniqueId'] ? $this->uniqueId : null,
                'sessionId' => $this->options['sessionId'],
                'ttTargetIdc' => $this->options['ttTargetIdc'],
            ]);

            if ($this->roomId === null && $signed['roomId'] !== null) {
                $this->roomId = $signed['roomId'];
                $this->webClient->roomId = $signed['roomId'];
            }

            if ($this->roomId === null || $this->roomId === '') {
                throw new \RuntimeException('No roomId resolved after signing.');
            }

            // Process initial fetch messages (the history TikTok bundles with the
            // signing response). The first WebSocket frame usually replays them
            // again, so callers may disable this to avoid duplicate output.
            if ($this->options['processInitialData']) {
                foreach ($signed['messages'] as $msg) {
                    $this->dispatchInner($msg['type'], $msg['payload']);
                }
            }

            // 3) Open the WebSocket.
            $wsParams = array_merge(Config::defaultWsParams(), $signed['wsParams'], [
                'compress' => 'gzip',
                'room_id' => $this->roomId,
                'internal_ext' => $signed['internalExt'],
                'cursor' => $signed['cursor'],
            ]);

            $hbMs = $signed['heartBeatDuration'] > 0 ? $signed['heartBeatDuration'] : 10000;

            $this->wsClient = new TikTokWsClient(
                $this->loop,
                $this->webClient->cookieJar,
                $this->roomId,
                $wsParams,
                $hbMs,
            );

            $this->wsClient->on('fetchResult', function (array $fetchResult): void {
                foreach ($fetchResult['messages'] as $msg) {
                    $this->dispatchInner($msg['type'], $msg['payload']);
                }
            });
            $this->wsClient->on('close', function (int $code, string $reason): void {
                $this->connected = false;
                $this->emit(WebcastEvent::DISCONNECTED, [['code' => $code, 'reason' => $reason]]);
            });
            $this->wsClient->on('error', function (\Throwable $e): void {
                $this->emit(WebcastEvent::ERROR, [$e]);
            });

            $this->wsClient->connect($signed['wsUrl'])->then(
                function () use ($deferred): void {
                    $this->connecting = false;
                    $this->connected = true;
                    $this->emit(WebcastEvent::CONNECTED, [['roomId' => $this->roomId]]);
                    $deferred->resolve(['roomId' => $this->roomId]);
                },
                function (\Throwable $e) use ($deferred): void {
                    $this->connecting = false;
                    $deferred->reject($e);
                }
            );
        } catch (\Throwable $e) {
            $this->connecting = false;
            $deferred->reject($e);
        }

        return $deferred->promise();
    }

    public function disconnect(): void
    {
        $this->wsClient?->close();
        $this->connected = false;
        $this->connecting = false;
    }

    /**
     * Try HTML → API → Euler (mirror of the Node lib's chain).
     */
    private function fetchRoomId(): string
    {
        $errors = [];

        try {
            $info = (new FetchRoomInfoFromHtml($this->webClient))->call($this->uniqueId);
            $roomId = $info['user']['roomId'] ?? null;
            if (is_string($roomId) && $roomId !== '') {
                return $roomId;
            }
        } catch (\Throwable $e) {
            $errors[] = $e;
        }

        try {
            $data = (new FetchRoomInfoFromApiLive($this->webClient))->call($this->uniqueId);
            $roomId = $data['data']['user']['roomId'] ?? null;
            if (is_string($roomId) && $roomId !== '') {
                return $roomId;
            }
        } catch (\Throwable $e) {
            $errors[] = $e;
        }

        if (!$this->options['disableEulerFallbacks']) {
            try {
                $resp = (new FetchRoomIdFromEuler($this->webClient))->call($this->uniqueId);
                if (in_array($resp['code'], [401, 402, 403], true)) {
                    throw new \RuntimeException(
                        'EulerStream fallback rejected (HTTP ' . $resp['code'] . '). '
                        . 'Set $options[\'signApiKey\'] or disableEulerFallbacks=true.'
                    );
                }
                if ($resp['ok'] && is_string($resp['room_id']) && $resp['room_id'] !== '') {
                    return $resp['room_id'];
                }
                if ($resp['message'] !== null) {
                    throw new \RuntimeException($resp['message']);
                }
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }

        throw new FetchRoomIdException(
            'Failed to retrieve roomId from all sources.',
            $errors
        );
    }

    /**
     * Best-effort live check using the HTML page only — used to short-circuit
     * connect() when the user is plainly offline. Soft-fails on errors so the
     * full pipeline still gets a chance.
     *
     * @return array<string,mixed>|null
     */
    private function fetchRoomInfoSafe(): ?array
    {
        try {
            return (new FetchRoomInfoFromHtml($this->webClient))->call($this->uniqueId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $info SIGI_STATE liveRoomUserInfo
     */
    private static function isOffline(array $info): bool
    {
        $status = $info['liveRoom']['status'] ?? null;
        return $status === 4;
    }

    /**
     * Decode a single inner BaseProtoMessage payload and emit the appropriate
     * high-level event. Unknown types are surfaced via RAW_MESSAGE so callers
     * can still introspect.
     */
    private function dispatchInner(string $type, string $payload): void
    {
        $this->emit(WebcastEvent::RAW_MESSAGE, [$type, $payload]);
        try {
            switch ($type) {
                case 'WebcastChatMessage':
                    $this->emit(WebcastEvent::CHAT, [Codec::decodeChat($payload)]);
                    return;
                case 'WebcastGiftMessage':
                    $this->emit(WebcastEvent::GIFT, [Codec::decodeGift($payload)]);
                    return;
                case 'WebcastLikeMessage':
                    $this->emit(WebcastEvent::LIKE, [Codec::decodeLike($payload)]);
                    return;
            }
        } catch (\Throwable $e) {
            $this->emit(WebcastEvent::ERROR, [$e]);
        }
    }
}
