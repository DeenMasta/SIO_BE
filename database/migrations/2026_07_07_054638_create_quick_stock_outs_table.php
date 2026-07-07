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
        Schema::create('quick_stock_outs', function (Blueprint $table) {
            $table->id();
            $table->string('qso_number')->unique();
            $table->date('qso_date');
            $table->foreignId('customer_id')->constrained();
            $table->string('status')->default('PENDING'); // PENDING, CONVERTED
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quick_stock_outs');
    }
};
