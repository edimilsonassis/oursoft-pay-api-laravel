<?php

namespace App\Http\Presenters;

use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Data\ProviderField;
use App\Domain\Payments\Enums\Environment;
use App\Domain\Payments\Enums\PaymentMethod;

final class ProviderPresenter
{
    public static function present(PaymentProvider $provider): array
    {
        return [
            'id' => $provider->name()->value,
            'object' => 'provider',
            'name' => $provider->label(),
            'methods' => array_map(fn (PaymentMethod $m) => $m->value, $provider->methods()),
            'environments' => array_map(fn (Environment $e) => $e->value, Environment::cases()),
            'fields' => array_map(fn (ProviderField $f) => $f->toArray(), $provider->fields()),
        ];
    }
}
