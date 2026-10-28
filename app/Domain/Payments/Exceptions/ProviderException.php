<?php

namespace App\Domain\Payments\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Falha de comunicação ou recusa do banco (vira HTTP 502 com detalhes do banco).
 */
class ProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        string $message,
        public readonly ?int $providerStatus = null,
        public readonly mixed $providerBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
