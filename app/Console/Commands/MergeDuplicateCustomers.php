<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\Customer;
use App\Models\SaleOrder;
use App\Models\StockOut;
use App\Models\Repair;
use App\Models\CustomerReturn;
use Illuminate\Support\Facades\DB;

#[Signature('customers:merge-duplicates')]
#[Description('Find duplicate customers by name, merge their relationships, and delete the redundant ones.')]
class MergeDuplicateCustomers extends Command
{
    public function handle()
    {
        $this->info('Finding duplicate customers...');

        $duplicates = Customer::query()
            ->select(DB::raw('LOWER(TRIM(customer_name)) as normalized_name'))
            ->groupBy('normalized_name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('normalized_name');

        if ($duplicates->isEmpty()) {
            $this->info('No duplicate customers found in the database! (If you see duplicates in the UI, they might be from local browser cache or React HMR).');
            return;
        }

        foreach ($duplicates as $duplicateName) {
            $this->info("Merging duplicates for: {$duplicateName}");

            $customers = Customer::where(DB::raw('LOWER(TRIM(customer_name))'), $duplicateName)
                ->orderBy('id', 'asc')
                ->get();

            $primary = $customers->first();

            foreach ($customers->slice(1) as $duplicate) {
                // Merge relations to the primary customer to ensure we don't lose any sale orders
                SaleOrder::where('customer_id', $duplicate->id)->update(['customer_id' => $primary->id]);
                StockOut::where('customer_id', $duplicate->id)->update(['customer_id' => $primary->id]);
                Repair::where('customer_id', $duplicate->id)->update(['customer_id' => $primary->id]);
                CustomerReturn::where('customer_id', $duplicate->id)->update(['customer_id' => $primary->id]);

                // Delete the redundant duplicate
                $duplicate->delete();

                $this->line("  -> Merged ID {$duplicate->id} into ID {$primary->id} and deleted.");
            }
        }

        $this->info('Successfully merged and deleted all redundant customers.');
    }
}
