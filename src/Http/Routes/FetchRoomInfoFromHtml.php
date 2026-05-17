<?php

declare(strict_types=1);

namespace TikTokLive\Http\Routes;

use TikTokLive\Http\WebClient;

/**
 * Scrape Room ID + live status from the @username/live HTML page.
 *
 * TikTok embeds a JSON blob in a <script id="SIGI_STATE"> tag. The Node lib
 * relies on the exact same regex; we just port it.
 */
final class FetchRoomInfoFromHtml
{
    public function __construct(private readonly WebClient $client)
    {
    }

    /**
     * @return array<string,mixed> the `liveRoomUserInfo` object from SIGI_STATE
     */
    public function call(string $uniqueId): array
    {
        $html = $this->client->getHtml('@' . $uniqueId . '/live');

        if (!preg_match(
            '/<script id="SIGI_STATE" type="application\/json">(.*?)<\/script>/s',
            $html,
            $matches
        )) {
            throw new \RuntimeException(
                'Failed to extract SIGI_STATE — TikTok may be serving a captcha or blocking this IP.'
            );
        }

        $sigiState = json_decode($matches[1], true);
        if (!is_array($sigiState)) {
            throw new \RuntimeException('Failed to parse SIGI_STATE JSON.');
        }

        $liveRoom = $sigiState['LiveRoom']['liveRoomUserInfo'] ?? null;
        if (!is_array($liveRoom)) {
            throw new \RuntimeException('liveRoomUserInfo missing from SIGI_STATE.');
        }
        return $liveRoom;
    }
}
