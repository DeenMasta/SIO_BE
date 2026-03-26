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
        Schema::create('stock_in_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_in_id')->constrained('stock_in')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('received_qty');
            $table->string('condition_at_receiving', 50)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['stock_in_id']);
            $table->index(['product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_in_lines');
    }
};
