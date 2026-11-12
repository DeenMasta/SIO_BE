<?php

namespace Tests\Feature;

use App\Application\Support\DocumentNumberGenerator;
use App\Models\Customer;
use App\Models\StockOut;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reserves_each_generated_stock_out_number(): void
    {
        $generator = app(DocumentNumberGenerator::class);
        $prefix = 'SOUT-'.now()->format('Ymd');
        $user = User::factory()->create();
        $customer = Customer::factory()->create();

        StockOut::query()->create([
            'stock_out_number' => $prefix.'-0002',
            'idempotency_key' => 'existing-stock-out-number',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-EXISTING',
            'pic_id' => $user->id,
        ]);

        $this->assertSame($prefix.'-0003', $generator->generateStockOutNumber());
        $this->assertSame($prefix.'-0004', $generator->generateStockOutNumber());

        $this->assertDatabaseHas('document_number_sequences', [
            'sequence_key' => StockOut::class.'|stock_out_number|'.$prefix,
            'last_number' => 4,
        ]);
    }
}
