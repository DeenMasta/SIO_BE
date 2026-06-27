<?php

namespace Tests\Feature;

use App\Jobs\Integrations\DownloadTelegramInvoiceAttachmentJob;
use App\Jobs\Integrations\MatchTelegramInvoiceJob;
use App\Jobs\Integrations\ParseTelegramInvoicePdfJob;
use App\Models\Customer;
use App\Models\InvoiceInboxItem;
use App\Models\SaleOrder;
use App\Models\StockOut;
use App\Models\TelegramInvoiceMessage;
use App\Models\User;
use App\Services\Integrations\Telegram\TelegramInvoiceMatcher;
use App\Services\Integrations\Telegram\TelegramInvoicePdfParser;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TelegramInvoiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_captures_pdf_invoice_and_queues_download_job(): void
    {
        Queue::fake();

        config()->set('services.telegram.webhook_secret', 'secret-123');
        config()->set('services.telegram.allowed_chat_ids', '-10099887766');
        config()->set('services.telegram.storage_disk', 'local');

        $staff = User::factory()->staff()->create();

        $response = $this
            ->withHeader('X-Telegram-Bot-Api-Secret-Token', 'secret-123')
            ->postJson('/api/integrations/telegram/invoices/webhook', [
                'update_id' => 7001,
                'message' => [
                    'message_id' => 501,
                    'date' => now()->timestamp,
                    'caption' => 'Invoice INV-001',
                    'chat' => [
                        'id' => '-10099887766',
                        'title' => 'Invoices',
                    ],
                    'from' => [
                        'id' => 99,
                        'username' => 'ops_user',
                        'first_name' => 'Ops',
                    ],
                    'document' => [
                        'file_id' => 'file-123',
                        'file_unique_id' => 'uniq-123',
                        'file_name' => 'invoice-001.pdf',
                        'mime_type' => 'application/pdf',
                        'file_size' => 12345,
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.queued', true);

        $this->assertDatabaseHas('telegram_invoice_messages', [
            'telegram_chat_id' => '-10099887766',
            'telegram_message_id' => 501,
            'telegram_username' => 'ops_user',
        ]);

        $this->assertDatabaseHas('invoice_inbox_items', [
            'source' => 'telegram',
            'original_file_name' => 'invoice-001.pdf',
            'telegram_file_unique_id' => 'uniq-123',
            'download_status' => 'queued',
        ]);

        Queue::assertPushed(DownloadTelegramInvoiceAttachmentJob::class);
        $this->assertSame(1, $staff->fresh()->unreadNotifications()->count());
        $this->assertSame('telegram-invoice.received', $staff->fresh()->notifications()->first()?->data['event_type']);
    }

    public function test_downloaded_pdf_is_parsed_and_invoice_number_is_extracted(): void
    {
        Storage::fake('local');

        $staff = User::factory()->staff()->create();
        $message = TelegramInvoiceMessage::query()->create([
            'telegram_update_id' => 9002,
            'telegram_chat_id' => '-10099887766',
            'telegram_chat_title' => 'Invoices',
            'telegram_message_id' => 89,
            'telegram_user_id' => 78,
            'telegram_username' => 'finance',
            'caption' => 'Invoice for ACME',
            'message_date' => now(),
            'received_at' => now(),
        ]);

        $pdfPath = 'telegram-invoices/2026/05/invoice-test.pdf';
        Storage::disk('local')->put($pdfPath, $this->makePdfContent(
            "INVOICE\nInvoice No: INV-2026-0001\nInvoice Date: 2026-05-12\nBill To: ACME SDN BHD\n"
        ));

        $item = InvoiceInboxItem::query()->create([
            'source' => 'telegram',
            'telegram_invoice_message_id' => $message->id,
            'file_disk' => 'local',
            'file_path' => $pdfPath,
            'original_file_name' => 'invoice-test.pdf',
            'mime_type' => 'application/pdf',
            'telegram_file_id' => 'file-a',
            'telegram_file_unique_id' => 'uniq-a',
            'download_status' => 'downloaded',
            'parse_status' => 'pending',
            'readability_status' => 'unknown',
        ]);

        (new ParseTelegramInvoicePdfJob($item->id))->handle(
            app(TelegramInvoicePdfParser::class),
            app(\App\Services\Integrations\Telegram\TelegramInvoiceCustomerSync::class),
            app(\App\Application\Support\UserNotificationService::class),
        );

        $item = $item->fresh();

        $this->assertSame('parsed', $item?->parse_status);
        $this->assertSame('text_pdf', $item?->readability_status);
        $this->assertSame('INV-2026-0001', $item?->invoice_number);
        $this->assertSame('ACME SDN BHD', $item?->customer_name);
        $this->assertSame('2026-05-12', $item?->invoice_date?->format('Y-m-d'));
        $this->assertNotEmpty($item?->ocr_text);
        $this->assertSame('unmatched', $item?->match_status);
        $this->assertDatabaseHas('customers', [
            'customer_name' => 'ACME SDN BHD',
        ]);
        $this->assertSame(1, $staff->fresh()->notifications()->count());
        $this->assertSame('telegram-invoice.match-review', $staff->fresh()->notifications()->first()?->data['event_type']);
    }

    public function test_parsing_does_not_create_duplicate_customer_when_name_already_exists(): void
    {
        Storage::fake('local');

        Customer::query()->create([
            'customer_name' => 'ACME SDN BHD',
            'remarks' => 'Existing customer',
        ]);

        $message = TelegramInvoiceMessage::query()->create([
            'telegram_update_id' => 9007,
            'telegram_chat_id' => '-10099887766',
            'telegram_chat_title' => 'Invoices',
            'telegram_message_id' => 94,
            'telegram_user_id' => 83,
            'telegram_username' => 'finance',
            'caption' => 'Invoice for ACME',
            'message_date' => now(),
            'received_at' => now(),
        ]);

        $pdfPath = 'telegram-invoices/2026/05/invoice-existing-customer.pdf';
        Storage::disk('local')->put($pdfPath, $this->makePdfContent(
            "INVOICE\nInvoice No: INV-2026-0002\nInvoice Date: 2026-05-12\nBill To:   Acme   Sdn   Bhd   \n"
        ));

        $item = InvoiceInboxItem::query()->create([
            'source' => 'telegram',
            'telegram_invoice_message_id' => $message->id,
            'file_disk' => 'local',
            'file_path' => $pdfPath,
            'original_file_name' => 'invoice-existing-customer.pdf',
            'mime_type' => 'application/pdf',
            'telegram_file_id' => 'file-c',
            'telegram_file_unique_id' => 'uniq-c',
            'download_status' => 'downloaded',
            'parse_status' => 'pending',
            'readability_status' => 'unknown',
        ]);

        (new ParseTelegramInvoicePdfJob($item->id))->handle(
            app(TelegramInvoicePdfParser::class),
            app(\App\Services\Integrations\Telegram\TelegramInvoiceCustomerSync::class),
            app(\App\Application\Support\UserNotificationService::class),
        );

        $this->assertSame(1, Customer::query()->count('*'));
    }

    public function test_parser_prioritizes_bill_to_and_ignores_display_artifact_for_customer_name(): void
    {
        Storage::fake('local');

        $message = TelegramInvoiceMessage::query()->create([
            'telegram_update_id' => 9008,
            'telegram_chat_id' => '-10099887766',
            'telegram_chat_title' => 'Invoices',
            'telegram_message_id' => 95,
            'telegram_user_id' => 84,
            'telegram_username' => 'finance',
            'caption' => 'Invoice for Devaraz',
            'message_date' => now(),
            'received_at' => now(),
        ]);

        $pdfPath = 'telegram-invoices/2026/05/invoice-bill-to-priority.pdf';
        Storage::disk('local')->put($pdfPath, $this->makePdfContent(
            "Customer Display)\n".
            "INVOICE\n".
            "Invoice No: MYSZ-INV-002373/04/2026\n".
            "Bill To:\n".
            "DEVARAZ BOUTIQUE WORLD\n".
            "Bandar Seremban\n".
            "Negeri Sembilan\n"
        ));

        $item = InvoiceInboxItem::query()->create([
            'source' => 'telegram',
            'telegram_invoice_message_id' => $message->id,
            'file_disk' => 'local',
            'file_path' => $pdfPath,
            'original_file_name' => 'invoice-bill-to-priority.pdf',
            'mime_type' => 'application/pdf',
            'telegram_file_id' => 'file-d',
            'telegram_file_unique_id' => 'uniq-d',
            'download_status' => 'downloaded',
            'parse_status' => 'pending',
            'readability_status' => 'unknown',
        ]);

        (new ParseTelegramInvoicePdfJob($item->id))->handle(
            app(TelegramInvoicePdfParser::class),
            app(\App\Services\Integrations\Telegram\TelegramInvoiceCustomerSync::class),
            app(\App\Application\Support\UserNotificationService::class),
        );

        $item = $item->fresh();

        $this->assertSame('DEVARAZ BOUTIQUE WORLD', $item?->customer_name);
        $this->assertDatabaseHas('customers', [
            'customer_name' => 'DEVARAZ BOUTIQUE WORLD',
        ]);
    }

    public function test_unreadable_pdf_is_marked_for_review(): void
    {
        Storage::fake('local');

        $staff = User::factory()->staff()->create();
        $message = TelegramInvoiceMessage::query()->create([
            'telegram_update_id' => 9003,
            'telegram_chat_id' => '-10099887766',
            'telegram_chat_title' => 'Invoices',
            'telegram_message_id' => 90,
            'telegram_user_id' => 79,
            'telegram_username' => 'finance',
            'caption' => 'Scanned invoice',
            'message_date' => now(),
            'received_at' => now(),
        ]);

        $pdfPath = 'telegram-invoices/2026/05/image-only.pdf';
        Storage::disk('local')->put($pdfPath, '%PDF-1.4');

        $item = InvoiceInboxItem::query()->create([
            'source' => 'telegram',
            'telegram_invoice_message_id' => $message->id,
            'file_disk' => 'local',
            'file_path' => $pdfPath,
            'original_file_name' => 'image-only.pdf',
            'mime_type' => 'application/pdf',
            'telegram_file_id' => 'file-b',
            'telegram_file_unique_id' => 'uniq-b',
            'download_status' => 'downloaded',
            'parse_status' => 'pending',
            'readability_status' => 'unknown',
        ]);

        try {
            (new ParseTelegramInvoicePdfJob($item->id))->handle(
                app(TelegramInvoicePdfParser::class),
                app(\App\Services\Integrations\Telegram\TelegramInvoiceCustomerSync::class),
                app(\App\Application\Support\UserNotificationService::class),
            );
        } catch (\Throwable) {
            $this->fail('Unreadable PDF should be marked for review, not throw.');
        }

        $item = $item->fresh();

        $this->assertSame('needs_review', $item?->parse_status);
        $this->assertSame('image_pdf', $item?->readability_status);
        $this->assertNotNull($item?->review_notes);
        $this->assertSame('pending', $item?->match_status);
        $this->assertSame(1, $staff->fresh()->notifications()->count());
        $this->assertSame('telegram-invoice.needs-review', $staff->fresh()->notifications()->first()?->data['event_type']);
    }

    public function test_invoice_is_matched_to_sale_order_by_exact_invoice_number(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $saleOrder = SaleOrder::query()->create([
            'so_number' => 'SO-2026-0001',
            'so_date' => '2026-05-12',
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-2026-0001',
            'status' => 'CONFIRMED',
            'created_by' => $staff->id,
        ]);

        $message = TelegramInvoiceMessage::query()->create([
            'telegram_update_id' => 9004,
            'telegram_chat_id' => '-10099887766',
            'telegram_chat_title' => 'Invoices',
            'telegram_message_id' => 91,
            'telegram_user_id' => 80,
            'telegram_username' => 'finance',
            'caption' => 'Invoice INV-2026-0001',
            'message_date' => now(),
            'received_at' => now(),
        ]);

        $item = InvoiceInboxItem::query()->create([
            'source' => 'telegram',
            'telegram_invoice_message_id' => $message->id,
            'file_disk' => 'local',
            'file_path' => 'telegram-invoices/2026/05/invoice.pdf',
            'original_file_name' => 'invoice.pdf',
            'mime_type' => 'application/pdf',
            'download_status' => 'downloaded',
            'parse_status' => 'parsed',
            'readability_status' => 'text_pdf',
            'invoice_number' => 'INV-2026-0001',
            'ocr_text' => 'Invoice No: INV-2026-0001',
        ]);

        (new MatchTelegramInvoiceJob($item->id))->handle(
            app(TelegramInvoiceMatcher::class),
            app(\App\Application\Support\UserNotificationService::class),
        );

        $item = $item->fresh();

        $this->assertSame('matched', $item?->match_status);
        $this->assertSame($saleOrder->id, $item?->matched_sale_order_id);
        $this->assertNull($item?->matched_stock_out_id);
        $this->assertNotNull($item?->matched_at);
        $this->assertSame('telegram-invoice.matched', $staff->fresh()->notifications()->first()?->data['event_type']);
    }

    public function test_invoice_with_multiple_exact_matches_is_marked_multi_match(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        SaleOrder::query()->create([
            'so_number' => 'SO-2026-1001',
            'so_date' => '2026-05-12',
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-DUP-001',
            'status' => 'CONFIRMED',
            'created_by' => $staff->id,
        ]);
        SaleOrder::query()->create([
            'so_number' => 'SO-2026-1002',
            'so_date' => '2026-05-13',
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-DUP-001',
            'status' => 'DRAFT',
            'created_by' => $staff->id,
        ]);

        $message = TelegramInvoiceMessage::query()->create([
            'telegram_update_id' => 9005,
            'telegram_chat_id' => '-10099887766',
            'telegram_chat_title' => 'Invoices',
            'telegram_message_id' => 92,
            'telegram_user_id' => 81,
            'telegram_username' => 'finance',
            'caption' => 'Duplicate invoice',
            'message_date' => now(),
            'received_at' => now(),
        ]);

        $item = InvoiceInboxItem::query()->create([
            'source' => 'telegram',
            'telegram_invoice_message_id' => $message->id,
            'file_disk' => 'local',
            'file_path' => 'telegram-invoices/2026/05/dup.pdf',
            'original_file_name' => 'dup.pdf',
            'mime_type' => 'application/pdf',
            'download_status' => 'downloaded',
            'parse_status' => 'parsed',
            'readability_status' => 'text_pdf',
            'invoice_number' => 'INV-DUP-001',
            'ocr_text' => 'Invoice No: INV-DUP-001',
        ]);

        (new MatchTelegramInvoiceJob($item->id))->handle(
            app(TelegramInvoiceMatcher::class),
            app(\App\Application\Support\UserNotificationService::class),
        );

        $item = $item->fresh();

        $this->assertSame('multi_match', $item?->match_status);
        $this->assertNull($item?->matched_sale_order_id);
        $this->assertNull($item?->matched_stock_out_id);
        $this->assertSame('telegram-invoice.match-review', $staff->fresh()->notifications()->first()?->data['event_type']);
    }

    public function test_invoice_is_matched_to_stock_out_when_sale_order_is_missing(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $stockOut = StockOut::query()->create([
            'stock_out_number' => 'STO-2026-0001',
            'idempotency_key' => 'stock-out-key-001',
            'stock_out_date' => '2026-05-12',
            'customer_id' => $customer->id,
            'pic_id' => $staff->id,
            'status' => 'POSTED',
        ]);

        $message = TelegramInvoiceMessage::query()->create([
            'telegram_update_id' => 9006,
            'telegram_chat_id' => '-10099887766',
            'telegram_chat_title' => 'Invoices',
            'telegram_message_id' => 93,
            'telegram_user_id' => 82,
            'telegram_username' => 'finance',
            'caption' => 'Stock out invoice',
            'message_date' => now(),
            'received_at' => now(),
        ]);

        $item = InvoiceInboxItem::query()->create([
            'source' => 'telegram',
            'telegram_invoice_message_id' => $message->id,
            'file_disk' => 'local',
            'file_path' => 'telegram-invoices/2026/05/sto.pdf',
            'original_file_name' => 'sto.pdf',
            'mime_type' => 'application/pdf',
            'download_status' => 'downloaded',
            'parse_status' => 'parsed',
            'readability_status' => 'text_pdf',
            'invoice_number' => 'INV-STO-001',
            'ocr_text' => 'Invoice No: INV-STO-001',
        ]);

        (new MatchTelegramInvoiceJob($item->id))->handle(
            app(TelegramInvoiceMatcher::class),
            app(\App\Application\Support\UserNotificationService::class),
        );

        $item = $item->fresh();

        $this->assertSame('matched', $item?->match_status);
        $this->assertNull($item?->matched_sale_order_id);
        $this->assertSame($stockOut->id, $item?->matched_stock_out_id);
    }

    public function test_staff_user_can_list_invoice_inbox_records(): void
    {
        $staff = User::factory()->staff()->create();
        $message = TelegramInvoiceMessage::query()->create([
            'telegram_update_id' => 9001,
            'telegram_chat_id' => '-10099887766',
            'telegram_chat_title' => 'Invoices',
            'telegram_message_id' => 88,
            'telegram_user_id' => 77,
            'telegram_username' => 'finance',
            'caption' => 'Monthly invoice',
            'message_date' => now(),
            'received_at' => now(),
        ]);

        $item = InvoiceInboxItem::query()->create([
            'source' => 'telegram',
            'telegram_invoice_message_id' => $message->id,
            'file_disk' => 'local',
            'file_path' => 'telegram-invoices/2026/05/1.pdf',
            'original_file_name' => 'monthly.pdf',
            'mime_type' => 'application/pdf',
            'telegram_file_id' => 'file-a',
            'telegram_file_unique_id' => 'uniq-a',
            'download_status' => 'downloaded',
            'parse_status' => 'pending',
            'readability_status' => 'text_pdf',
        ]);

        Sanctum::actingAs($staff, ['staff-access']);

        $this->getJson('/api/invoice-inbox')
            ->assertOk()
            ->assertJsonPath('data.0.id', $item->id)
            ->assertJsonPath('data.0.telegram_message.telegram_username', 'finance');

        $this->getJson('/api/invoice-inbox/'.$item->id)
            ->assertOk()
            ->assertJsonPath('data.original_file_name', 'monthly.pdf');
    }

    public function test_staff_user_can_manually_link_invoice_to_sale_order(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $saleOrder = SaleOrder::query()->create([
            'so_number' => 'SO-MANUAL-001',
            'so_date' => '2026-05-12',
            'customer_id' => $customer->id,
            'invoice_number' => null,
            'status' => 'DRAFT',
            'created_by' => $staff->id,
        ]);
        $message = TelegramInvoiceMessage::query()->create([
            'telegram_update_id' => 9101,
            'telegram_chat_id' => '-10099887766',
            'telegram_chat_title' => 'Invoices',
            'telegram_message_id' => 101,
            'telegram_user_id' => 90,
            'telegram_username' => 'finance',
            'message_date' => now(),
            'received_at' => now(),
        ]);
        $item = InvoiceInboxItem::query()->create([
            'source' => 'telegram',
            'telegram_invoice_message_id' => $message->id,
            'file_disk' => 'local',
            'original_file_name' => 'manual.pdf',
            'download_status' => 'downloaded',
            'parse_status' => 'parsed',
            'readability_status' => 'text_pdf',
            'match_status' => 'unmatched',
        ]);

        Sanctum::actingAs($staff, ['staff-access']);

        $this->patchJson('/api/invoice-inbox/'.$item->id.'/link-sale-order', [
            'sale_order_id' => $saleOrder->id,
            'review_notes' => 'Manual confirmation from ops',
        ])
            ->assertOk()
            ->assertJsonPath('data.matched_sale_order_id', $saleOrder->id)
            ->assertJsonPath('data.match_status', 'matched')
            ->assertJsonPath('data.reviewed_by', $staff->id);
    }

    public function test_staff_user_can_manually_link_invoice_to_stock_out(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $stockOut = StockOut::query()->create([
            'stock_out_number' => 'STO-MANUAL-001',
            'idempotency_key' => 'stock-out-manual-001',
            'stock_out_date' => '2026-05-12',
            'customer_id' => $customer->id,
            'pic_id' => $staff->id,
            'status' => 'POSTED',
        ]);
        $message = TelegramInvoiceMessage::query()->create([
            'telegram_update_id' => 9102,
            'telegram_chat_id' => '-10099887766',
            'telegram_chat_title' => 'Invoices',
            'telegram_message_id' => 102,
            'telegram_user_id' => 91,
            'telegram_username' => 'finance',
            'message_date' => now(),
            'received_at' => now(),
        ]);
        $item = InvoiceInboxItem::query()->create([
            'source' => 'telegram',
            'telegram_invoice_message_id' => $message->id,
            'file_disk' => 'local',
            'original_file_name' => 'manual-sto.pdf',
            'download_status' => 'downloaded',
            'parse_status' => 'parsed',
            'readability_status' => 'text_pdf',
            'match_status' => 'unmatched',
        ]);

        Sanctum::actingAs($staff, ['staff-access']);

        $this->patchJson('/api/invoice-inbox/'.$item->id.'/link-stock-out', [
            'stock_out_id' => $stockOut->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.matched_stock_out_id', $stockOut->id)
            ->assertJsonPath('data.match_status', 'matched');
    }

    public function test_staff_user_can_retry_match_after_updating_invoice_number(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $saleOrder = SaleOrder::query()->create([
            'so_number' => 'SO-RETRY-001',
            'so_date' => '2026-05-12',
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-RETRY-001',
            'status' => 'CONFIRMED',
            'created_by' => $staff->id,
        ]);
        $message = TelegramInvoiceMessage::query()->create([
            'telegram_update_id' => 9103,
            'telegram_chat_id' => '-10099887766',
            'telegram_chat_title' => 'Invoices',
            'telegram_message_id' => 103,
            'telegram_user_id' => 92,
            'telegram_username' => 'finance',
            'message_date' => now(),
            'received_at' => now(),
        ]);
        $item = InvoiceInboxItem::query()->create([
            'source' => 'telegram',
            'telegram_invoice_message_id' => $message->id,
            'file_disk' => 'local',
            'original_file_name' => 'retry.pdf',
            'download_status' => 'downloaded',
            'parse_status' => 'parsed',
            'readability_status' => 'text_pdf',
            'match_status' => 'unmatched',
            'invoice_number' => 'INV-RETRY-001',
        ]);

        Sanctum::actingAs($staff, ['staff-access']);

        $this->patchJson('/api/invoice-inbox/'.$item->id.'/retry-match')
            ->assertOk()
            ->assertJsonPath('data.match_status', 'matched')
            ->assertJsonPath('data.matched_sale_order_id', $saleOrder->id);
    }

    public function test_parsed_invoice_creates_sale_order_when_items_match(): void
    {
        Storage::fake('local');
        
        $staff = User::factory()->staff()->create();
        $package = \App\Models\Package::query()->create([
            'package_code' => 'SPB',
            'package_name' => 'SPB Starter Pack',
            'status' => \App\Domain\MasterData\Enums\RecordStatus::Active,
        ]);
        $product = \App\Models\Product::query()->create([
            'product_code' => 'P1',
            'product_name' => 'P1 Product',
            'product_type' => \App\Domain\MasterData\Enums\ProductType::Standard,
            'selling_price' => 199.00,
            'status' => \App\Domain\MasterData\Enums\RecordStatus::Active,
        ]);
        $package->products()->attach($product->id, ['quantity' => 1]);

        $message = TelegramInvoiceMessage::query()->create([
            'telegram_update_id' => 9999,
            'telegram_chat_id' => '-10099887766',
            'telegram_chat_title' => 'Invoices',
            'telegram_message_id' => 999,
            'telegram_user_id' => 888,
            'telegram_username' => 'finance',
            'caption' => 'Invoice with items',
            'message_date' => now(),
            'received_at' => now(),
        ]);

        $pdfPath = 'telegram-invoices/2026/05/invoice-items.pdf';
        Storage::disk('local')->put($pdfPath, $this->makePdfContent(
            "INVOICE\n".
            "Invoice No: MYSZ-INV-002518/06/2026\n".
            "Bill To:\n".
            "SWEETLY COOKIES\n".
            "Rembau Negeri Sembilan\n".
            "012-3456789\n".
            "\nItems\n".
            "1 SPB Starter GD Pack B 1 Set 999.00 999.00\n"
        ));

        $item = InvoiceInboxItem::query()->create([
            'source' => 'telegram',
            'telegram_invoice_message_id' => $message->id,
            'file_disk' => 'local',
            'file_path' => $pdfPath,
            'original_file_name' => 'invoice-items.pdf',
            'mime_type' => 'application/pdf',
            'telegram_file_id' => 'file-new',
            'telegram_file_unique_id' => 'uniq-new',
            'download_status' => 'downloaded',
            'parse_status' => 'pending',
            'readability_status' => 'unknown',
        ]);

        (new ParseTelegramInvoicePdfJob($item->id))->handle(
            app(TelegramInvoicePdfParser::class),
            app(\App\Services\Integrations\Telegram\TelegramInvoiceCustomerSync::class),
            app(\App\Application\Support\UserNotificationService::class),
        );

        $item = $item->fresh();

        $this->assertSame('parsed', $item?->parse_status);
        $this->assertSame('002518/06/2026', $item?->invoice_number);
        $this->assertSame('SWEETLY COOKIES', $item?->customer_name);
        $this->assertNotNull($item?->matched_sale_order_id);

        $saleOrder = SaleOrder::query()->find($item->matched_sale_order_id);
        $this->assertNotNull($saleOrder);
        $this->assertSame('DRAFT', $saleOrder->status->value);
        $this->assertCount(1, $saleOrder->lines);
        $this->assertSame($product->id, $saleOrder->lines->first()->product_id);
        
        $customer = Customer::query()->find($saleOrder->customer_id);
        $this->assertSame('SWEETLY COOKIES', $customer->customer_name);
        $this->assertSame('012-3456789', $customer->phone);
        $this->assertSame('Rembau Negeri Sembilan', $customer->address);
    }

    private function makePdfContent(string $text): string
    {
        $dompdf = new Dompdf();
        $dompdf->loadHtml('<html><body><pre>'.e($text).'</pre></body></html>');
        $dompdf->render();

        return $dompdf->output();
    }
}
