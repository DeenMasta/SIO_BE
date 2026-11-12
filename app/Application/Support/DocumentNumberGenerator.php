<?php

namespace App\Application\Support;

use App\Models\MissingItemReport;
use App\Models\PurchaseOrder;
use App\Models\QcDocument;
use App\Models\QuickStockOut;
use App\Models\SaleOrder;
use App\Models\StockIn;
use App\Models\StockOut;
use App\Models\Stocktake;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DocumentNumberGenerator
{
    public function generatePurchaseOrderNumber(): string
    {
        return $this->generateNext(
            modelClass: PurchaseOrder::class,
            column: 'po_number',
            prefix: 'PO-'.CarbonImmutable::now()->format('Ymd'),
        );
    }

    public function generateSaleOrderNumber(): string
    {
        return $this->generateNext(
            modelClass: SaleOrder::class,
            column: 'so_number',
            prefix: 'SO-'.CarbonImmutable::now()->format('Ymd'),
        );
    }

    public function generateQuickStockOutNumber(): string
    {
        return $this->generateNext(
            modelClass: QuickStockOut::class,
            column: 'qso_number',
            prefix: 'QSO-'.CarbonImmutable::now()->format('Ymd'),
        );
    }

    public function generateStockOutNumber(): string
    {
        return $this->generateNext(
            modelClass: StockOut::class,
            column: 'stock_out_number',
            prefix: 'SOUT-'.CarbonImmutable::now()->format('Ymd'),
        );
    }

    public function generateStockInNumber(): string
    {
        return $this->generateNext(
            modelClass: StockIn::class,
            column: 'stock_in_number',
            prefix: 'SIN-'.CarbonImmutable::now()->format('Ymd'),
        );
    }

    public function generateQcDocumentNumber(): string
    {
        return $this->generateNext(
            modelClass: QcDocument::class,
            column: 'document_number',
            prefix: 'QC-'.CarbonImmutable::now()->format('Ymd'),
        );
    }

    public function generateStocktakeNumber(): string
    {
        return $this->generateNext(Stocktake::class, 'stocktake_number', 'STK-'.CarbonImmutable::now()->format('Ymd'));
    }

    public function generateMissingItemReportNumber(): string
    {
        return $this->generateNext(MissingItemReport::class, 'report_number', 'MISS-'.CarbonImmutable::now()->format('Ymd'));
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function generateNext(string $modelClass, string $column, string $prefix): string
    {
        return DB::transaction(function () use ($modelClass, $column, $prefix): string {
            $sequenceKey = $modelClass.'|'.$column.'|'.$prefix;
            $created = DB::table('document_number_sequences')->insertOrIgnore([
                'sequence_key' => $sequenceKey,
                'last_number' => 0,
            ]);

            $sequence = DB::table('document_number_sequences')
                ->where('sequence_key', $sequenceKey)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                throw new \RuntimeException('Unable to allocate a document number.');
            }

            $lastNumber = (int) $sequence->last_number;
            if ($created === 1) {
                $pattern = '/^'.preg_quote($prefix, '/').'-([0-9]+)$/';
                foreach ($modelClass::query()->where($column, 'like', $prefix.'-%')->pluck($column) as $number) {
                    if (preg_match($pattern, (string) $number, $matches) === 1) {
                        $lastNumber = max($lastNumber, (int) $matches[1]);
                    }
                }
            }

            $nextNumber = $lastNumber + 1;
            DB::table('document_number_sequences')
                ->where('sequence_key', $sequenceKey)
                ->update(['last_number' => $nextNumber]);

            return $prefix.'-'.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        });
    }
}
