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
        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('qty_received_pending_qc')->default(0);
            $table->unsignedInteger('qty_in_stock')->default(0);
            $table->unsignedInteger('qty_delivered')->default(0);
            $table->unsignedInteger('qty_under_repair')->default(0);
            $table->unsignedInteger('qty_returned')->default(0);
            $table->unsignedInteger('qty_returned_to_supplier')->default(0);
            $table->timestamps();

            $table->index(['qty_in_stock']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
