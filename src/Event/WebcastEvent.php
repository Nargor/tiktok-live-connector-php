<?php

declare(strict_types=1);

namespace TikTokLive\Event;

/**
 * Event-name constants. We follow the casing of the Node lib so that future
 * additions (member, social, follow, …) can drop in without breaking existing
 * listeners.
 */
final class WebcastEvent
{
    // Control / lifecycle
    public const CONNECTED = 'connected';
    public const DISCONNECTED = 'disconnected';
    public const ERROR = 'error';
    public const RAW_MESSAGE = 'rawMessage';

    // MVP message events
    public const CHAT = 'chat';
    public const GIFT = 'gift';
    public const LIKE = 'like';
}
