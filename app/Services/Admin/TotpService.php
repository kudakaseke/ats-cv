<?php

namespace App\Services\Admin;

class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function secret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function uri(string $secret, string $email): string
    {
        $label = rawurlencode('ATS CV Detector:'.$email);

        return 'otpauth://totp/'.$label.'?secret='.$secret.'&issuer='.rawurlencode('ATS CV Detector').'&algorithm=SHA1&digits=6&period=30';
    }

    public function matchingCounter(string $secret, string $code, ?int $lastCounter = null, ?int $at = null): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $counter = intdiv($at ?? time(), 30);
        foreach ([$counter - 1, $counter, $counter + 1] as $candidate) {
            if ($candidate > ($lastCounter ?? -1) && hash_equals($this->code($secret, $candidate), $code)) {
                return $candidate;
            }
        }

        return null;
    }

    public function code(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $binary = pack('N2', 0, $counter);
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public function recoveryCodes(int $count = 8): array
    {
        return array_map(fn () => strtoupper(bin2hex(random_bytes(5))), range(1, $count));
    }

    public function base32Encode(string $value): string
    {
        $bits = '';
        foreach (str_split($value) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $bits = '';
        foreach (str_split(rtrim(strtoupper($value), '=')) as $character) {
            $index = strpos(self::ALPHABET, $character);
            if ($index === false) {
                throw new \InvalidArgumentException('Invalid authenticator secret.');
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
