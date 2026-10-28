<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pay:client:list')]
#[Description('Lista clientes e suas chaves de API')]
class ListClientsCommand extends Command
{
    public function handle(): int
    {
        $rows = [];
        foreach (Client::with('apiKeys')->withCount('providerAccounts')->orderBy('name')->get() as $client) {
            foreach ($client->apiKeys->whenEmpty(fn ($c) => $c->push(null)) as $key) {
                $rows[] = [
                    $client->id, $client->name, $client->active ? 'sim' : 'não', $client->provider_accounts_count,
                    $key?->prefix, $key?->name, $key?->revoked_at ? 'revogada' : ($key ? 'ativa' : ''),
                    $key?->last_used_at?->format('Y-m-d H:i'),
                ];
            }
        }

        $this->table(['client_id', 'nome', 'ativo', 'contas', 'chave', 'rótulo', 'status', 'último uso'], $rows);

        return self::SUCCESS;
    }
}
