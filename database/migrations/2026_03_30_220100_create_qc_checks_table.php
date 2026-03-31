<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('qc_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qc_document_id')->nullable()->constrained('quality_checks')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->string('result', 20);              // PASSED | FAILED
            $table->timestamp('checked_at');
            $table->json('checked_conditions')->nullable();
            $table->json('checked_accessories')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['stock_item_id', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qc_items');
    }
};
