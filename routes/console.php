<?php

use App\Application\Inventory\UseCases\CorrectWrongProductChainUseCase;
use App\Application\Inventory\UseCases\RebuildCorrectedInboundChainUseCase;
use App\Models\TelegramInvoiceMessage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('telegram-invoices:prune-raw-payloads', function () {
    $retentionDays = max((int) config('services.telegram.invoice_payload_retention_days', 180), 1);
    $cutoff = now()->subDays($retentionDays);

    $pruned = TelegramInvoiceMessage::query()
        ->whereNotNull('raw_payload')
        ->whereNull('pruned_at')
        ->where('received_at', '<', $cutoff)
        ->update([
            'raw_payload' => null,
            'pruned_at' => now(),
            'updated_at' => now(),
        ]);

    $this->info("Pruned {$pruned} Telegram invoice raw payload(s).");
})->purpose('Prune raw Telegram invoice payloads after the configured retention period.');

Artisan::command('telegram-invoices:generate-webhook-secret', function () {
    $this->line(Str::random(64));
})->purpose('Generate a random Telegram invoice webhook secret.');

Artisan::command('telegram-invoices:register-webhook', function () {
    $botToken = trim((string) config('services.telegram.bot_token', ''));
    $webhookUrl = trim((string) config('services.telegram.invoice_webhook_url', ''));
    $secret = trim((string) config('services.telegram.webhook_secret', ''));

    if ($botToken === '') {
        $this->error('TELEGRAM_BOT_TOKEN is not set.');

        return self::FAILURE;
    }

    if ($webhookUrl === '') {
        $this->error('TELEGRAM_INVOICE_WEBHOOK_URL is not set.');

        return self::FAILURE;
    }

    if ($secret === '') {
        $this->error('TELEGRAM_WEBHOOK_SECRET is not set.');

        return self::FAILURE;
    }

    $response = Http::baseUrl('https://api.telegram.org')
        ->acceptJson()
        ->asForm()
        ->post('/bot'.$botToken.'/setWebhook', [
            'url' => $webhookUrl,
            'secret_token' => $secret,
            'allowed_updates' => json_encode(['message'], JSON_THROW_ON_ERROR),
        ])
        ->throw()
        ->json();

    if (! is_array($response) || ! ($response['ok'] ?? false)) {
        $this->error('Telegram webhook registration failed.');

        return self::FAILURE;
    }

    $this->info('Telegram invoice webhook registered successfully.');
    $this->line('URL: '.$webhookUrl);
    $this->line('Allowed updates: message');

    return self::SUCCESS;
})->purpose('Register the Telegram invoice webhook using the configured bot token and URL.');

Artisan::command(
    'inventory:correct-product-chain
        {purchase_order_line_id : The purchase_order_lines.id to correct}
        {stock_in_line_id : The stock_in_lines.id to correct}
        {from_product_id : The wrong products.id currently stored in the chain}
        {to_product_id : The correct products.id that should replace it}
        {--sale-order-line-id= : Optional sale_order_lines.id to correct}
        {--stock-out-line-id= : Optional stock_out_lines.id to correct}
        {--po-date= : Optional corrected PO date (YYYY-MM-DD)}
        {--stock-in-date= : Optional corrected stock in date (YYYY-MM-DD)}
        {--qc-date= : Optional corrected QC document date (YYYY-MM-DD)}
        {--so-date= : Optional corrected sale order date (YYYY-MM-DD)}
        {--stock-out-date= : Optional corrected stock out date (YYYY-MM-DD)}
        {--yes : Skip the confirmation prompt}',
    function () {
        $payload = [
            'purchase_order_line_id' => (int) $this->argument('purchase_order_line_id'),
            'stock_in_line_id' => (int) $this->argument('stock_in_line_id'),
            'from_product_id' => (int) $this->argument('from_product_id'),
            'to_product_id' => (int) $this->argument('to_product_id'),
            'sale_order_line_id' => $this->option('sale-order-line-id') !== null
                ? (int) $this->option('sale-order-line-id')
                : null,
            'stock_out_line_id' => $this->option('stock-out-line-id') !== null
                ? (int) $this->option('stock-out-line-id')
                : null,
            'po_date' => $this->option('po-date'),
            'stock_in_date' => $this->option('stock-in-date'),
            'qc_date' => $this->option('qc-date'),
            'so_date' => $this->option('so-date'),
            'stock_out_date' => $this->option('stock-out-date'),
        ];

        if (! $this->option('yes')) {
            $confirmed = $this->confirm(
                sprintf(
                    'This will rewrite the linked transaction chain from product %d to %d. Continue?',
                    $payload['from_product_id'],
                    $payload['to_product_id'],
                ),
                false,
            );

            if (! $confirmed) {
                $this->warn('Correction aborted.');

                return self::FAILURE;
            }
        }

        $result = app(CorrectWrongProductChainUseCase::class)->execute($payload);

        $this->info('Product chain corrected successfully.');
        $this->table(
            ['Field', 'Value'],
            [
                ['from_product', sprintf('%d | %s | %s', $result['from_product']['id'], $result['from_product']['code'], $result['from_product']['name'])],
                ['to_product', sprintf('%d | %s | %s', $result['to_product']['id'], $result['to_product']['code'], $result['to_product']['name'])],
                ['purchase_order_line_id', (string) $result['purchase_order_line_id']],
                ['stock_in_line_id', (string) $result['stock_in_line_id']],
                ['sale_order_line_id', (string) ($result['sale_order_line_id'] ?? '-')],
                ['stock_out_line_id', (string) ($result['stock_out_line_id'] ?? '-')],
                ['qc_document_ids', $result['qc_document_ids'] === [] ? '-' : implode(', ', $result['qc_document_ids'])],
                ['updated_stock_item_ids', $result['updated_stock_item_ids'] === [] ? '-' : implode(', ', $result['updated_stock_item_ids'])],
                ['updated_stock_movement_ids', $result['updated_stock_movement_ids'] === [] ? '-' : implode(', ', $result['updated_stock_movement_ids'])],
            ],
        );

        return self::SUCCESS;
    }
)->purpose('Correct a wrong product that was posted through PO, stock in, QC, sale order, stock out, and stock movements.');

