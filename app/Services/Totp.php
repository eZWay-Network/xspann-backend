<?php

namespace App\Services;

use function chr;
use function ord;
use function strlen;

/** RFC 6238: SHA-1, six digits, 30-second time steps. */
class Totp
{
    public static function secret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }

        return $secret;
    }

    public static function code(string $secret, int $counter): string
    {
        if (!preg_match('/^[A-Z2-7]{16,128}$/D', $secret) || $counter < 0) {
            throw new \InvalidArgumentException('Invalid TOTP input.');
        }

        $bits = '';
        foreach (str_split($secret) as $character) {
            $bits .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $character)), 5, '0', STR_PAD_LEFT);
        }

        $key = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
            $key .= chr(bindec(substr($bits, $i, 8)));
        }

        $digest = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $key, true);
        $offset = ord($digest[19]) & 15;
        $number = unpack('N', substr($digest, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function counter(string $secret, string $code, int $lastCounter = -1, ?int $time = null): ?int
    {
        if (!preg_match('/^\d{6}$/D', $code)) {
            return null;
        }

        $current = intdiv($time ?? time(), 30);
        foreach ([$current, $current - 1, $current + 1] as $counter) {
            if ($counter > $lastCounter && hash_equals(self::code($secret, $counter), $code)) {
                return $counter;
            }
        }

        return null;
    }
}
