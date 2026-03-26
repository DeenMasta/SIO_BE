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
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('product_code', 50)->unique();
            $table->string('product_name', 150);
            $table->string('product_type', 20);
            $table->decimal('selling_price', 15, 2)->default(0);
            $table->string('uom', 20);
            $table->unsignedInteger('reorder_level')->default(0);
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['product_name']);
            $table->index(['product_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
