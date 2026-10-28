<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Conta bancária (credenciais de um banco) que um cliente usa para operar.
        // Um cliente pode ter várias (ex.: uma por CFC), de bancos diferentes.
        Schema::create('provider_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('client_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('environment', 20);
            $table->string('name');
            $table->string('external_reference')->nullable();
            $table->text('credentials');
            $table->json('settings')->nullable();
            $table->string('webhook_token', 64);
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['client_id', 'external_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_accounts');
    }
};
