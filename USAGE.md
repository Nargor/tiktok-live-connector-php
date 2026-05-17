# คู่มือการใช้งาน (ภาษาไทย)

PHP port ของ [tiktok-live-connector](https://github.com/zerodytrash/TikTok-Live-Connector) (Node.js) — ใช้ฟัง chat / gift / like จากห้อง TikTok LIVE แบบ realtime

## สารบัญ

- [Requirements](#requirements)
- [การติดตั้ง](#การติดตั้ง)
- [Quick Start](#quick-start)
- [Events ที่รองรับ](#events-ที่รองรับ)
- [Options](#options)
- [EulerStream sign server](#eulerstream-sign-server)
- [ตัวอย่างการใช้งาน](#ตัวอย่างการใช้งาน)
- [การ deploy](#การ-deploy-เป็น-daemon)
- [Troubleshooting](#troubleshooting)
- [ข้อจำกัด](#ข้อจำกัด)

---

## Requirements

- **PHP 8.2 หรือสูงกว่า**
- PHP extensions: `ext-json`, `ext-mbstring`, `ext-zlib`, `ext-sockets`
- Composer
- การเชื่อมต่ออินเทอร์เน็ตที่ต่อ `tiktok.com` และ `tiktok.eulerstream.com` ได้

ตรวจ PHP version:

```bash
php -v
# ต้องขึ้น PHP 8.2.x หรือสูงกว่า
```

## การติดตั้ง

```bash
composer require nargor/tiktok-live-poll
```

หรือ clone repo มาใช้ตรงๆ:

```bash
git clone https://github.com/nargor/tiktok-live-poll.git
cd tiktok-live-poll
composer install
```

## Quick Start

ไฟล์ `listen.php` สั้นๆ:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use React\EventLoop\Loop;
use TikTokLive\Event\WebcastEvent;
use TikTokLive\TikTokLiveConnection;

$loop = Loop::get();
$conn = new TikTokLiveConnection('officialgeilegisela', $loop);

$conn->on(WebcastEvent::CHAT, function (array $msg): void {
    $user = $msg['user']['uniqueId'] ?? '?';
    echo "[chat] $user: {$msg['comment']}\n";
});

$conn->connect()->then(
    fn ($state) => print("Connected. roomId={$state['roomId']}\n"),
    fn (\Throwable $e) => fwrite(STDERR, "Error: {$e->getMessage()}\n"),
);

$loop->run();
```

รัน:

```bash
php listen.php
```

> เปลี่ยน `officialgeilegisela` เป็น username TikTok ที่กำลัง live อยู่ (เช่นจาก URL `tiktok.com/@username/live` → ใช้ `username`)

## Events ที่รองรับ

### Realtime message events

| Event | เมื่อเกิด | Payload |
|---|---|---|
| `WebcastEvent::CHAT` | มีคนพิมพ์ comment | `['comment' => string, 'user' => ['userId', 'nickname', 'uniqueId']]` |
| `WebcastEvent::GIFT` | มีคนส่ง gift | `['giftId' => int, 'repeatCount' => int, 'repeatEnd' => int, 'user' => [...]]` |
| `WebcastEvent::LIKE` | มีคนกดไลก์ | `['likeCount' => int, 'totalLikeCount' => int, 'user' => [...]]` |

### Lifecycle events

| Event | เมื่อเกิด | Payload |
|---|---|---|
| `WebcastEvent::CONNECTED` | ต่อ WebSocket สำเร็จ | `['roomId' => string]` |
| `WebcastEvent::DISCONNECTED` | หลุดการเชื่อมต่อ | `['code' => int, 'reason' => string]` |
| `WebcastEvent::ERROR` | error ทุกประเภท | `\Throwable` |
| `WebcastEvent::RAW_MESSAGE` | ทุก message รวมที่ยังไม่ decode | `(string $type, string $bytes)` |

### หลักการ handle gift ที่ส่งติดต่อกัน (combo)

เหมือนกับ Node lib — gift บางตัว user ส่งเป็น "streak" หลายชิ้นติดกัน เราจะได้ event หลายรอบ + รอบสุดท้ายที่ `repeatEnd === 1`:

```php
$conn->on(WebcastEvent::GIFT, function (array $msg): void {
    if ($msg['repeatEnd'] === 0) {
        // กำลัง streak อยู่ — แสดงชั่วคราว
        printf("ส่งของขวัญต่อเนื่อง x%d\n", $msg['repeatCount']);
    } else {
        // จบ streak — นับเป็น final
        printf("ส่ง gift %d รวม %d ชิ้น\n", $msg['giftId'], $msg['repeatCount']);
    }
});
```

## Options

```php
$conn = new TikTokLiveConnection('username', $loop, [
    'sessionId' => null,            // session ID ของ TikTok account (ถ้าจะ login ต่อ)
    'ttTargetIdc' => null,          // datacenter cookie (เช่น 'useast1a')
    'signApiKey' => null,           // EulerStream API key (auto-pick จาก env)
    'disableEulerFallbacks' => false, // ปิดการ fallback ไป EulerStream ตอนหา roomId
    'fetchRoomInfoOnConnect' => true, // ดึง room info ก่อน connect (เช็ค offline)
    'connectWithUniqueId' => false,   // ส่ง uniqueId แทน roomId ไปให้ Euler resolve
]);
```

## EulerStream sign server

TikTok บังคับให้ WebSocket URL ต้องถูก "sign" ด้วย algorithm ลับ — เราเลยพึ่ง EulerStream แก้

### ไม่ใส่ key (Free tier)

```bash
php listen.php
```

ใช้งานได้ทันที — แต่ rate limit หนัก (~10 connection/วัน บางช่วง)

### ใส่ key ผ่าน env var

```bash
# Linux/Mac
SIGN_API_KEY=xxx php listen.php

# Windows PowerShell
$env:SIGN_API_KEY="xxx"; php listen.php

# Windows CMD
set SIGN_API_KEY=xxx
php listen.php
```

### ใส่ key ในโค้ดตรงๆ

```php
$conn = new TikTokLiveConnection('username', $loop, [
    'signApiKey' => 'paste_key_here',
]);
```

### สมัครเอา key

ไปที่ <https://www.eulerstream.com> สมัครบัญชี → dashboard → API Keys → Create

## ตัวอย่างการใช้งาน

### 1. log ลงไฟล์

```php
$logFile = fopen('chat.log', 'a');

$conn->on(WebcastEvent::CHAT, function (array $msg) use ($logFile): void {
    $line = sprintf(
        "[%s] %s: %s\n",
        date('Y-m-d H:i:s'),
        $msg['user']['uniqueId'] ?? '?',
        $msg['comment']
    );
    fwrite($logFile, $line);
});
```

### 2. ส่งเข้า database

```php
use PDO;

$pdo = new PDO('mysql:host=localhost;dbname=tiktok', 'user', 'pass');
$stmt = $pdo->prepare('INSERT INTO chats (username, comment, created_at) VALUES (?, ?, NOW())');

$conn->on(WebcastEvent::CHAT, function (array $msg) use ($stmt): void {
    $stmt->execute([
        $msg['user']['uniqueId'] ?? null,
        $msg['comment'],
    ]);
});
```

### 3. forward ไป webhook

```php
use GuzzleHttp\Client;

$http = new Client(['timeout' => 5]);

$conn->on(WebcastEvent::GIFT, function (array $msg) use ($http): void {
    $http->postAsync('https://your-webhook.example.com/gift', [
        'json' => [
            'user' => $msg['user']['uniqueId'] ?? null,
            'gift_id' => $msg['giftId'],
            'count' => $msg['repeatCount'],
        ],
    ]);
});
```

### 4. นับยอด like สะสม

```php
$totalLikes = 0;

$conn->on(WebcastEvent::LIKE, function (array $msg) use (&$totalLikes): void {
    $totalLikes += $msg['likeCount'];
    printf("\rTotal likes: %d", $totalLikes);
});
```

### 5. reconnect อัตโนมัติ

```php
$reconnect = function () use (&$conn, $username, $loop, &$reconnect): void {
    echo "Connecting...\n";
    $conn = new TikTokLiveConnection($username, $loop);
    setupListeners($conn);
    $conn->connect()->then(
        null,
        function (\Throwable $e) use ($loop, $reconnect): void {
            fwrite(STDERR, "Connect failed: {$e->getMessage()}, retry in 30s\n");
            $loop->addTimer(30, $reconnect);
        }
    );
};

$conn->on(WebcastEvent::DISCONNECTED, function () use ($loop, $reconnect): void {
    echo "Disconnected — reconnecting in 10s\n";
    $loop->addTimer(10, $reconnect);
});

$reconnect();
$loop->run();
```

## การ deploy เป็น daemon

### systemd (Linux)

ไฟล์ `/etc/systemd/system/tiktok-listener.service`:

```ini
[Unit]
Description=TikTok LIVE listener
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/tiktok-listener
ExecStart=/usr/bin/php /var/www/tiktok-listener/listen.php username
Restart=always
RestartSec=10
Environment=SIGN_API_KEY=your_key_here

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable tiktok-listener
sudo systemctl start tiktok-listener
sudo journalctl -u tiktok-listener -f
```

### Supervisor

```ini
[program:tiktok-listener]
command=/usr/bin/php /var/www/tiktok-listener/listen.php username
autostart=true
autorestart=true
stderr_logfile=/var/log/tiktok-listener.err.log
stdout_logfile=/var/log/tiktok-listener.out.log
environment=SIGN_API_KEY="your_key_here"
```

### Docker

```dockerfile
FROM php:8.2-cli
RUN apt-get update && apt-get install -y libzip-dev zip \
    && docker-php-ext-install sockets zip \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev
COPY . .
CMD ["php", "listen.php"]
```

## Troubleshooting

### `Failed to extract SIGI_STATE — TikTok may be serving a captcha`

TikTok บล็อก IP ของคุณ / ขึ้น captcha:
- ลองรันจาก IP อื่น (server cloud, VPN)
- ตั้ง `disableEulerFallbacks = false` (ค่า default) — มันจะ fallback ไปใช้ Euler แทน

### `SignApiException: HTTP 429`

EulerStream rate limit:
- รอ 1-2 นาทีลองใหม่
- ลองทำ EulerStream API key (มี free tier ที่ limit สูงกว่า)

### `SignApiException: HTTP 402`

ต้องการ premium feature — ส่วนใหญ่จาก feature ที่เราไม่ได้ใช้ใน MVP ลองดู error message ละเอียด

### `Connect failed: No roomId resolved`

User อาจไม่กำลัง live หรือ username ผิด:

```bash
# เช็คเอง
curl -A "Mozilla/5.0" https://www.tiktok.com/@username/live | grep -o 'roomId":"[0-9]*' | head -1
```

### WebSocket ต่อแล้วไม่ได้ข้อความ

- ตรวจว่าห้อง live จริง (เปิดเว็บดูเอง)
- ดู event `WebcastEvent::ERROR` — อาจมี exception เงียบๆ
- เพิ่ม listener `RAW_MESSAGE` เพื่อ debug:

```php
$conn->on(WebcastEvent::RAW_MESSAGE, function (string $type, string $bytes): void {
    echo "raw: $type (" . strlen($bytes) . " bytes)\n";
});
```

## ข้อจำกัด

### MVP scope

ตัวนี้พอร์ตแค่ **chat / gift / like** ครับ ใน Node lib ต้นฉบับมีอีกหลาย event:
- `member` (เข้าห้อง), `social` (follow/share), `subscribe`, `envelope` (ถุงทอง)
- `questionNew`, `linkMicBattle`, `roomUser` (viewer count)
- ฯลฯ อีก ~30 events

ถ้าต้องการเพิ่ม — ไป edit [src/Protobuf/Codec.php](src/Protobuf/Codec.php) แล้ว dispatch ใน [src/TikTokLiveConnection.php](src/TikTokLiveConnection.php) ที่ method `dispatchInner()`

### Methods ที่ยังไม่ได้พอร์ต

- `sendMessage()` — ส่งข้อความเข้า chat (ต้องมี session ID + premium Euler)
- `fetchAvailableGifts()` — ดึงรายชื่อ gift ทั้งหมด
- `waitUntilLive()` — รอจนกว่า user จะ live
- `fetchRoomInfo()` — ดึง room info แบบ standalone (ตอนนี้เรียกได้แค่ตอน connect)

### อื่นๆ

- ไม่รองรับ HTTP/SOCKS proxy (Guzzle + Pawl ทำได้ แต่ยังไม่ wire)
- ไม่รองรับ `wss://` ผ่าน HTTP proxy
- protobuf decoder รองรับเฉพาะ field ที่ระบุไว้ — field อื่น skip เงียบๆ (ไม่ error)
- TikTok เปลี่ยน schema บ่อย ถ้าฟังก์ชันไหนพัง อาจต้อง update field tag ใน [Codec.php](src/Protobuf/Codec.php)

---

## License

MIT — ดู [LICENSE](LICENSE) เครดิตหลักให้ [Zerody](https://github.com/zerodytrash/) และ [Isaac Kogan](https://github.com/isaackogan) สำหรับการ reverse engineer ต้นฉบับ
