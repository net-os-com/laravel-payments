<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function getConnection(): ?string
    {
        return config('payments.connections.tenant');
    }

    /**
     * A one-time code is held for one open checkout, so two customers cannot both redeem it.
     */
    public function up(): void
    {
        Schema::table('payment_discount_codes', function (Blueprint $table): void {
            $table->string('reserved_reference')->nullable()->after('times_redeemed');
            $table->timestamp('reserved_until')->nullable()->after('reserved_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payment_discount_codes', function (Blueprint $table): void {
            $table->dropColumn(['reserved_reference', 'reserved_until']);
        });
    }
};
