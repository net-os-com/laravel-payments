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
     * Credit notes are documents of their own; amounts stay positive and the type tells which way they go.
     */
    public function up(): void
    {
        Schema::table('payment_invoices', function (Blueprint $table): void {
            $table->string('type')->default('invoice')->after('id');
            $table->foreignId('credits_invoice_id')->nullable()->after('type')
                ->constrained('payment_invoices')->restrictOnDelete();
            $table->json('payer')->nullable()->after('payer_email');
            $table->string('pdf_disk')->nullable()->after('credited_at');
            $table->string('pdf_path')->nullable()->after('pdf_disk');
        });
    }

    public function down(): void
    {
        Schema::table('payment_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('credits_invoice_id');
            $table->dropColumn(['type', 'payer', 'pdf_disk', 'pdf_path']);
        });
    }
};
