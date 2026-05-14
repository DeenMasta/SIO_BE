<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->unsignedInteger('qty_internal_use')->default(0)->after('qty_delivered');
        });

        $serializedByProduct = DB::table('stock_items')
            ->selectRaw('product_id')
            ->selectRaw("SUM(CASE WHEN current_status = 'INTERNAL_USE' THEN 1 ELSE 0 END) as qty_internal_use")
            ->groupBy('product_id')
            ->pluck('qty_internal_use', 'product_id');

        $nonSerializedByProduct = DB::table('stock_movements')
            ->whereNull('stock_item_id')
            ->selectRaw('product_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN to_status = 'INTERNAL_USE' THEN qty_out ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN from_status = 'INTERNAL_USE' THEN qty_in ELSE 0 END), 0) as qty_internal_use")
            ->groupBy('product_id')
            ->pluck('qty_internal_use', 'product_id');

        $productIds = DB::table('products')->pluck('id');
        foreach ($productIds as $productId) {
            DB::table('stock_balances')
                ->where('product_id', (int) $productId)
                ->update([
                    'qty_internal_use' => max(
                        (int) ($serializedByProduct[$productId] ?? 0) + (int) ($nonSerializedByProduct[$productId] ?? 0),
                        0,
                    ),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->dropColumn('qty_internal_use');
        });
    }
};
