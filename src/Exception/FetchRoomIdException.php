<?php

declare(strict_types=1);

namespace TikTokLive\Exception;

class FetchRoomIdException extends TikTokLiveException
{
    /** @var array<int,\Throwable> */
    public array $causes;

    /**
     * @param array<int,\Throwable> $causes
     */
    public function __construct(string $message, array $causes = [])
    {
        parent::__construct($message);
        $this->causes = $causes;
    }
}
