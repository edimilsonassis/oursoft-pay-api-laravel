<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pay:key:revoke {prefix : Prefixo da chave (12 primeiros caracteres)}')]
#[Description('Revoga uma chave de API')]
class RevokeApiKeyCommand extends Command
{
    public function handle(): int
    {
        $count = ApiKey::where('prefix', $this->argument('prefix'))->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $count
            ? $this->components->info("{$count} chave(s) revogada(s).")
            : $this->components->error('Nenhuma chave ativa com esse prefixo.');

        return $count ? self::SUCCESS : self::FAILURE;
    }
}
