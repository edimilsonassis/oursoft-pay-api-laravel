<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notificações recebidas dos bancos (auditoria/reprocessamento).
        Schema::create('inbound_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('provider_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);
            $table->string('path')->nullable();
            $table->json('headers')->nullable();
            $table->longText('body')->nullable();
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        // Eventos enviados aos clientes (ex.: payment.paid), com retentativas.
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 40);
            $table->string('url');
            $table->json('payload');
            $table->string('status', 20)->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('inbound_webhooks');
    }
};