Artisan::command(
    'inventory:rebuild-corrected-inbound
        {purchase_order_line_id : The corrected purchase_order_lines.id to move}
        {stock_in_line_id : The corrected stock_in_lines.id to move}
        {supplier_id : The correct suppliers.id for the corrected product}
        {--po-date= : Optional new PO date (YYYY-MM-DD)}
        {--expected-delivery-date= : Optional new expected delivery date (YYYY-MM-DD)}
        {--stock-in-date= : Optional new stock in date (YYYY-MM-DD)}
        {--qc-date= : Optional new QC date (YYYY-MM-DD)}
        {--po-number= : Optional explicit PO number}
        {--stock-in-number= : Optional explicit stock in number}
        {--qc-number= : Optional explicit QC document number}
        {--yes : Skip the confirmation prompt}',
    function () {
        $payload = [
            'purchase_order_line_id' => (int) $this->argument('purchase_order_line_id'),
            'stock_in_line_id' => (int) $this->argument('stock_in_line_id'),
            'supplier_id' => (int) $this->argument('supplier_id'),
            'po_date' => $this->option('po-date'),
            'expected_delivery_date' => $this->option('expected-delivery-date'),
            'stock_in_date' => $this->option('stock-in-date'),
            'qc_date' => $this->option('qc-date'),
            'po_number' => $this->option('po-number'),
            'stock_in_number' => $this->option('stock-in-number'),
            'qc_number' => $this->option('qc-number'),
        ];

        if (! $this->option('yes')) {
            $confirmed = $this->confirm(
                sprintf(
                    'Create a new PO, stock in, and QC document for purchase_order_line_id %d / stock_in_line_id %d?',
                    $payload['purchase_order_line_id'],
                    $payload['stock_in_line_id'],
                ),
                false,
            );

            if (! $confirmed) {
                $this->warn('Inbound rebuild aborted.');

                return self::FAILURE;
            }
        }

        $result = app(RebuildCorrectedInboundChainUseCase::class)->execute($payload);

        $this->info('Corrected inbound chain rebuilt successfully.');
        $this->table(
            ['Field', 'Value'],
            [
                ['new_purchase_order_id', (string) $result['new_purchase_order_id']],
                ['new_po_number', $result['new_po_number']],
                ['new_stock_in_id', (string) $result['new_stock_in_id']],
                ['new_stock_in_number', $result['new_stock_in_number']],
                ['new_stock_in_line_id', (string) $result['new_stock_in_line_id']],
                ['new_qc_document_id', (string) ($result['new_qc_document_id'] ?? '-')],
                ['new_qc_number', (string) ($result['new_qc_number'] ?? '-')],
                ['supplier', sprintf('%d | %s', $result['supplier_id'], $result['supplier_name'])],
                ['product', sprintf('%d | %s | %s', $result['product_id'], $result['product_code'], $result['product_name'])],
                ['moved_stock_item_ids', implode(', ', $result['moved_stock_item_ids'])],
                ['moved_qc_check_ids', $result['moved_qc_check_ids'] === [] ? '-' : implode(', ', $result['moved_qc_check_ids'])],
            ],
        );

        return self::SUCCESS;
    }
)->purpose('Create a new PO, stock in, and QC document for corrected serials and move the inbound references onto those new documents.');

Schedule::command('telegram-invoices:prune-raw-payloads')->daily();
Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=60')
    ->everyMinute()
    ->withoutOverlapping();
