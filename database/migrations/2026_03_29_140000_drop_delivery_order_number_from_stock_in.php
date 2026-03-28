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
        if (! Schema::hasColumn('stock_in', 'delivery_order_number')) {
            return;
        }

        Schema::table('stock_in', function (Blueprint $table): void {
            $table->dropColumn('delivery_order_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('stock_in', 'delivery_order_number')) {
            return;
        }

        Schema::table('stock_in', function (Blueprint $table): void {
            $table->string('delivery_order_number', 50)->nullable()->after('stock_in_date');
        });
    }
};
