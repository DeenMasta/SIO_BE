<?php

namespace App\Services\Integrations\Telegram;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;

class TelegramInvoiceService
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function allowedChatIds(): array
    {
        $raw = config('services.telegram.allowed_chat_ids', []);

        if (is_array($raw)) {
            $values = $raw;
        } else {
            $values = explode(',', (string) $raw);
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    public function isAllowedChat(string|int|null $chatId): bool
    {
        if ($chatId === null) {
            return false;
        }

        return in_array((string) $chatId, $this->allowedChatIds(), true);
    }

    public function webhookSecret(): string
    {
        return trim((string) config('services.telegram.webhook_secret', ''));
    }

    public function botToken(): string
    {
        return trim((string) config('services.telegram.bot_token', ''));
    }

    public function localDisk(): string
    {
        return (string) config('services.telegram.storage_disk', 'local');
    }

    public function payloadRetentionDays(): int
    {
        return max((int) config('services.telegram.invoice_payload_retention_days', 180), 1);
    }

    /**
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>|null
     */
    public function extractInvoiceDocument(array $update): ?array
    {
        $message = Arr::get($update, 'message');
        if (! is_array($message)) {
            return null;
        }

        $document = Arr::get($message, 'document');
        if (! is_array($document)) {
            return null;
        }

        $mimeType = strtolower(trim((string) ($document['mime_type'] ?? '')));
        $fileName = strtolower(trim((string) ($document['file_name'] ?? '')));

        $isPdf = $mimeType === 'application/pdf' || str_ends_with($fileName, '.pdf');

        if (! $isPdf) {
            return null;
        }

        return [
            'message' => $message,
            'document' => $document,
        ];
    }

    /**
     * @return array{file_path:string}
     */
    public function getFileMetadata(string $fileId): array
    {
        $response = $this->http
            ->baseUrl('https://api.telegram.org')
            ->acceptJson()
            ->get('/bot'.$this->botToken().'/getFile', [
                'file_id' => $fileId,
            ])
            ->throw()
            ->json();

        if (! is_array($response) || ! ($response['ok'] ?? false)) {
            throw new RequestException($this->http->response([
                'ok' => false,
                'description' => 'Telegram getFile failed.',
            ], 502));
        }

        /** @var array{file_path:string} $result */
        $result = (array) ($response['result'] ?? []);

        if (! isset($result['file_path']) || trim((string) $result['file_path']) === '') {
            throw new \RuntimeException('Telegram file path was not returned.');
        }

        return $result;
    }

    public function downloadFileContents(string $telegramFilePath): string
    {
        return (string) $this->http
            ->baseUrl('https://api.telegram.org')
            ->get('/file/bot'.$this->botToken().'/'.$telegramFilePath)
            ->throw()
            ->body();
    }
}
