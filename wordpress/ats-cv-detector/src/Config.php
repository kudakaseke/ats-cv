<?php

namespace ATSCV;

final class Config
{
    public static function get(string $name, mixed $default = ''): mixed
    {
        $key = 'ATSCV_'.strtoupper($name);
        $value = defined($key) ? constant($key) : (($value = getenv($key)) !== false ? $value : $default);

        return apply_filters('atscv_config', $value, $name);
    }

    public static function enabled(string $name): bool
    {
        return filter_var(self::get($name, false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function plans(): array
    {
        return [
            'free' => ['name' => 'Free', 'cents' => 0, 'credits' => 15, 'interval' => 'free'],
            'monthly' => ['name' => 'Monthly', 'cents' => 475, 'credits' => 150, 'interval' => 'monthly'],
            'pro' => ['name' => 'Pro', 'cents' => 650, 'credits' => 225, 'interval' => 'monthly'],
            'annual' => ['name' => 'Annual', 'cents' => 4740, 'credits' => 2400, 'interval' => 'annually'],
        ];
    }

    public static function crypt(string $value, bool $decrypt = false): string
    {
        $key = hash('sha256', wp_salt('auth'), true);
        if (! $decrypt) {
            $nonce = random_bytes(12);
            $tag = '';
            $encrypted = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            if ($encrypted === false) {
                throw new \RuntimeException('Encryption unavailable.');
            }

            return base64_encode($nonce.$tag.$encrypted);
        }
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Encrypted data unavailable.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new \RuntimeException('Stored data cannot be decrypted. Preserve WordPress authentication salts.');
        }

        return $plain;
    }

    public static function page(): string
    {
        return get_permalink((int) get_option('atscv_page_id')) ?: home_url('/');
    }
}
