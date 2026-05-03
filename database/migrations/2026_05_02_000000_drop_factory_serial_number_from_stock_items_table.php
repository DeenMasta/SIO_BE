<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_items', 'factory_serial_number')) {
                $table->dropUnique('stock_items_factory_serial_number_unique');
                $table->dropColumn('factory_serial_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_items', 'factory_serial_number')) {
                $table->string('factory_serial_number', 80)->nullable()->unique()->after('serial_number');
            }
        });
    }
};
