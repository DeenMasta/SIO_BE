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
        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('qty_received_pending_qc')->default(0);
            $table->unsignedInteger('qty_in_stock')->default(0);
            $table->unsignedInteger('qty_delivered')->default(0);
            $table->unsignedInteger('qty_under_repair')->default(0);
            $table->unsignedInteger('qty_returned')->default(0);
            $table->unsignedInteger('qty_returned_to_supplier')->default(0);
            $table->timestamp('last_computed_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id']);
            $table->index(['qty_in_stock']);
        });

        $this->backfillBalances();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }

    private function backfillBalances(): void
    {
        $serializedByProduct = DB::table('stock_items')
            ->selectRaw('product_id')
            ->selectRaw("SUM(CASE WHEN current_status = 'RECEIVED' THEN 1 ELSE 0 END) as qty_received_pending_qc")
            ->selectRaw("SUM(CASE WHEN current_status = 'IN_STOCK' THEN 1 ELSE 0 END) as qty_in_stock_serialized")
            ->selectRaw("SUM(CASE WHEN current_status = 'DELIVERED' THEN 1 ELSE 0 END) as qty_delivered")
            ->selectRaw("SUM(CASE WHEN current_status = 'UNDER_REPAIR' THEN 1 ELSE 0 END) as qty_under_repair")
            ->selectRaw("SUM(CASE WHEN current_status = 'RETURNED' THEN 1 ELSE 0 END) as qty_returned")
            ->selectRaw("SUM(CASE WHEN current_status = 'RETURNED_TO_SUPPLIER' THEN 1 ELSE 0 END) as qty_returned_to_supplier")
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $nonSerializedByProduct = DB::table('stock_movements')
            ->whereNull('stock_item_id')
            ->selectRaw('product_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN to_status = 'IN_STOCK' THEN qty_in ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN from_status = 'IN_STOCK' THEN qty_out ELSE 0 END), 0) as qty_in_stock_non_serialized")
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $rows = DB::table('products')
            ->select('id')
            ->get()
            ->map(function (object $product) use ($serializedByProduct, $nonSerializedByProduct): array {
                $serialized = $serializedByProduct->get($product->id);
                $nonSerializedRaw = (int) (($nonSerializedByProduct->get($product->id)->qty_in_stock_non_serialized) ?? 0);
                $nonSerialized = max($nonSerializedRaw, 0);

                return [
                    'product_id' => (int) $product->id,
                    'qty_received_pending_qc' => (int) ($serialized->qty_received_pending_qc ?? 0),
                    'qty_in_stock' => (int) ($serialized->qty_in_stock_serialized ?? 0) + $nonSerialized,
                    'qty_delivered' => (int) ($serialized->qty_delivered ?? 0),
                    'qty_under_repair' => (int) ($serialized->qty_under_repair ?? 0),
                    'qty_returned' => (int) ($serialized->qty_returned ?? 0),
                    'qty_returned_to_supplier' => (int) ($serialized->qty_returned_to_supplier ?? 0),
                    'last_computed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })
            ->all();

        if ($rows !== []) {
            DB::table('stock_balances')->insert($rows);
        }
    }
};
