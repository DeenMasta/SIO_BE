<?php

namespace App\Jobs\Integrations;

use App\Models\InvoiceInboxItem;
use App\Services\Integrations\Telegram\TelegramInvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DownloadTelegramInvoiceAttachmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $invoiceInboxItemId,
    ) {
    }

    public function handle(TelegramInvoiceService $telegram): void
    {
        $item = InvoiceInboxItem::query()
            ->with('telegramMessage')
            ->find($this->invoiceInboxItemId);

        if (! $item || trim((string) $item->telegram_file_id) === '') {
            return;
        }

        if ($item->download_status === 'downloaded' && trim((string) $item->file_path) !== '') {
            return;
        }

        try {
            $item->forceFill([
                'download_status' => 'processing',
                'parse_status' => 'pending',
            ])->save();

            $metadata = $telegram->getFileMetadata((string) $item->telegram_file_id);
            $contents = $telegram->downloadFileContents((string) $metadata['file_path']);
            $fileHash = hash('sha256', $contents);
            $directory = 'telegram-invoices/'.now()->format('Y/m');
            $filename = sprintf(
                '%s-%s.pdf',
                $item->id,
                now()->format('YmdHis')
            );
            $filePath = $directory.'/'.$filename;

            Storage::disk($telegram->localDisk())->put($filePath, $contents);

            DB::transaction(function () use ($item, $fileHash, $filePath, $telegram): void {
                $duplicate = InvoiceInboxItem::query()
                    ->whereKeyNot($item->id)
                    ->where(function ($query) use ($item, $fileHash): void {
                        $query->where('file_hash', $fileHash)
                            ->orWhere(function ($innerQuery) use ($item): void {
                                if (trim((string) $item->telegram_file_unique_id) === '') {
                                    $innerQuery->whereRaw('1 = 0');

                                    return;
                                }

                                $innerQuery->where('telegram_file_unique_id', (string) $item->telegram_file_unique_id);
                            });
                    })
                    ->orderBy('id')
                    ->first();

                $item->forceFill([
                    'file_disk' => $telegram->localDisk(),
                    'file_path' => $filePath,
                    'file_hash' => $fileHash,
                    'download_status' => 'downloaded',
                    'parse_status' => 'pending',
                    'readability_status' => 'text_pdf',
                    'is_duplicate' => $duplicate !== null,
                    'duplicate_reason' => $duplicate !== null ? 'matching-file-hash-or-telegram-file' : null,
                    'duplicate_of_inbox_item_id' => $duplicate?->id,
                    'last_downloaded_at' => now(),
                ])->save();
            });

            ParseTelegramInvoicePdfJob::dispatch($item->id);
        } catch (\Throwable $exception) {
            $item->forceFill([
                'download_status' => 'failed',
                'parse_status' => 'failed',
                'review_notes' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }
}
