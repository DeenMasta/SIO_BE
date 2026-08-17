<?php

namespace App\Models;

use App\Domain\InventoryCore\Enums\MissingItemReportStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MissingItemReport extends Model
{
    use HasFactory;

    protected $fillable = ['report_number', 'stocktake_id', 'stocktake_line_id', 'product_id', 'stock_item_id', 'missing_qty', 'status', 'resolution_type', 'stock_out_id', 'investigation_notes', 'resolution_notes', 'reported_by', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['missing_qty' => 'integer', 'resolved_at' => 'datetime', 'status' => MissingItemReportStatus::class];
    }

    public function scopeUnresolved(Builder $query): void
    {
        $query->whereIn('status', [
            MissingItemReportStatus::Open->value,
            MissingItemReportStatus::Investigating->value,
        ]);
    }

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function stocktakeLine(): BelongsTo
    {
        return $this->belongsTo(StocktakeLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function stockOut(): BelongsTo
    {
        return $this->belongsTo(StockOut::class);
    }

    public function reportedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
