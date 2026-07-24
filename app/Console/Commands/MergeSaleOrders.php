<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SaleOrder;
use App\Models\SaleOrderLine;
use App\Models\StockOut;
use App\Models\QuickStockOut;
use Illuminate\Support\Facades\DB;

class MergeSaleOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sio:merge-sale-orders {target} {sources*}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Merge multiple source sale orders into a target sale order';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $targetSoNumber = $this->argument('target');
        $sourceSoNumbers = $this->argument('sources');

        $target = SaleOrder::where('so_number', $targetSoNumber)->first();
        if (!$target) {
            $this->error("Target SO {$targetSoNumber} not found.");
            return 1;
        }

        DB::beginTransaction();
        try {
            foreach ($sourceSoNumbers as $sourceSoNumber) {
                if ($sourceSoNumber === $targetSoNumber) {
                    continue;
                }

                $source = SaleOrder::where('so_number', $sourceSoNumber)->first();
                if (!$source) {
                    $this->warn("Source SO {$sourceSoNumber} not found. Skipping.");
                    continue;
                }

                $this->info("Merging {$sourceSoNumber} into {$targetSoNumber}...");

                // Move sale order lines
                SaleOrderLine::where('sale_order_id', $source->id)
                    ->update(['sale_order_id' => $target->id]);

                // Move stock outs
                StockOut::where('sale_order_id', $source->id)
                    ->update(['sale_order_id' => $target->id]);

                // Update InvoiceInboxItem
                \App\Models\InvoiceInboxItem::where('matched_sale_order_id', $source->id)
                    ->update(['matched_sale_order_id' => $target->id]);

                // Update QuickStockOut if it was converted to this source SO
                QuickStockOut::where('converted_sale_order_id', $source->id)
                    ->update(['converted_sale_order_id' => $target->id]);

                // If target doesn't have a quick_stock_out_id but source does, bring it over
                if ($source->quick_stock_out_id && !$target->quick_stock_out_id) {
                    $target->quick_stock_out_id = $source->quick_stock_out_id;
                    $target->save();
                }

                // Delete source
                $source->delete();
            }

            DB::commit();
            $this->info("Successfully merged sale orders into {$targetSoNumber}.");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Failed to merge: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
