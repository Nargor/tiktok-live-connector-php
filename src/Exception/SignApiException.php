<?php

declare(strict_types=1);

namespace TikTokLive\Exception;

class SignApiException extends TikTokLiveException
{
    public ?int $statusCode = null;
    public ?string $logId = null;
}
