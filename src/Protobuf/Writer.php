<?php

declare(strict_types=1);

namespace TikTokLive\Protobuf;

final class Writer
{
    private string $buf = '';

    public function getBytes(): string
    {
        return $this->buf;
    }

    public function appendRaw(string $bytes): void
    {
        $this->buf .= $bytes;
    }

    public function writeVarint(int $value): void
    {
        // PHP int is 64-bit signed on 64-bit platforms. For negative values
        // protobuf encodes the two's-complement form into 10 bytes.
        if ($value < 0) {
            // Encode as 10 bytes (uint64 two's complement) — same as protobuf reference.
            $this->buf .= chr(($value & 0x7F) | 0x80);
            for ($i = 0; $i < 8; $i++) {
                $value = ($value >> 7) & ~(0x7F << 57); // logical shift
                $this->buf .= chr(($value & 0x7F) | 0x80);
            }
            $this->buf .= chr(0x01);
            return;
        }
        while ($value > 0x7F) {
            $this->buf .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }
        $this->buf .= chr($value & 0x7F);
    }

    public function writeTag(int $fieldNumber, int $wireType): void
    {
        $this->writeVarint(($fieldNumber << 3) | $wireType);
    }

    public function writeVarintField(int $fieldNumber, int $value): void
    {
        $this->writeTag($fieldNumber, 0);
        $this->writeVarint($value);
    }

    public function writeBoolField(int $fieldNumber, bool $value): void
    {
        $this->writeTag($fieldNumber, 0);
        $this->writeVarint($value ? 1 : 0);
    }

    public function writeStringField(int $fieldNumber, string $value): void
    {
        $this->writeTag($fieldNumber, 2);
        $this->writeVarint(strlen($value));
        $this->buf .= $value;
    }

    public function writeBytesField(int $fieldNumber, string $value): void
    {
        $this->writeStringField($fieldNumber, $value);
    }

    /**
     * Allow caller to pass an int64 number rendered as a base-10 string
     * (used by the TS source for int64 fields). This keeps very large
     * room ids safe on 32-bit installs even though 64-bit is required.
     */
    public function writeInt64StringField(int $fieldNumber, string $value): void
    {
        $intVal = is_numeric($value) ? (int) $value : 0;
        $this->writeVarintField($fieldNumber, $intVal);
    }
}
