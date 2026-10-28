<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aplicações consumidoras do gateway (ex.: API do CFC, super-painel).
        Schema::create('clients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->string('webhook_url')->nullable();
            $table->text('webhook_secret');
            $table->timestamps();
        });

        // Chaves de API: guardamos só o hash SHA-256; o texto puro é exibido uma única vez.
        Schema::create('api_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('prefix', 16);
            $table->char('key_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('clients');
    }
};
