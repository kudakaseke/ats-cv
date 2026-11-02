<?php

namespace App\Services;

class CvDocumentIdentity
{
    public static function normalise(string $text): string
    {
        // HTML form submission converts LF to CRLF; this does not change a CV.
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    public static function same(string $left, string $right): bool
    {
        return hash_equals(hash('sha256', self::normalise($left)), hash('sha256', self::normalise($right)));
    }

    public static function signature(string $job, string $resume): string
    {
        $identity = [self::normalise($job), self::normalise($resume)];
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }
}
