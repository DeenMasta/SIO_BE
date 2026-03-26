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
        Schema::create('stock_in', function (Blueprint $table): void {
            $table->id();
            $table->string('stock_in_number', 50)->unique();
            $table->date('stock_in_date');
            $table->string('delivery_order_number', 50)->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('stock_in_pic_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('qc_person_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('DRAFT');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['stock_in_date']);
            $table->index(['supplier_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_in');
    }
};
