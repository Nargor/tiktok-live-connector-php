# tiktok-live-poll (PHP port — MVP)

A **minimal** PHP port of [tiktok-live-connector](https://github.com/zerodytrash/TikTok-Live-Connector) (Node.js).
Built for **PHP 8.2+** with ReactPHP + Ratchet/Pawl. Receives `chat`, `gift`, and `like` events from a TikTok LIVE room in realtime.

> 🇹🇭 อ่านวิธีใช้งานภาษาไทย: [USAGE.md](USAGE.md)

> [!WARNING]
> Same as the upstream library: this is a reverse-engineering project, not a TikTok-supported API. Use at your own risk.
> The free EulerStream sign server is rate-limited; set `SIGN_API_KEY` (from <https://www.eulerstream.com>) for production use.

## Scope of this MVP

Implemented:

- `chat`, `gift`, `like` events
- HTML / API / EulerStream room-id resolution (mirrors the Node lib chain)
- WebSocket connection with heartbeat + ack
- gzip-compressed frames

**Not yet implemented** (the Node lib has them all — port follow-ups are listed in the package):
member/social/follow/share/subscribe/envelope/question/linkMic*, `sendMessage()`, `fetchAvailableGifts()`, `waitUntilLive()`, proxied connections, full extended-gift info.

## Install

```bash
composer require nargor/tiktok-live-poll
```

Requires `ext-json`, `ext-mbstring`, `ext-zlib`, `ext-sockets`.

## Usage (CLI daemon)

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use React\EventLoop\Loop;
use TikTokLive\Event\WebcastEvent;
use TikTokLive\TikTokLiveConnection;

$loop = Loop::get();
$conn = new TikTokLiveConnection('officialgeilegisela', $loop);

$conn->on(WebcastEvent::CHAT, fn (array $m) =>
    printf("%s: %s\n", $m['user']['uniqueId'] ?? '?', $m['comment'])
);
$conn->on(WebcastEvent::GIFT, fn (array $m) =>
    printf("%s sent giftId=%d x%d\n", $m['user']['uniqueId'] ?? '?', $m['giftId'], $m['repeatCount'])
);
$conn->on(WebcastEvent::LIKE, fn (array $m) =>
    printf("%s +%d likes\n", $m['user']['uniqueId'] ?? '?', $m['likeCount'])
);

$conn->connect()->then(
    fn ($state) => printf("Connected. roomId=%s\n", $state['roomId']),
    fn (\Throwable $e) => fwrite(STDERR, "Connect failed: {$e->getMessage()}\n"),
);

$loop->run();
```

See [`examples/chat-reader.php`](examples/chat-reader.php) for a complete CLI daemon with signal handling.

## Run it

```bash
# Works without any key — EulerStream free tier (rate-limited)
php examples/chat-reader.php <username>

# Optional: set an EulerStream API key for higher limits
SIGN_API_KEY=your_key php examples/chat-reader.php <username>
```

The `SIGN_API_KEY` env var is read automatically inside the library — same as the Node lib. You can also pass it explicitly:

```php
$conn = new TikTokLiveConnection($username, $loop, [
    'signApiKey' => 'paste_key_here',
]);
```

## Architecture notes

- **HTTP** (Guzzle, sync) handles the one-shot pre-connect work: SIGI_STATE scrape → API fallback → EulerStream `/webcast/room_id` + `/webcast/fetch`. These all happen *before* the WebSocket loop starts so blocking I/O is fine.
- **WebSocket** (Ratchet/Pawl, async on ReactPHP) carries the realtime stream. Heartbeat + ack frames are encoded inline.
- **Protobuf** is hand-rolled (`src/Protobuf/`) — only the message types we actually emit are decoded. Everything else is skipped via wire-type fall-through, which keeps us tolerant of TikTok adding new fields upstream. If you need a new message type, add a `Codec::decodeFoo()` and dispatch it from `TikTokLiveConnection::dispatchInner()`.

## Limitations vs. the Node lib

1. **No sendMessage / authenticated chat** — would need WS auth + premium EulerStream signing.
2. **Sync HTTP for pre-connect** — fine for a daemon; not ideal for high-concurrency use.
3. **No proxy support yet** — Guzzle/Pawl both support it, just not wired through.
4. **MVP event coverage** — only chat / gift / like are decoded. Raw bytes for other types are emitted via `WebcastEvent::RAW_MESSAGE` so callers can decode them manually.

## License

MIT — same as the upstream Node lib. Credit to **Zerody** and **Isaac Kogan** for the original reverse engineering.
