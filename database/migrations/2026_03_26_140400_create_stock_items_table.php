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
        Schema::create('stock_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('stock_in_line_id')->constrained('stock_in_lines')->cascadeOnDelete();
            $table->string('serial_number', 80)->unique();
            $table->string('serial_source', 20);
            $table->string('current_status', 30)->default('RECEIVED');
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_movement_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['product_id']);
            $table->index(['stock_in_line_id']);
            $table->index(['current_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_items');
    }
};
