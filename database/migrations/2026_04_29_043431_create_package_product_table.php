<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_product', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
            
            $table->unique(['package_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_product');
    }
};
