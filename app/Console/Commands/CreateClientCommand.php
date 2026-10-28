<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\Client;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('pay:client:create {name : Nome da aplicação cliente} {--webhook-url= : URL HTTPS para receber eventos}')]
#[Description('Cadastra um cliente (aplicação consumidora) e emite a primeira chave de API')]
class CreateClientCommand extends Command
{
    public function handle(): int
    {
        $client = Client::create([
            'name' => $this->argument('name'),
            'webhook_url' => $this->option('webhook-url'),
            'webhook_secret' => 'whsec_'.Str::random(40),
        ]);

        [, $plain] = ApiKey::issue($client, 'default');

        $this->components->info("Cliente {$client->name} criado.");
        $this->table(['Campo', 'Valor'], [
            ['client_id', $client->id],
            ['api_key', $plain],
            ['webhook_secret', $client->webhook_secret],
        ]);
        $this->components->warn('Guarde a api_key agora: ela não poderá ser exibida novamente.');

        return self::SUCCESS;
    }
}
