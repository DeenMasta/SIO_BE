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
        Schema::create('return_to_supplier_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_to_supplier_id')->constrained('return_to_supplier')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
            $table->unsignedInteger('qty');
            $table->string('reason_for_return', 255);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['return_to_supplier_id']);
            $table->index(['product_id']);
            $table->index(['stock_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_to_supplier_lines');
    }
};
