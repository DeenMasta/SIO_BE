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
        Schema::create('customer_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_return_id')->constrained('customer_returns')->cascadeOnDelete();
            $table->foreignId('original_stock_out_line_id')->nullable()->constrained('stock_out_lines')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
            $table->unsignedInteger('qty');
            $table->string('reason_for_return', 255);
            $table->string('condition_on_return', 255)->nullable();
            $table->string('next_action', 50)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['customer_return_id']);
            $table->index(['product_id']);
            $table->index(['stock_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_return_lines');
    }
};
