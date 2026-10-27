<?php

namespace App\Exceptions;

class AiResponseException extends \RuntimeException
{
    public function __construct(public readonly int $providerStatus, public readonly string $failureReason)
    {
        parent::__construct('AI response failed (HTTP '.$providerStatus.'; '.$failureReason.').');
    }
}
