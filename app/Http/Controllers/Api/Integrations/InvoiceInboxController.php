<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Application\Support\ApiResponse;
use App\Application\Support\UserNotificationService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Integrations\InvoiceInboxItemResource;
use App\Models\InvoiceInboxItem;
use App\Models\SaleOrder;
use App\Models\StockOut;
use App\Models\User;
use App\Services\Integrations\Telegram\TelegramInvoiceMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceInboxController extends Controller
{
    public function __construct(
        private readonly TelegramInvoiceMatcher $matcher,
        private readonly UserNotificationService $notifications,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $records = InvoiceInboxItem::query()
            ->with('telegramMessage')
            ->when(trim((string) $request->query('q', '')) !== '', function (Builder $query) use ($request): void {
                $search = '%'.trim((string) $request->query('q')).'%';

                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('original_file_name', 'like', $search)
                        ->orWhere('invoice_number', 'like', $search)
                        ->orWhereHas('telegramMessage', function (Builder $telegramQuery) use ($search): void {
                            $telegramQuery->where('caption', 'like', $search)
                                ->orWhere('telegram_username', 'like', $search)
                                ->orWhere('telegram_chat_title', 'like', $search);
                        });
                });
            })
            ->when(trim((string) $request->query('download_status', '')) !== '', function (Builder $query) use ($request): void {
                $query->where('download_status', trim((string) $request->query('download_status')));
            })
            ->when(trim((string) $request->query('parse_status', '')) !== '', function (Builder $query) use ($request): void {
                $query->where('parse_status', trim((string) $request->query('parse_status')));
            })
            ->when(trim((string) $request->query('match_status', '')) !== '', function (Builder $query) use ($request): void {
                $query->where('match_status', trim((string) $request->query('match_status')));
            })
            ->when($request->has('is_duplicate'), function (Builder $query) use ($request): void {
                $query->where('is_duplicate', $request->boolean('is_duplicate'));
            })
            ->latest()
            ->paginate(max((int) $request->integer('per_page', 15), 1));

        return ApiResponse::paginated(
            $records,
            InvoiceInboxItemResource::collection($records->items()),
            'Invoice inbox records retrieved successfully.',
        );
    }

    public function show(InvoiceInboxItem $invoiceInboxItem): JsonResponse
    {
        return ApiResponse::success(
            new InvoiceInboxItemResource($invoiceInboxItem->load('telegramMessage', 'duplicateOf')),
            'Invoice inbox record retrieved successfully.',
        );
    }

    public function updateReview(Request $request, InvoiceInboxItem $invoiceInboxItem): JsonResponse
    {
        $validated = $request->validate([
            'review_notes' => ['nullable', 'string'],
        ]);

        $invoiceInboxItem->forceFill([
            'review_notes' => isset($validated['review_notes']) && trim((string) $validated['review_notes']) !== ''
                ? trim((string) $validated['review_notes'])
                : null,
            'reviewed_by' => $this->currentUser($request)->id,
            'reviewed_at' => now(),
        ])->save();

        return ApiResponse::success(
            new InvoiceInboxItemResource($invoiceInboxItem->fresh('telegramMessage', 'duplicateOf')),
            'Invoice inbox review updated successfully.',
        );
    }

    public function linkSaleOrder(Request $request, InvoiceInboxItem $invoiceInboxItem): JsonResponse
    {
        $validated = $request->validate([
            'sale_order_id' => ['required', 'integer', Rule::exists('sale_orders', 'id')],
            'review_notes' => ['nullable', 'string'],
        ]);

        $saleOrder = SaleOrder::query()->findOrFail((int) $validated['sale_order_id']);

        $invoiceInboxItem->forceFill([
            'matched_sale_order_id' => $saleOrder->id,
            'matched_stock_out_id' => null,
            'match_status' => 'matched',
            'match_notes' => 'Manually linked to sale order.',
            'matched_at' => now(),
            'review_notes' => isset($validated['review_notes']) && trim((string) $validated['review_notes']) !== ''
                ? trim((string) $validated['review_notes'])
                : $invoiceInboxItem->review_notes,
            'reviewed_by' => $this->currentUser($request)->id,
            'reviewed_at' => now(),
        ])->save();

        $this->notifications->notifyAllActiveUsers(
            eventType: 'telegram-invoice.manually-linked',
            title: 'Telegram invoice manually linked',
            message: sprintf('Invoice inbox item %d was manually linked to sale order %s.', $invoiceInboxItem->id, $saleOrder->so_number),
            data: [
                'invoice_inbox_item_id' => $invoiceInboxItem->id,
                'sale_order_id' => $saleOrder->id,
            ],
            exceptUserId: $this->currentUser($request)->id,
            level: 'info',
        );

        return ApiResponse::success(
            new InvoiceInboxItemResource($invoiceInboxItem->fresh('telegramMessage', 'duplicateOf')),
            'Invoice inbox item linked to sale order successfully.',
        );
    }

    public function linkStockOut(Request $request, InvoiceInboxItem $invoiceInboxItem): JsonResponse
    {
        $validated = $request->validate([
            'stock_out_id' => ['required', 'integer', Rule::exists('stock_out', 'id')],
            'review_notes' => ['nullable', 'string'],
        ]);

        $stockOut = StockOut::query()->findOrFail((int) $validated['stock_out_id']);

        $invoiceInboxItem->forceFill([
            'matched_sale_order_id' => $stockOut->sale_order_id ? (int) $stockOut->sale_order_id : null,
            'matched_stock_out_id' => $stockOut->id,
            'match_status' => 'matched',
            'match_notes' => 'Manually linked to stock out.',
            'matched_at' => now(),
            'review_notes' => isset($validated['review_notes']) && trim((string) $validated['review_notes']) !== ''
                ? trim((string) $validated['review_notes'])
                : $invoiceInboxItem->review_notes,
            'reviewed_by' => $this->currentUser($request)->id,
            'reviewed_at' => now(),
        ])->save();

        $this->notifications->notifyAllActiveUsers(
            eventType: 'telegram-invoice.manually-linked',
            title: 'Telegram invoice manually linked',
            message: sprintf('Invoice inbox item %d was manually linked to stock out %s.', $invoiceInboxItem->id, $stockOut->stock_out_number),
            data: [
                'invoice_inbox_item_id' => $invoiceInboxItem->id,
                'stock_out_id' => $stockOut->id,
            ],
            exceptUserId: $this->currentUser($request)->id,
            level: 'info',
        );

        return ApiResponse::success(
            new InvoiceInboxItemResource($invoiceInboxItem->fresh('telegramMessage', 'duplicateOf')),
            'Invoice inbox item linked to stock out successfully.',
        );
    }

    public function retryMatch(Request $request, InvoiceInboxItem $invoiceInboxItem): JsonResponse
    {
        $result = $this->matcher->match($invoiceInboxItem->loadMissing('telegramMessage'));

        $invoiceInboxItem->forceFill([
            'match_status' => $result['match_status'],
            'matched_sale_order_id' => $result['matched_sale_order_id'],
            'matched_stock_out_id' => $result['matched_stock_out_id'],
            'match_notes' => $result['match_notes'],
            'matched_at' => $result['match_status'] === 'matched' ? now() : null,
            'reviewed_by' => $this->currentUser($request)->id,
            'reviewed_at' => now(),
        ])->save();

        return ApiResponse::success(
            new InvoiceInboxItemResource($invoiceInboxItem->fresh('telegramMessage', 'duplicateOf')),
            'Invoice inbox match retried successfully.',
        );
    }

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
