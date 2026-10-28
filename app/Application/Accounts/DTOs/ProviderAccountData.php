<?php

namespace App\Application\Accounts\DTOs;

use App\Domain\Payments\Enums\Environment;
use App\Domain\Payments\Enums\Provider;

/**
 * Campos nulos não são alterados numa atualização. `credentials` e `settings`
 * são mesclados com os valores já gravados.
 */
final readonly class ProviderAccountData
{
    public function __construct(
        public ?Provider $provider = null,
        public ?Environment $environment = null,
        public ?string $name = null,
        public ?string $externalReference = null,
        public ?array $credentials = null,
        public ?array $settings = null,
        public ?bool $isDefault = null,
        public ?bool $active = null,
    ) {}
}
