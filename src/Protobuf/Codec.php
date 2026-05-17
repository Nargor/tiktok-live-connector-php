<?php

declare(strict_types=1);

namespace TikTokLive\Protobuf;

/**
 * High-level codec for the handful of message types the MVP cares about.
 *
 * Field tags below were extracted from tiktok-live-connector's compiled
 * tiktok-schema.js (the Node lib's protobufjs output). Only the fields we
 * actually surface to PHP consumers are decoded — the rest are skipped via
 * the wire-type fall-through in Reader::walk(). That keeps us schema-light
 * while remaining tolerant of new fields TikTok may add upstream.
 */
final class Codec
{
    /* ---------- WebcastPushFrame (the WebSocket envelope) ---------- */

    /**
     * @return array{
     *   seqId:string, logId:string, payloadEncoding:string,
     *   payloadType:string, payload:string, headers:array<string,string>
     * }
     */
    public static function decodePushFrame(string $bytes): array
    {
        $out = [
            'seqId' => '0',
            'logId' => '0',
            'payloadEncoding' => '',
            'payloadType' => '',
            'payload' => '',
            'headers' => [],
        ];
        (new Reader($bytes))->walk(function (int $field, int $wire, Reader $r) use (&$out): bool {
            switch ($field) {
                case 1: $out['seqId'] = (string) $r->readVarint(); return true;
                case 2: $out['logId'] = (string) $r->readVarint(); return true;
                case 3: case 4: return false; // service / method (skip)
                case 5: // headers (map<string,string>)
                    $entry = $r->readBytes();
                    [$k, $v] = self::decodeStringMapEntry($entry);
                    $out['headers'][$k] = $v;
                    return true;
                case 6: $out['payloadEncoding'] = $r->readString(); return true;
                case 7: $out['payloadType'] = $r->readString(); return true;
                case 8: $out['payload'] = $r->readBytes(); return true;
                default: return false;
            }
        });
        return $out;
    }

    public static function encodePushFrame(string $payloadType, string $payload, string $payloadEncoding = 'pb', string $logId = '0'): string
    {
        $w = new Writer();
        // We deliberately omit seqId/logId/service/method when they are "0" — that
        // matches the TS createBaseWebcastPushFrame behaviour.
        if ($logId !== '0' && $logId !== '') {
            $w->writeInt64StringField(2, $logId);
        }
        if ($payloadEncoding !== '') {
            $w->writeStringField(6, $payloadEncoding);
        }
        if ($payloadType !== '') {
            $w->writeStringField(7, $payloadType);
        }
        if ($payload !== '') {
            $w->writeBytesField(8, $payload);
        }
        return $w->getBytes();
    }

    /* ---------- ProtoMessageFetchResult (the payload inside PushFrame) ---------- */

    /**
     * @return array{
     *   messages: list<array{type:string,payload:string}>,
     *   cursor:string, internalExt:string,
     *   wsUrl:string, wsParams:array<string,string>,
     *   needsAck:bool, heartBeatDuration:int
     * }
     */
    public static function decodeFetchResult(string $bytes): array
    {
        $out = [
            'messages' => [],
            'cursor' => '',
            'internalExt' => '',
            'wsUrl' => '',
            'wsParams' => [],
            'needsAck' => false,
            'heartBeatDuration' => 0,
        ];
        (new Reader($bytes))->walk(function (int $field, int $wire, Reader $r) use (&$out): bool {
            switch ($field) {
                case 1: // BaseProtoMessage
                    $out['messages'][] = self::decodeBaseProtoMessage($r->readBytes());
                    return true;
                case 2: $out['cursor'] = $r->readString(); return true;
                case 5: $out['internalExt'] = $r->readString(); return true;
                case 7: // wsParams entry
                    [$k, $v] = self::decodeStringMapEntry($r->readBytes());
                    $out['wsParams'][$k] = $v;
                    return true;
                case 8: $out['heartBeatDuration'] = $r->readVarint(); return true;
                case 9: $out['needsAck'] = $r->readVarint() !== 0; return true;
                case 10: $out['wsUrl'] = $r->readString(); return true;
                default: return false;
            }
        });
        return $out;
    }

