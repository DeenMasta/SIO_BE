<?php

namespace App\Http\Resources\Api\QcOutbound;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QcTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'qc_reference_number' => $this->qc_reference_number,
            'stock_in_id' => $this->stock_in_id,
            'qc_date' => $this->qc_date,
            'qc_by' => $this->qc_by,
            'status' => $this->status?->value,
            'remarks' => $this->remarks,
            'lines' => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'stock_in_line_id' => $line->stock_in_line_id,
                'product_id' => $line->product_id,
                'stock_item_id' => $line->stock_item_id,
                'qc_result' => $line->qc_result?->value,
                'qty_pass' => $line->qty_pass,
                'qty_fail' => $line->qty_fail,
                'remarks' => $line->remarks,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
