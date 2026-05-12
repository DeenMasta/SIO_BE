<?php

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

Schedule::command('telegram-invoices:prune-raw-payloads')->daily();
Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=60')
    ->everyMinute()
    ->withoutOverlapping();
