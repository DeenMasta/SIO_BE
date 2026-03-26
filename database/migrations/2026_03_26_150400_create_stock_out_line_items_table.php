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
        Schema::create('stock_out_line_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_out_line_id')->constrained('stock_out_lines')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['stock_out_line_id', 'stock_item_id']);
            $table->index(['stock_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_out_line_items');
    }
};
