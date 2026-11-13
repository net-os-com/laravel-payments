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
     * The last number handed out per series and year, so local documents are numbered without gaps.
     */
    public function up(): void
    {
        Schema::create('payment_document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('series');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['series', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_document_sequences');
    }
};
