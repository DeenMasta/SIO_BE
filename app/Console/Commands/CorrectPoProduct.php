<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockInLine;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\ReturnToSupplierLine;
use Illuminate\Support\Facades\DB;

class CorrectPoProduct extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sio:correct-po-product {po_number} {old_product_id} {new_product_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Correct the product in a PO and cascade to Stock In, Inventory, and QC';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $poNumber = $this->argument('po_number');
        $oldProductId = $this->argument('old_product_id');
        $newProductId = $this->argument('new_product_id');

        $po = PurchaseOrder::where('po_number', $poNumber)->first();
        if (!$po) {
            $this->error("Purchase Order {$poNumber} not found.");
            return 1;
        }

        $poLines = PurchaseOrderLine::where('purchase_order_id', $po->id)
            ->where('product_id', $oldProductId)
            ->get();

        if ($poLines->isEmpty()) {
            $this->error("Purchase Order Line with product ID {$oldProductId} not found in PO {$poNumber}.");
            return 1;
        }

        DB::beginTransaction();
        try {
            foreach ($poLines as $poLine) {
                // 1. Update PO Line
                $poLine->update(['product_id' => $newProductId]);
                $this->info("Updated PurchaseOrderLine ID: {$poLine->id}");

                // 2. Update Stock In Lines
                $stockInLines = StockInLine::where('purchase_order_line_id', $poLine->id)
                    ->where('product_id', $oldProductId)
                    ->get();
                
                foreach ($stockInLines as $sil) {
                    $sil->update(['product_id' => $newProductId]);
                    $this->info("  Updated StockInLine ID: {$sil->id}");

                    // 3. Update Stock Items
                    $stockItems = StockItem::where('stock_in_line_id', $sil->id)
                        ->where('product_id', $oldProductId)
                        ->get();

                    foreach ($stockItems as $item) {
                        $item->update(['product_id' => $newProductId]);
                        $this->info("    Updated StockItem ID: {$item->id} (Serial: {$item->serial_number})");

                        // 4. Update Stock Movements
                        StockMovement::where('stock_item_id', $item->id)
                            ->where('product_id', $oldProductId)
                            ->update(['product_id' => $newProductId]);
                        
                        // 5. Update Return to Supplier Lines
                        ReturnToSupplierLine::where('stock_item_id', $item->id)
                            ->where('product_id', $oldProductId)
                            ->update(['product_id' => $newProductId]);
                    }
                }
            }

            DB::commit();
            $this->info("Successfully corrected product from ID {$oldProductId} to {$newProductId} for PO {$poNumber}.");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Failed to correct product: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