    /**
     * @return array{type:string,payload:string}
     */
    private static function decodeBaseProtoMessage(string $bytes): array
    {
        $out = ['type' => '', 'payload' => ''];
        (new Reader($bytes))->walk(function (int $field, int $wire, Reader $r) use (&$out): bool {
            switch ($field) {
                case 1: $out['type'] = $r->readString(); return true;
                case 2: $out['payload'] = $r->readBytes(); return true;
                default: return false;
            }
        });
        return $out;
    }

    /* ---------- Message bodies ---------- */

    /**
     * @return array{userId:string,nickname:string,uniqueId:string}
     */
    public static function decodeUser(string $bytes): array
    {
        $out = ['userId' => '0', 'nickname' => '', 'uniqueId' => ''];
        (new Reader($bytes))->walk(function (int $field, int $wire, Reader $r) use (&$out): bool {
            switch ($field) {
                case 1:  $out['userId']   = (string) $r->readVarint(); return true;
                case 3:  $out['nickname'] = $r->readString();          return true;
                case 38: $out['uniqueId'] = $r->readString();          return true;
                default: return false;
            }
        });
        return $out;
    }

    /**
     * @return array{comment:string,user:array{userId:string,nickname:string,uniqueId:string}|null}
     */
    public static function decodeChat(string $bytes): array
    {
        $out = ['comment' => '', 'user' => null];
        (new Reader($bytes))->walk(function (int $field, int $wire, Reader $r) use (&$out): bool {
            switch ($field) {
                case 2: $out['user'] = self::decodeUser($r->readBytes()); return true;
                case 3: $out['comment'] = $r->readString(); return true;
                default: return false;
            }
        });
        return $out;
    }

    /**
     * @return array{
     *   giftId:int, repeatCount:int, repeatEnd:int,
     *   user:array{userId:string,nickname:string,uniqueId:string}|null
     * }
     */
    public static function decodeGift(string $bytes): array
    {
        $out = ['giftId' => 0, 'repeatCount' => 0, 'repeatEnd' => 0, 'user' => null];
        (new Reader($bytes))->walk(function (int $field, int $wire, Reader $r) use (&$out): bool {
            switch ($field) {
                case 2: $out['giftId']      = $r->readVarint(); return true;
                case 5: $out['repeatCount'] = $r->readVarint(); return true;
                case 7: $out['user']        = self::decodeUser($r->readBytes()); return true;
                case 9: $out['repeatEnd']   = $r->readVarint(); return true;
                default: return false;
            }
        });
        return $out;
    }

    /**
     * @return array{
     *   likeCount:int, totalLikeCount:int,
     *   user:array{userId:string,nickname:string,uniqueId:string}|null
     * }
     */
    public static function decodeLike(string $bytes): array
    {
        $out = ['likeCount' => 0, 'totalLikeCount' => 0, 'user' => null];
        (new Reader($bytes))->walk(function (int $field, int $wire, Reader $r) use (&$out): bool {
            switch ($field) {
                case 2: $out['likeCount']      = $r->readVarint(); return true;
                case 3: $out['totalLikeCount'] = $r->readVarint(); return true;
                case 5: $out['user']           = self::decodeUser($r->readBytes()); return true;
                default: return false;
            }
        });
        return $out;
    }

    /* ---------- Encoders for outgoing frames ---------- */

    public static function encodeHeartbeat(string $roomId): string
    {
        $w = new Writer();
        if ($roomId !== '0' && $roomId !== '') {
            $w->writeInt64StringField(1, $roomId);
        }
        // sendPacketSeqId — always "1" in the JS source; safe to omit when 0.
        $w->writeInt64StringField(2, '1');
        return $w->getBytes();
    }

    public static function encodeImEnterRoom(string $roomId): string
    {
        $w = new Writer();
        if ($roomId !== '0' && $roomId !== '') {
            $w->writeInt64StringField(1, $roomId);
        }
        $w->writeStringField(5, 'audience');   // identity
        $w->writeInt64StringField(4, '12');    // liveId
        return $w->getBytes();
    }

    /* ---------- Helpers ---------- */

    /**
     * @return array{0:string,1:string}
     */
    private static function decodeStringMapEntry(string $bytes): array
    {
        $k = $v = '';
        (new Reader($bytes))->walk(function (int $field, int $wire, Reader $r) use (&$k, &$v): bool {
            switch ($field) {
                case 1: $k = $r->readString(); return true;
                case 2: $v = $r->readString(); return true;
                default: return false;
            }
        });
        return [$k, $v];
    }
}
