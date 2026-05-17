<?php

declare(strict_types=1);

namespace TikTokLive;

use TikTokLive\Exception\InvalidUniqueIdException;

final class Utilities
{
    public static function validateAndNormalizeUniqueId(string $uniqueId): string
    {
        $uniqueId = trim($uniqueId);
        if ($uniqueId === '') {
            throw new InvalidUniqueIdException('Empty uniqueId.');
        }
        $uniqueId = str_replace('https://www.tiktok.com/', '', $uniqueId);
        $uniqueId = str_replace('/live', '', $uniqueId);
        $uniqueId = ltrim($uniqueId, '@');
        $uniqueId = trim($uniqueId);
        if ($uniqueId === '') {
            throw new InvalidUniqueIdException('Invalid uniqueId after normalization.');
        }
        return $uniqueId;
    }
}
