<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection(config('payments.connections.central'))->create('payment_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type');
            $table->string('owner_id');
            $table->string('provider');
            $table->string('provider_account_id');
            $table->string('status');
            $table->string('business_name')->nullable();
            $table->string('email')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('requirements_due')->nullable();
            $table->string('connected_by')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_account_id']);
            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('payments.connections.central'))->dropIfExists('payment_accounts');
    }
};
