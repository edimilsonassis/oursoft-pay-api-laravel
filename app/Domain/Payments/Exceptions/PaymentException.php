<?php

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

/**
 * Violação de regra de negócio do gateway (vira HTTP 422 com `code`).
 */
class PaymentException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function methodNotSupported(string $method, string $provider): self
    {
        return new self('method_not_supported', "O método {$method} não é suportado pela conta ({$provider}).");
    }

    public static function invalidState(string $action, string $status): self
    {
        return new self('invalid_payment_state', "Não é possível {$action} uma cobrança com status {$status}.");
    }
}
