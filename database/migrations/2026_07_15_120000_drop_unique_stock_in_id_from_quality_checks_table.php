<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Allow multiple QC documents to be linked to the same stock-in session so
     * different users can QC separate subsets of items over time.
     */
    public function up(): void
    {
        Schema::table('quality_checks', function (Blueprint $table) {
            $table->dropForeign(['stock_in_id']);
            $table->dropUnique('quality_checks_stock_in_id_unique');
            $table->index('stock_in_id');
            $table->foreign('stock_in_id')->references('id')->on('stock_in')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quality_checks', function (Blueprint $table) {
            $table->dropForeign(['stock_in_id']);
            $table->dropIndex(['stock_in_id']);
            $table->unique('stock_in_id');
            $table->foreign('stock_in_id')->references('id')->on('stock_in')->restrictOnDelete();
        });
    }
};
