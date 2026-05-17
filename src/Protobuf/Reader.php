<?php

declare(strict_types=1);

namespace TikTokLive\Protobuf;

/**
 * Minimal protobuf binary reader.
 *
 * Supports the wire types needed for the TikTok webcast protocol:
 *   - 0 (varint): bool, int32/64, uint32/64
 *   - 2 (length-delimited): string, bytes, embedded message, packed repeated
 *
 * Wire types 1 (64-bit) and 5 (32-bit) are skipped rather than decoded — they
 * are not used by any of the messages this MVP cares about.
 */
final class Reader
{
    public int $pos = 0;
    public int $len;

    public function __construct(public string $buf)
    {
        $this->len = strlen($buf);
    }

    public function eof(): bool
    {
        return $this->pos >= $this->len;
    }

    public function readVarint(): int
    {
        $result = 0;
        $shift = 0;
        while (true) {
            if ($this->pos >= $this->len) {
                throw new \RuntimeException('Truncated varint.');
            }
            $byte = ord($this->buf[$this->pos++]);
            $result |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                break;
            }
            $shift += 7;
            if ($shift > 63) {
                throw new \RuntimeException('Varint too long.');
            }
        }
        return $result;
    }

    /**
     * Reads tag → [fieldNumber, wireType]. Returns null on EOF.
     *
     * @return array{0:int,1:int}|null
     */
    public function readTag(): ?array
    {
        if ($this->eof()) {
            return null;
        }
        $tag = $this->readVarint();
        return [$tag >> 3, $tag & 0x07];
    }

    public function readBytes(): string
    {
        $len = $this->readVarint();
        if ($this->pos + $len > $this->len) {
            throw new \RuntimeException('Truncated length-delimited field.');
        }
        $slice = substr($this->buf, $this->pos, $len);
        $this->pos += $len;
        return $slice;
    }

    public function readString(): string
    {
        return $this->readBytes();
    }

    /**
     * Skip the value for the given wire type (when we don't care about it).
     */
    public function skip(int $wireType): void
    {
        switch ($wireType) {
            case 0:
                $this->readVarint();
                return;
            case 1:
                $this->pos += 8;
                return;
            case 2:
                $len = $this->readVarint();
                $this->pos += $len;
                return;
            case 5:
                $this->pos += 4;
                return;
            default:
                throw new \RuntimeException("Unsupported wire type $wireType");
        }
    }

    /**
     * Walk every field in the message and pass it to the visitor.
     *
     * The visitor receives (fieldNumber, wireType, Reader) and is responsible
     * for consuming the value. If it returns false the field is skipped.
     *
     * @param callable(int,int,Reader):bool $visitor
     */
    public function walk(callable $visitor): void
    {
        while (!$this->eof()) {
            $tag = $this->readTag();
            if ($tag === null) {
                return;
            }
            [$field, $wire] = $tag;
            $before = $this->pos;
            if ($visitor($field, $wire, $this) === false) {
                // Visitor declined; rewind and skip generically
                $this->pos = $before;
                $this->skip($wire);
            }
        }
    }
}
