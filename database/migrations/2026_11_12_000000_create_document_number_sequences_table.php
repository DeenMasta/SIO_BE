<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_number_sequences', function (Blueprint $table): void {
            $table->string('sequence_key', 191)->primary();
            $table->unsignedBigInteger('last_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_number_sequences');
    }
};
