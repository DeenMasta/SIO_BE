<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stock_movements')
            ->where('movement_type', 'FAILED_UPSELL_REVERSE')
            ->update(['movement_type' => 'EXTRA_ITEM_RETURN']);
    }

    public function down(): void
    {
        DB::table('stock_movements')
            ->where('movement_type', 'EXTRA_ITEM_RETURN')
            ->update(['movement_type' => 'FAILED_UPSELL_REVERSE']);
    }
};
