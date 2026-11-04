<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection(config('payments.connections.central'))->create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('provider_event_id');
            $table->string('provider_account_id')->nullable();
            $table->string('type');
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('exception')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('payments.connections.central'))->dropIfExists('payment_webhook_events');
    }
};
