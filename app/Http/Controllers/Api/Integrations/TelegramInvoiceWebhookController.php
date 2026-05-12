<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Application\Support\ApiResponse;
use App\Application\Support\UserNotificationService;
use App\Http\Controllers\Controller;
use App\Jobs\Integrations\DownloadTelegramInvoiceAttachmentJob;
use App\Models\InvoiceInboxItem;
use App\Models\TelegramInvoiceMessage;
use App\Services\Integrations\Telegram\TelegramInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TelegramInvoiceWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramInvoiceService $telegram,
        private readonly UserNotificationService $notifications,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $expectedSecret = $this->telegram->webhookSecret();
        $providedSecret = trim((string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''));

        if ($expectedSecret === '' || ! hash_equals($expectedSecret, $providedSecret)) {
            return ApiResponse::error('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $update = $request->json()->all();
        $documentPayload = $this->telegram->extractInvoiceDocument($update);

        if ($documentPayload === null) {
            return ApiResponse::success(['ignored' => true], 'Ignored. No supported PDF attachment found.');
        }

        /** @var array<string, mixed> $message */
        $message = $documentPayload['message'];
        /** @var array<string, mixed> $document */
        $document = $documentPayload['document'];
        $chatId = Arr::get($message, 'chat.id');

        if (! $this->telegram->isAllowedChat($chatId)) {
            return ApiResponse::error('Forbidden chat.', Response::HTTP_FORBIDDEN);
        }

        $createdInboxItem = false;

        DB::transaction(function () use ($update, $message, $document, &$createdInboxItem): void {
            $telegramMessage = TelegramInvoiceMessage::query()->updateOrCreate(
                [
                    'telegram_chat_id' => (string) Arr::get($message, 'chat.id'),
                    'telegram_message_id' => (int) Arr::get($message, 'message_id'),
                ],
                [
                    'telegram_update_id' => Arr::get($update, 'update_id'),
                    'telegram_chat_title' => Arr::get($message, 'chat.title'),
                    'telegram_user_id' => Arr::get($message, 'from.id'),
                    'telegram_username' => Arr::get($message, 'from.username'),
                    'telegram_first_name' => Arr::get($message, 'from.first_name'),
                    'telegram_last_name' => Arr::get($message, 'from.last_name'),
                    'message_text' => Arr::get($message, 'text'),
                    'caption' => Arr::get($message, 'caption'),
                    'message_date' => now()->setTimestamp((int) Arr::get($message, 'date', now()->timestamp)),
                    'raw_payload' => $update,
                    'received_at' => now(),
                ],
            );

            $existingDuplicate = InvoiceInboxItem::query()
                ->where('telegram_file_unique_id', (string) Arr::get($document, 'file_unique_id'))
                ->orderBy('id')
                ->first();

            $inboxItem = InvoiceInboxItem::query()->firstOrCreate(
                [
                    'telegram_invoice_message_id' => $telegramMessage->id,
                ],
                [
                    'source' => 'telegram',
                    'file_disk' => $this->telegram->localDisk(),
                    'original_file_name' => Arr::get($document, 'file_name'),
                    'mime_type' => Arr::get($document, 'mime_type'),
                    'file_size' => Arr::get($document, 'file_size'),
                    'telegram_file_id' => Arr::get($document, 'file_id'),
                    'telegram_file_unique_id' => Arr::get($document, 'file_unique_id'),
                    'download_status' => 'queued',
                    'parse_status' => 'pending',
                    'readability_status' => 'unknown',
                    'is_duplicate' => $existingDuplicate !== null,
                    'duplicate_reason' => $existingDuplicate !== null ? 'matching-telegram-file' : null,
                    'duplicate_of_inbox_item_id' => $existingDuplicate?->id,
                ],
            );

            $createdInboxItem = $inboxItem->wasRecentlyCreated;

            if ($createdInboxItem) {
                DB::afterCommit(function () use ($inboxItem): void {
                    DownloadTelegramInvoiceAttachmentJob::dispatch($inboxItem->id);
                });
            }
        });

        if ($createdInboxItem) {
            $this->notifications->notifyAllActiveUsers(
                eventType: 'telegram-invoice.received',
                title: 'Telegram invoice received',
                message: 'A new invoice PDF was captured from the Telegram invoice group.',
                data: [
                    'source' => 'telegram',
                ],
                level: 'info',
            );
        }

        return ApiResponse::success(
            ['queued' => $createdInboxItem],
            $createdInboxItem ? 'Telegram invoice captured successfully.' : 'Telegram invoice already captured.',
            $createdInboxItem ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }
}
