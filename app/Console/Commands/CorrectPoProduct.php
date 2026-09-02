<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

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
    protected $description = 'Deprecated unsafe PO product correction command.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->error('This command is disabled because it can leave stock movements, sales, stock-outs, and returns inconsistent.');
        $this->line('Use inventory:correct-product-chain for a compatible linked chain, or inventory:replace-dispatch-and-remove-wrong-inbound for the dispatched non-serialized-to-serialized correction flow.');

        return self::FAILURE;
    }
}
