<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Payments;

/** Firma dei webhook di Stripe (intestazione Stripe-Signature «t=…,v1=…»), dallo StripeWebhook del plugin. */
final class StripeWebhook
{
    public const TOLERANCE_SECONDS = 300;

    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    public static function header(string $payload, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.self::sign($payload, $secret, $timestamp);
    }

    public static function verify(string $payload, string $header, string $secret, int $now, int $tolerance = self::TOLERANCE_SECONDS): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $timestamp = ctype_digit($kv[1]) ? (int) $kv[1] : null;
            } elseif ($kv[0] === 'v1') {
                $signatures[] = $kv[1];
            }
        }
        if ($timestamp === null || $signatures === [] || abs($now - $timestamp) > $tolerance) {
            return false;
        }
        $expected = self::sign($payload, $secret, $timestamp);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
