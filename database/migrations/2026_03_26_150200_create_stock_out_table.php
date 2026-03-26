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
        Schema::create('stock_out', function (Blueprint $table): void {
            $table->id();
            $table->string('stock_out_number', 50)->unique();
            $table->string('idempotency_key', 80)->unique();
            $table->date('stock_out_date');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('invoice_number', 50);
            $table->foreignId('pic_id')->constrained('users')->restrictOnDelete();
            $table->string('pick_list_reference', 50)->nullable();
            $table->boolean('packing_verified')->default(false);
            $table->string('status', 20)->default('POSTED');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['stock_out_date']);
            $table->index(['customer_id']);
            $table->index(['status']);
            $table->index(['invoice_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_out');
    }
};
