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
     * Discount codes of both sources. A Stripe code mirrors a coupon with a promotion code; a local code exists only
     * here. The mirror keeps what Stripe has no field for: the description and the start date.
     */
    public function up(): void
    {
        Schema::create('payment_discount_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('code')->unique();
            $table->string('type');
            $table->unsignedTinyInteger('percent_off');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_account_id')->nullable();
            $table->string('provider_discount_id')->nullable();
            $table->string('provider_coupon_id')->nullable();
            $table->unsignedInteger('times_redeemed')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->unsignedBigInteger('deactivated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_discount_codes');
    }
};
