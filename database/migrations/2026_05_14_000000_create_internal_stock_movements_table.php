<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->string('movement_number', 50)->unique();
            $table->date('movement_date');
            $table->string('movement_direction', 20);
            $table->string('purpose', 30)->nullable();
            $table->foreignId('original_movement_id')->nullable()->constrained('internal_stock_movements')->restrictOnDelete();
            $table->string('status', 20)->default('POSTED');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['movement_date']);
            $table->index(['movement_direction']);
            $table->index(['purpose']);
            $table->index(['status']);
        });

        Schema::create('internal_stock_movement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('internal_stock_movement_id')->constrained('internal_stock_movements')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['product_id']);
            $table->index(['stock_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_stock_movement_lines');
        Schema::dropIfExists('internal_stock_movements');
    }
};
