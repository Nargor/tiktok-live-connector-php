<?php

declare(strict_types=1);

/**
 * Minimal TikTok LIVE chat reader.
 *
 *   composer install
 *   php examples/chat-reader.php <tiktok-username>
 */

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use TikTokLive\Event\WebcastEvent;
use TikTokLive\TikTokLiveConnection;

$username = $argv[1] ?? null;
if ($username === null) {
    fwrite(STDERR, "Usage: php chat-reader.php <tiktok-username>\n");
    exit(1);
}

$loop = Loop::get();
// SIGN_API_KEY env var is picked up automatically if set.
// Without it, EulerStream's free tier is used (rate-limited).
$conn = new TikTokLiveConnection($username, $loop);

$conn->on(WebcastEvent::CHAT, function (array $msg): void {
    $user = $msg['user']['uniqueId'] ?? ($msg['user']['nickname'] ?? '?');
    echo "[chat] $user: {$msg['comment']}\n";
});

$conn->on(WebcastEvent::GIFT, function (array $msg): void {
    $user = $msg['user']['uniqueId'] ?? '?';
    echo "[gift] $user sent giftId={$msg['giftId']} x{$msg['repeatCount']} repeatEnd={$msg['repeatEnd']}\n";
});

$conn->on(WebcastEvent::LIKE, function (array $msg): void {
    $user = $msg['user']['uniqueId'] ?? '?';
    echo "[like] $user +{$msg['likeCount']} (total {$msg['totalLikeCount']})\n";
});

$conn->on(WebcastEvent::DISCONNECTED, function (array $info): void {
    echo "[disconnected] code={$info['code']} reason={$info['reason']}\n";
});

$conn->on(WebcastEvent::ERROR, function (\Throwable $e): void {
    fwrite(STDERR, "[error] " . $e->getMessage() . "\n");
});

echo "Connecting to @$username ...\n";
$conn->connect()->then(
    function (array $state): void {
        echo "Connected. roomId={$state['roomId']}\n";
    },
    function (\Throwable $e): void {
        fwrite(STDERR, "Connect failed: " . $e->getMessage() . "\n");
        Loop::get()->stop();
        exit(1);
    },
);

pcntl_async_signals(true);
foreach ([SIGINT, SIGTERM] as $sig) {
    if (defined((string) $sig)) {
        pcntl_signal($sig, function () use ($conn): void {
            echo "Caught signal — disconnecting.\n";
            $conn->disconnect();
            Loop::get()->stop();
        });
    }
}

$loop->run();
