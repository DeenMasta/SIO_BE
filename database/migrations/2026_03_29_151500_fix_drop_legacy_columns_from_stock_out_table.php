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
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        if (!Schema::hasTable('stock_out')) {
            return;
        }

        Schema::table('stock_out', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_out', 'invoice_number')) {
                $table->dropColumn('invoice_number');
            }

            if (Schema::hasColumn('stock_out', 'packing_verified')) {
                $table->dropColumn('packing_verified');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        if (!Schema::hasTable('stock_out')) {
            return;
        }

        Schema::table('stock_out', function (Blueprint $table): void {
            if (!Schema::hasColumn('stock_out', 'invoice_number')) {
                $table->string('invoice_number', 50)->nullable();
            }

            if (!Schema::hasColumn('stock_out', 'packing_verified')) {
                $table->boolean('packing_verified')->default(false);
            }
        });
    }
};
