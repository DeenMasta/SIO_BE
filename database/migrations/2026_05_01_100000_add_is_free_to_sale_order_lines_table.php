<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_order_lines', function (Blueprint $table): void {
            $table->boolean('is_free')->default(false)->after('fulfilled_qty');
        });
    }

    public function down(): void
    {
        Schema::table('sale_order_lines', function (Blueprint $table): void {
            $table->dropColumn('is_free');
        });
    }
};
