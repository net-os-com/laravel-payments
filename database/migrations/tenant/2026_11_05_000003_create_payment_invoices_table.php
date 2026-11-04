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
     * Invoices issued by the local driver. Immutable once issued; a refund adds a credit, never an edit.
     */
    public function up(): void
    {
        Schema::create('payment_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
            $table->string('status');
            $table->string('reference')->index();
            $table->json('issuer');
            $table->string('payer_name');
            $table->string('payer_email');
            $table->json('lines');
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('tax_amount');
            $table->unsignedInteger('total');
            $table->string('currency', 3);
            $table->unsignedInteger('credited_amount')->default(0);
            $table->timestamp('issued_at');
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_invoices');
    }
};
