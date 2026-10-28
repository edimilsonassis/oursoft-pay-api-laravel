<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Valores monetários sempre em centavos (inteiro).
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('provider_account_id')->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('method', 20);
            $table->string('status', 30)->index();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->char('currency', 3)->default('BRL');
            $table->string('description')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->string('provider_reference')->nullable();
            $table->json('payer')->nullable();
            $table->json('details')->nullable();
            $table->json('metadata')->nullable();
            $table->string('failure_reason')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'idempotency_key']);
            $table->index(['client_id', 'external_reference']);
            $table->index(['provider_account_id', 'provider_reference']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status', 20);
            $table->string('reason')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        // Linha do tempo de cada cobrança (auditoria de mudanças de status).
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('payment_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('status', 30)->nullable();
            $table->string('source', 20);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
    }
};
