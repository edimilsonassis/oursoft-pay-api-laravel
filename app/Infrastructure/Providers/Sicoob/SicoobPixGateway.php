<?php

namespace App\Infrastructure\Providers\Sicoob;

use App\Infrastructure\Providers\Pix\BacenPixGateway;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\PendingRequest;

class SicoobPixGateway extends BacenPixGateway
{
    private const URLS = [
        'production' => 'https://api.sicoob.com.br/pix/api/v2',
        'sandbox' => 'https://sandbox.sicoob.com.br/sicoob/sandbox/pix/api/v2',
    ];

    private const SCOPE = 'cob.read cob.write pix.read pix.write webhook.read webhook.write';

    public function __construct(private readonly SicoobAuth $auth) {}

    protected function providerName(): string
    {
        return 'sicoob';
    }

    protected function client(ProviderAccount $account): PendingRequest
    {
        return $this->auth->client($account, self::URLS[$account->environment->value], self::SCOPE);
    }
}
