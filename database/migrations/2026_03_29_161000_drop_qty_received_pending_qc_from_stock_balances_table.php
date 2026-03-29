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
        Schema::table('stock_balances', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_balances', 'qty_received_pending_qc')) {
                $table->dropColumn('qty_received_pending_qc');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_balances', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_balances', 'qty_received_pending_qc')) {
                $table->unsignedInteger('qty_received_pending_qc')->default(0)->after('product_id');
            }
        });
    }
};
