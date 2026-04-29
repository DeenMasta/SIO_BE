<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Drops condition_at_receiving from stock_in_lines.
     * QC is now handled exclusively by the dedicated QC module.
     */
    public function up(): void
    {
        Schema::table('stock_in_lines', function (Blueprint $table) {
            $table->dropColumn('condition_at_receiving');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_in_lines', function (Blueprint $table) {
            $table->string('condition_at_receiving', 50)->nullable()->after('received_qty');
        });
    }
};
