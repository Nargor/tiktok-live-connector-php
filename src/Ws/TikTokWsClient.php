<?php

declare(strict_types=1);

namespace TikTokLive\Ws;

use Evenement\EventEmitter;
use Ratchet\Client\Connector as PawlConnector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use TikTokLive\Config;
use TikTokLive\CookieJar;
use TikTokLive\Protobuf\Codec;

/**
 * Async WebSocket client driven by ReactPHP + Ratchet/Pawl.
 *
 * Emitted events:
 *   - 'open'                  (no args)
 *   - 'fetchResult', array   the decoded ProtoMessageFetchResult
 *   - 'rawData', string      raw protobuf bytes of a single inner message
 *                            (so consumers can decode message types we don't
 *                            handle natively)
 *   - 'close', int $code, string $reason
 *   - 'error', \Throwable
 */
final class TikTokWsClient extends EventEmitter
{
    private ?WebSocket $conn = null;
    private ?\React\EventLoop\TimerInterface $heartbeat = null;
    public string $roomId;
    /** @var array<string,string> */
    public array $wsParams;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly CookieJar $cookieJar,
        string $roomId,
        array $wsParams,
        private readonly int $heartbeatMs = 10000,
    ) {
        $this->roomId = $roomId;
        $this->wsParams = $wsParams;
    }

    /**
     * Open the WebSocket. Returns a promise that resolves when the handshake completes.
     *
     * @param array<string,string> $extraHeaders
     */
    public function connect(string $wsUrl, array $extraHeaders = []): PromiseInterface
    {
        $url = $wsUrl . '?' . http_build_query($this->wsParams) . Config::DEFAULT_WS_PARAMS_APPEND;

        $headers = array_merge([
            'User-Agent' => Config::DEFAULT_USER_AGENT,
            'Cookie' => $this->cookieJar->getCookieString(),
            'Origin' => Config::TIKTOK_HTTP_ORIGIN,
        ], $extraHeaders);

        $connector = new PawlConnector($this->loop);
        return $connector($url, [], $headers)->then(
            function (WebSocket $conn): void {
                $this->conn = $conn;

                $conn->on('message', function (MessageInterface $msg): void {
                    $this->onMessage((string) $msg);
                });
                $conn->on('close', function ($code = null, $reason = null): void {
                    if ($this->heartbeat !== null) {
                        $this->loop->cancelTimer($this->heartbeat);
                        $this->heartbeat = null;
                    }
                    $this->emit('close', [(int) ($code ?? 0), (string) ($reason ?? '')]);
                });
                $conn->on('error', function (\Throwable $e): void {
                    $this->emit('error', [$e]);
                });

                $this->enterRoom();
                $this->heartbeat = $this->loop->addPeriodicTimer($this->heartbeatMs / 1000, function (): void {
                    $this->sendHeartbeat();
                });

                $this->emit('open');
            },
            function (\Throwable $e): void {
                $this->emit('error', [$e]);
            }
        );
    }

    public function close(): void
    {
        $this->conn?->close();
    }

    private function onMessage(string $bytes): void
    {
        try {
            $frame = Codec::decodePushFrame($bytes);
        } catch (\Throwable $e) {
            $this->emit('error', [$e]);
            return;
        }

        $payload = $frame['payload'];

        // gzip magic: 1f 8b 08 — auto-inflate. The Node lib uses zlib.unzip;
        // we use gzdecode which handles the gzip header transparently.
        if (
            strlen($payload) > 2
            && ord($payload[0]) === 0x1f
            && ord($payload[1]) === 0x8b
            && ord($payload[2]) === 0x08
        ) {
            $decoded = @gzdecode($payload);
            if ($decoded === false) {
                $this->emit('error', [new \RuntimeException('Failed to gunzip payload')]);
                return;
            }
            $payload = $decoded;
        }

        if ($frame['payloadEncoding'] !== 'pb' || $payload === '') {
            return;
        }

        // Only "msg" and "im_enter_room_resp" frames carry a ProtoMessageFetchResult.
        // Everything else (heartbeat ack, control frames, …) is silently ignored —
        // decoding them as fetch-results would produce harmless-but-noisy
        // "Truncated length-delimited field" errors.
        if (!in_array($frame['payloadType'], ['msg', 'im_enter_room_resp'], true)) {
            return;
        }

        try {
            $fetchResult = Codec::decodeFetchResult($payload);
        } catch (\Throwable $e) {
            // Don't kill the connection — emit error but keep listening.
            $this->emit('error', [$e]);
            return;
        }

        if ($fetchResult['needsAck']) {
            $this->sendAck($frame['logId'], $fetchResult['internalExt']);
        }

        $this->emit('fetchResult', [$fetchResult]);
    }

    private function enterRoom(): void
    {
        $payload = Codec::encodeImEnterRoom($this->roomId);
        $frame = Codec::encodePushFrame('im_enter_room', $payload);
        $this->conn?->send(new \Ratchet\RFC6455\Messaging\Frame($frame, true, \Ratchet\RFC6455\Messaging\Frame::OP_BINARY));
    }

    private function sendHeartbeat(): void
    {
        if ($this->conn === null) {
            return;
        }
        $payload = Codec::encodeHeartbeat($this->roomId);
        $frame = Codec::encodePushFrame('hb', $payload);
        $this->conn->send(new \Ratchet\RFC6455\Messaging\Frame($frame, true, \Ratchet\RFC6455\Messaging\Frame::OP_BINARY));
    }

    private function sendAck(string $logId, string $internalExt): void
    {
        if ($this->conn === null || $logId === '0' || $logId === '') {
            return;
        }
        $frame = Codec::encodePushFrame('ack', $internalExt, 'pb', $logId);
        $this->conn->send(new \Ratchet\RFC6455\Messaging\Frame($frame, true, \Ratchet\RFC6455\Messaging\Frame::OP_BINARY));
    }
}
