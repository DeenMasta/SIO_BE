<?php

namespace App\Console\Commands;

use App\Domain\InventoryCore\Enums\StockItemStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixLegacyRepairReturns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fix-legacy-repair-returns';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fixes existing customer return lines that have the deprecated REPAIR next_action.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting legacy repair return fix...');

        DB::transaction(function () {
            // Find all lines with 'REPAIR' action
            $lines = DB::table('customer_return_lines')
                ->where('next_action', 'REPAIR')
                ->get();

            if ($lines->isEmpty()) {
                $this->info('No legacy repair return lines found. You are good to go!');
                return;
            }

            $count = 0;

            foreach ($lines as $line) {
                // 1. Change next_action to REPLACE (which maps to RETURNED status)
                DB::table('customer_return_lines')
                    ->where('id', $line->id)
                    ->update(['next_action' => 'REPLACE']);

                if ($line->stock_item_id) {
                    // 2. Update the StockItem's current status from UNDER_REPAIR to RETURNED
                    DB::table('stock_items')
                        ->where('id', $line->stock_item_id)
                        ->where('current_status', 'UNDER_REPAIR')
                        ->update(['current_status' => StockItemStatus::Returned->value]);
                }

                // 3. Update the StockMovement to reflect the new target status
                DB::table('stock_movements')
                    ->where('reference_table', 'customer_return_lines')
                    ->where('reference_id', $line->id)
                    ->where('to_status', 'UNDER_REPAIR')
                    ->update(['to_status' => StockItemStatus::Returned->value]);

                $count++;
            }

            $this->info("Successfully updated {$count} legacy return lines.");
            $this->info('The items are now in RETURNED status. You can process them through the Repair module (Internal repair).');
        });
    }
}
