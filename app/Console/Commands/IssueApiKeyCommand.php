<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\Client;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pay:key:issue {client : ID do cliente} {--name=default : Rótulo da chave} {--expires= : Data de expiração (Y-m-d)}')]
#[Description('Emite uma nova chave de API para um cliente')]
class IssueApiKeyCommand extends Command
{
    public function handle(): int
    {
        $client = Client::findOrFail($this->argument('client'));
        $expires = $this->option('expires') ? now()->parse($this->option('expires'))->endOfDay() : null;

        [$key, $plain] = ApiKey::issue($client, $this->option('name'), $expires);

        $this->components->info("Chave {$key->prefix}… emitida para {$client->name}.");
        $this->line($plain);
        $this->components->warn('Guarde a chave agora: ela não poderá ser exibida novamente.');

        return self::SUCCESS;
    }
}
