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
     * Local VAT rates: a rate change is a new row, not a code change.
     */
    public function up(): void
    {
        Schema::create('payment_tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('country', 2);
            $table->string('category');
            $table->decimal('rate', 5, 2);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->index(['country', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_tax_rates');
    }
};
