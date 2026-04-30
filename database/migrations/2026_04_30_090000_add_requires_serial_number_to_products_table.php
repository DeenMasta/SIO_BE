<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('requires_serial_number')->default(false)->after('product_type');
        });

        DB::table('products')
            ->whereIn('product_type', ['DEVICE', 'ACCESSORY'])
            ->update(['requires_serial_number' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('requires_serial_number');
        });
    }
};
