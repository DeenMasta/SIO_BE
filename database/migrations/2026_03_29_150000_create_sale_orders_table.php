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
        Schema::create('sale_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('so_number', 50)->unique();
            $table->date('so_date');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('expected_delivery_date')->nullable();
            $table->string('invoice_number', 50)->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['customer_id']);
            $table->index(['status']);
            $table->index(['so_date']);
            $table->index(['invoice_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_orders');
    }
};
