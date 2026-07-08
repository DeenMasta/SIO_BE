<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_out_lines', function (Blueprint $table): void {
            $table->unsignedInteger('reversed_qty')->default(0)->after('settled_qty');
        });

        Schema::table('stock_out_line_items', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable()->after('settled_sale_order_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_out_line_items', function (Blueprint $table): void {
            $table->dropColumn('reversed_at');
        });

        Schema::table('stock_out_lines', function (Blueprint $table): void {
            $table->dropColumn('reversed_qty');
        });
    }
};
