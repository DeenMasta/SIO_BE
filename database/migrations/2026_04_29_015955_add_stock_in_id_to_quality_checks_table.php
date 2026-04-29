<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds stock_in_id (nullable FK) to quality_checks and enforces 1-to-1 uniqueness
     * so that each stock-in session can only have one QC document linked to it.
     */
    public function up(): void
    {
        Schema::table('quality_checks', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_in_id')->nullable()->after('pic_id');
            $table->foreign('stock_in_id')->references('id')->on('stock_in')->restrictOnDelete();
            $table->unique('stock_in_id'); // 1 QC document per stock-in session
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quality_checks', function (Blueprint $table) {
            $table->dropForeign(['stock_in_id']);
            $table->dropUnique(['stock_in_id']);
            $table->dropColumn('stock_in_id');
        });
    }
};
