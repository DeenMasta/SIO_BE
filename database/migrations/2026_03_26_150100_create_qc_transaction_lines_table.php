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
        Schema::create('qc_transaction_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qc_transaction_id')->constrained('qc_transactions')->cascadeOnDelete();
            $table->foreignId('stock_in_line_id')->constrained('stock_in_lines')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
            $table->string('qc_result', 10);
            $table->unsignedInteger('qty_pass')->default(0);
            $table->unsignedInteger('qty_fail')->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['qc_transaction_id']);
            $table->index(['product_id']);
            $table->index(['stock_item_id']);
            $table->index(['qc_result']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qc_transaction_lines');
    }
};
