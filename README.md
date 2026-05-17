# tiktok-live-connector-php

A PHP library to receive TikTok LIVE stream events such as chat comments, gifts, and likes in realtime
by connecting to TikTok's internal Webcast push service.
This package is a PHP port of the Node.js library
[tiktok-live-connector](https://github.com/zerodytrash/TikTok-Live-Connector).
You only need a TikTok username (`@uniqueId`) — no credentials required to connect to public streams.

> 🇹🇭 **อ่านภาษาไทย:** [USAGE.md](USAGE.md)

> [!NOTE]
> This is **not** a production-ready API. It is a reverse-engineering project that depends on TikTok's
> internal Webcast push service and on the [EulerStream](https://www.eulerstream.com) third-party sign server.
> For production use, prefer EulerStream's [WebSocket API](https://www.eulerstream.com/websockets).

> [!WARNING]
> The free EulerStream tier is heavily rate-limited. Set `SIGN_API_KEY` (see [Signing Configuration](#signing-configuration)) for any real use.

### Other language ports

- **Node.js** (original): [tiktok-live-connector](https://github.com/zerodytrash/TikTok-Live-Connector) by [@zerodytrash](https://github.com/zerodytrash) and [@isaackogan](https://github.com/isaackogan)
- **Python**: [TikTokLive](https://github.com/isaackogan/TikTokLive)
- **Java**: [TikTokLiveJava](https://github.com/jwdeveloper/TikTokLiveJava)
- **Go**: [GoTikTokLive](https://github.com/steampoweredtaco/gotiktoklive)
- **C#**: [TikTokLiveSharp](https://github.com/frankvHoof93/TikTokLiveSharp)

### Table of Contents

- [Getting Started](#getting-started)
- [Params and Options](#params-and-options)
- [Methods](#methods)
- [Properties](#properties)
- [Signing Configuration](#signing-configuration)
- [Events](#events)
  - [Control Events](#control-events)
  - [Message Events](#message-events)
- [Examples](#examples)

## Getting Started

1. **Install the package via Composer**

```bash
composer require nargor/tiktok-live-connector-php
```

Requires **PHP 8.2+** with extensions: `ext-json`, `ext-mbstring`, `ext-zlib`, `ext-sockets`.

### Platform support

| OS                 | Status            | Notes |
|--------------------|-------------------|-------|
| Linux              | ✅ Fully supported | `ext-pcntl` recommended for graceful Ctrl+C in CLI scripts. |
| macOS              | ✅ Fully supported | Same as Linux. |
| Windows 10 / 11    | ✅ Fully supported | `pcntl` is not available; `sapi_windows_set_ctrl_handler` is used instead. Make sure `extension=sockets` is enabled in `php.ini` — it ships with PHP for Windows but is sometimes commented out. |

The library code itself contains no OS-specific calls. Only the example CLI in
[`examples/chat-reader.php`](examples/chat-reader.php) does graceful-shutdown signal wiring,
and it auto-detects the platform.

2. **Create your first TikTok LIVE chat connection**

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use React\EventLoop\Loop;
use TikTokLive\Event\WebcastEvent;
use TikTokLive\TikTokLiveConnection;

$loop = Loop::get();
$tiktokUsername = 'officialgeilegisela';

// Create a new wrapper object and pass the username
$connection = new TikTokLiveConnection($tiktokUsername, $loop);

// Connect to the chat
$connection->connect()->then(
    fn ($state) => printf("Connected to roomId %s\n", $state['roomId']),
    fn (\Throwable $e) => fwrite(STDERR, "Failed to connect: {$e->getMessage()}\n"),
);

// Listen for chat messages (comments)
$connection->on(WebcastEvent::CHAT, function (array $data): void {
    $user = $data['user']['uniqueId'] ?? '?';
    printf("%s writes: %s\n", $user, $data['comment']);
});

// And gifts sent to the streamer
$connection->on(WebcastEvent::GIFT, function (array $data): void {
    $user = $data['user']['uniqueId'] ?? '?';
    printf("%s sends giftId=%d x%d\n", $user, $data['giftId'], $data['repeatCount']);
});

// Drive the ReactPHP event loop (blocks until disconnect)
$loop->run();
```

## Params and Options

To create a new `TikTokLiveConnection` object the following parameters can be specified.

`new TikTokLiveConnection(string $uniqueId, ?LoopInterface $loop = null, array $options = [])`

| Param      | Required | Description |
|------------|----------|-------------|
| `uniqueId` | Yes      | The unique username of the broadcaster. You can find this in the URL.<br>Example: `https://www.tiktok.com/@officialgeilegisela/live` → `officialgeilegisela` |
| `loop`     | No       | A ReactPHP `LoopInterface`. Defaults to `Loop::get()`. |
| `options`  | No       | Optional connection properties (see table below). |

### Available options

| Option                   | Default | Description |
|--------------------------|---------|-------------|
| `sessionId`              | `null`  | TikTok account session ID (from the `sessionid` cookie). Required for authenticated actions. |
| `ttTargetIdc`            | `null`  | The `tt-target-idc` cookie — the datacenter the account is registered in. Required when `sessionId` is set. |
| `signApiKey`             | `null`  | EulerStream API key. If `null`, falls back to the `SIGN_API_KEY` env var, then to the free tier. |
| `disableEulerFallbacks`  | `false` | Disable the EulerStream fallback used when HTML/API scraping fails to resolve room id. |
| `fetchRoomInfoOnConnect` | `true`  | Fetch room info on `connect()`. Prevents connection to offline rooms. |
| `connectWithUniqueId`    | `false` | Let EulerStream resolve the room id from `uniqueId` directly (skips local scraping). Useful for low-quality IPs that get captcha'd. |
| `processInitialData`     | `true`  | Emit events for the chat history bundled with the sign response. The WebSocket usually replays the same history on connect, so set this to `false` if you only want realtime events. |

### Example with options

```php
$connection = new TikTokLiveConnection('username', $loop, [
    'signApiKey' => 'your-eulerstream-key',
    'sessionId' => 'paste-from-browser-cookie',
    'ttTargetIdc' => 'useast1a',
    'fetchRoomInfoOnConnect' => false,
]);
```

## Methods

| Method                                          | Status         | Description |
|-------------------------------------------------|----------------|-------------|
| `connect(?string $roomId = null): PromiseInterface` | ✅ Implemented | Connect to the live stream. Returns a promise that resolves once the WebSocket is open. Optionally accepts an explicit roomId. |
| `disconnect(): void`                            | ✅ Implemented | Close the WebSocket and reset state. |
| `isConnected(): bool`                           | ✅ Implemented | Whether the WebSocket is currently open. |
| `on(string $event, callable $listener): void`  | ✅ Implemented | Register an event listener (from `evenement/evenement`). |
| `sendMessage(string $content)`                  | ❌ Not yet     | Send a chat message. Requires authenticated WebSocket + premium EulerStream signing. |
| `fetchRoomInfo()`                               | ❌ Not yet     | Standalone room-info fetch. Currently only happens automatically during `connect()` when `fetchRoomInfoOnConnect` is true. |
| `fetchAvailableGifts()`                         | ❌ Not yet     | List all available gifts. |
| `fetchIsLive()`                                 | ❌ Not yet     | Check whether the user is currently streaming. |
| `waitUntilLive()`                               | ❌ Not yet     | Block until the user goes live. |

> The unimplemented methods can be added on top of the existing route classes
> in [`src/Http/Routes/`](src/Http/Routes/). PRs welcome.

## Properties

| Property                              | Description |
|---------------------------------------|-------------|
| `webClient: WebClient`                | HTTP client used for the pre-connect work (scrape + EulerStream calls). |
| `wsClient: TikTokWsClient \| null`    | The WebSocket client, populated after `connect()`. |
| `options: array`                      | Resolved options array (defaults + caller overrides). |
| `roomId: string \| null`              | The current room ID. `null` before connect. |
| `uniqueId: string` *(readonly)*       | The normalized username. |
| `loop: LoopInterface` *(readonly)*    | The ReactPHP event loop instance. |

## Signing Configuration

TikTok requires WebSocket URLs to be cryptographically signed. The signing algorithm lives in
TikTok's obfuscated `webmssdk.js` and changes regularly, so all rewrites (Node, Python, Java, …)
delegate this step to a third-party sign server. We use **EulerStream** by default — the same as
the Node lib.

### Use the free tier (no key)

```bash
php examples/chat-reader.php <username>
```

This works for small experiments but is rate-limited (~10 connections per day on a shared IP).

### Use an API key

Sign up at <https://www.eulerstream.com> → Dashboard → API Keys → Create.

Then either:

```bash
# via env var (recommended)
SIGN_API_KEY=your_key php examples/chat-reader.php <username>
```

```php
// or pass it explicitly
$connection = new TikTokLiveConnection('username', $loop, [
    'signApiKey' => 'paste_key_here',
]);
```

### Self-host a sign server

You can point at any EulerStream-compatible endpoint by setting the
`SIGN_API_URL` env var:

```bash
SIGN_API_URL=https://my-sign-server.example.com php examples/chat-reader.php <user>
```

## Events

A `TikTokLiveConnection` extends `Evenement\EventEmitter`. Attach listeners with
`$connection->on(EVENT_NAME, callable)`.

### Control Events

| Constant                          | Event name      | Fired when |
|-----------------------------------|-----------------|------------|
| `WebcastEvent::CONNECTED`         | `connected`     | WebSocket handshake completed. |
| `WebcastEvent::DISCONNECTED`      | `disconnected`  | WebSocket closed (any reason). |
| `WebcastEvent::ERROR`             | `error`         | Any unhandled internal error. |
| `WebcastEvent::RAW_MESSAGE`       | `rawMessage`    | Every inner protobuf message — including ones we don't decode natively. |

#### `connected`

```php
$connection->on(WebcastEvent::CONNECTED, function (array $state): void {
    printf("Connected to roomId %s\n", $state['roomId']);
});
```

#### `disconnected`

```php
$connection->on(WebcastEvent::DISCONNECTED, function (array $info): void {
    printf("Disconnected: code=%d reason=%s\n", $info['code'], $info['reason']);
});
```

You can re-call `connect()` to reconnect. Wait a few seconds first to avoid being rate-limited.

#### `error`

```php
$connection->on(WebcastEvent::ERROR, function (\Throwable $e): void {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
});
```

#### `rawMessage`

Useful for decoding message types that aren't yet supported natively.

```php
$connection->on(WebcastEvent::RAW_MESSAGE, function (string $type, string $bytes): void {
    printf("type=%s payload=%d bytes\n", $type, strlen($bytes));
    // base64-encode and report in an issue for new message-type support:
    // base64_encode($bytes)
});
```

### Message Events

#### Implemented

| Constant                  | Event name | Payload |
|---------------------------|------------|---------|
| `WebcastEvent::CHAT`      | `chat`     | `['comment' => string, 'user' => ['userId', 'nickname', 'uniqueId']]` |
| `WebcastEvent::GIFT`      | `gift`     | `['giftId' => int, 'repeatCount' => int, 'repeatEnd' => int, 'user' => [...]]` |
| `WebcastEvent::LIKE`      | `like`     | `['likeCount' => int, 'totalLikeCount' => int, 'user' => [...]]` |

##### `chat`

Triggered when a viewer posts a chat comment.

```php
$connection->on(WebcastEvent::CHAT, function (array $data): void {
    printf("%s -> %s\n", $data['user']['uniqueId'] ?? '?', $data['comment']);
});
```

##### `gift`

Triggered when a viewer sends a gift.

> **NOTE:** Users can send gifts in a *streak*. Each tick of the streak fires another `gift` event with an
> increasing `repeatCount`. After the streak ends, one final event fires with `repeatEnd == 1`. Even a
> single, non-streak gift fires twice: once with `repeatEnd == 0` and once with `repeatEnd == 1`.
> Handle accordingly:

```php
$connection->on(WebcastEvent::GIFT, function (array $data): void {
    if ($data['repeatEnd'] === 0) {
        // Streak in progress — show temporarily
        printf("%s is sending giftId=%d x%d\n",
            $data['user']['uniqueId'] ?? '?',
            $data['giftId'],
            $data['repeatCount'],
        );
    } else {
        // Streak ended (or non-streakable gift) — process the final total
        printf("%s has sent giftId=%d x%d\n",
            $data['user']['uniqueId'] ?? '?',
            $data['giftId'],
            $data['repeatCount'],
        );
    }
});
```

##### `like`

Triggered when a viewer sends likes. For high-traffic streams TikTok does not always emit this.

```php
$connection->on(WebcastEvent::LIKE, function (array $data): void {
    printf("%s sent %d likes (total %d)\n",
        $data['user']['uniqueId'] ?? '?',
        $data['likeCount'],
        $data['totalLikeCount'],
    );
});
```

#### Not yet implemented

The following events from the Node lib are not yet decoded. Their raw bytes still arrive
via `WebcastEvent::RAW_MESSAGE`, so you can extend
[`src/Protobuf/Codec.php`](src/Protobuf/Codec.php) and
[`TikTokLiveConnection::dispatchInner()`](src/TikTokLiveConnection.php)
to add support. PRs welcome.

`member` (join), `social` (follow/share), `subscribe`, `envelope` (treasure box),
`questionNew`, `linkMicBattle`, `linkMicArmies`, `liveIntro`, `roomUser` (viewer count),
`emote`, `goalUpdate`, `roomMessage`, `captionMessage`, `imDelete`, `inRoomBanner`,
`rankUpdate`, `pollMessage`, `rankText`, `linkMicBattlePunishFinish`, `linkMicBattleTask`,
`linkMicFanTicketMethod`, `linkMicMethod`, `unauthorizedMember`, `oecLiveShopping`,
`msgDetect`, `linkMessage`, `roomVerify`, `linkLayer`, `roomPin`, `streamEnd`.

## Examples

### Minimal chat reader

The simplest possible script — see [`examples/chat-reader.php`](examples/chat-reader.php) for the full
version with signal handling.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use React\EventLoop\Loop;
use TikTokLive\Event\WebcastEvent;
use TikTokLive\TikTokLiveConnection;

$loop = Loop::get();
$conn = new TikTokLiveConnection($argv[1] ?? 'officialgeilegisela', $loop);

$conn->on(WebcastEvent::CHAT, fn (array $m) =>
    printf("%s: %s\n", $m['user']['uniqueId'] ?? '?', $m['comment'])
);

$conn->connect();
$loop->run();
```

### Reconnect on disconnect

```php
use React\EventLoop\Loop;
use TikTokLive\Event\WebcastEvent;
use TikTokLive\TikTokLiveConnection;

$loop = Loop::get();
$username = $argv[1];

$register = function () use (&$register, $loop, $username): void {
    $conn = new TikTokLiveConnection($username, $loop);

    $conn->on(WebcastEvent::CHAT, fn (array $m) =>
        printf("%s: %s\n", $m['user']['uniqueId'] ?? '?', $m['comment'])
    );

    $conn->on(WebcastEvent::DISCONNECTED, function () use ($loop, $register): void {
        echo "Disconnected — retrying in 10s\n";
        $loop->addTimer(10, $register);
    });

    $conn->connect()->then(
        null,
        function (\Throwable $e) use ($loop, $register): void {
            fwrite(STDERR, "Connect failed: {$e->getMessage()} — retry in 30s\n");
            $loop->addTimer(30, $register);
        },
    );
};

$register();
$loop->run();
```

### Save chat to a log file

```php
$logFile = fopen('chat-' . date('Y-m-d') . '.log', 'a');

$conn->on(WebcastEvent::CHAT, function (array $m) use ($logFile): void {
    fwrite($logFile, sprintf(
        "[%s] %s: %s\n",
        date('H:i:s'),
        $m['user']['uniqueId'] ?? '?',
        $m['comment'],
    ));
});
```

### Save events to MySQL

```php
use PDO;

$pdo = new PDO('mysql:host=localhost;dbname=tiktok;charset=utf8mb4',
    'user', 'pass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$insertChat = $pdo->prepare(
    'INSERT INTO chats (username, user_id, comment, created_at) VALUES (?, ?, ?, NOW())'
);
$insertGift = $pdo->prepare(
    'INSERT INTO gifts (username, user_id, gift_id, repeat_count, repeat_end, created_at)
     VALUES (?, ?, ?, ?, ?, NOW())'
);

$conn->on(WebcastEvent::CHAT, function (array $m) use ($insertChat): void {
    $insertChat->execute([
        $m['user']['uniqueId'] ?? null,
        $m['user']['userId'] ?? null,
        $m['comment'],
    ]);
});

$conn->on(WebcastEvent::GIFT, function (array $m) use ($insertGift): void {
    // Only persist when the streak ends, to avoid duplicate rows for combos
    if ($m['repeatEnd'] !== 1) {
        return;
    }
    $insertGift->execute([
        $m['user']['uniqueId'] ?? null,
        $m['user']['userId'] ?? null,
        $m['giftId'],
        $m['repeatCount'],
        $m['repeatEnd'],
    ]);
});
```

### Forward to a webhook

```php
use GuzzleHttp\Client;

$http = new Client(['timeout' => 5]);
$webhookUrl = 'https://your-app.example.com/tiktok/event';

$forward = function (string $event, array $payload) use ($http, $webhookUrl): void {
    $http->postAsync($webhookUrl, [
        'json' => ['event' => $event, 'data' => $payload, 'ts' => time()],
    ])->then(null, fn () => null); // swallow errors so listener doesn't crash
};

$conn->on(WebcastEvent::CHAT, fn (array $m) => $forward('chat', $m));
$conn->on(WebcastEvent::GIFT, fn (array $m) => $forward('gift', $m));
$conn->on(WebcastEvent::LIKE, fn (array $m) => $forward('like', $m));
```

### Live dashboard counter

```php
$totals = ['chat' => 0, 'gift' => 0, 'likes' => 0];

$render = function () use (&$totals): void {
    printf("\rchats: %d  gifts: %d  likes: %d   ",
        $totals['chat'], $totals['gift'], $totals['likes']);
};

$conn->on(WebcastEvent::CHAT, function () use (&$totals, $render): void {
    $totals['chat']++; $render();
});
$conn->on(WebcastEvent::GIFT, function (array $m) use (&$totals, $render): void {
    if ($m['repeatEnd'] === 1) { $totals['gift'] += $m['repeatCount']; $render(); }
});
$conn->on(WebcastEvent::LIKE, function (array $m) use (&$totals, $render): void {
    $totals['likes'] += $m['likeCount']; $render();
});
```

### Authenticated connection (TikTok account session)

You can supply your account's `sessionid` + `tt-target-idc` cookies for authenticated WebSocket
features (matches the Node lib's `sessionId` option). Extract them from your browser's DevTools after
logging into <https://www.tiktok.com>.

```php
$conn = new TikTokLiveConnection('username', $loop, [
    'sessionId'   => '<account_session_id>',
    'ttTargetIdc' => 'useast1a', // varies by your region
]);
```

> [!CAUTION]
> The session id is a credential. Keep it out of source control and treat it like a password.
> The WebSocket connection is signed by EulerStream (a third party) — only enable authenticated
> mode if you trust the sign server.

## Architecture

- **HTTP** (Guzzle, blocking) handles the one-shot pre-connect work: SIGI_STATE HTML scrape →
  api-live JSON fallback → EulerStream `/webcast/room_id` + `/webcast/fetch`. Blocking I/O is fine
  here because it all happens before the WebSocket loop starts.
- **WebSocket** (Ratchet/Pawl on ReactPHP) carries the realtime stream. Heartbeat + ack frames
  are encoded inline.
- **Protobuf** is hand-rolled ([`src/Protobuf/`](src/Protobuf/)) — only the message types we
  actually surface are decoded; everything else is skipped via wire-type fall-through.
  That keeps the codec tolerant of TikTok adding new fields upstream. To add a new message
  type, drop a `Codec::decodeFoo()` method in
  [`Codec.php`](src/Protobuf/Codec.php) and dispatch it from
  [`TikTokLiveConnection::dispatchInner()`](src/TikTokLiveConnection.php).

## Limitations vs. the Node lib

1. **No `sendMessage()`** — requires authenticated WebSocket + premium EulerStream signing.
2. **Sync HTTP for pre-connect** — fine for a daemon, not ideal for high-concurrency reuse.
3. **No proxy support yet** — Guzzle and Pawl both support proxies; not wired through here.
4. **MVP event coverage** — only chat / gift / like are decoded. Use `RAW_MESSAGE` for everything else.
5. **Schema drift** — TikTok occasionally changes protobuf field numbers. If decoding breaks for
   a message you care about, compare against the Node lib's `tiktok-schema.js` and update the
   field tags in [`Codec.php`](src/Protobuf/Codec.php).

## Contributors

* **Zerody** — original Node.js reverse-engineering & protobuf decoding — [@zerodytrash](https://github.com/zerodytrash/)
* **Isaac Kogan** — Node lib TypeScript rewrite & sign-server — [@isaackogan](https://github.com/isaackogan)
* **nargor** — this PHP port

## License

MIT — same as the upstream Node lib. See [LICENSE](LICENSE).
