<?php

namespace App\Http\Requests\Api\PurchasingInbound\StockIn;

use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

class PostStockInRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stock_in_number' => ['nullable', 'string', 'max:50', 'alpha_dash', 'unique:stock_in,stock_in_number'],
            'stock_in_date' => ['required', 'date'],
            'delivery_order_number' => ['nullable', 'string', 'max:50'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'qc_person_id' => ['nullable', 'integer', 'exists:users,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['nullable', 'integer', 'exists:purchase_order_lines,id'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.received_qty' => ['required', 'integer', 'min:1'],
            'lines.*.condition_at_receiving' => ['nullable', 'string', 'max:50'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
            'lines.*.allow_generated_serials' => ['nullable', 'boolean'],

            'lines.*.serial_numbers' => ['nullable', 'array'],
            'lines.*.serial_numbers.*' => ['string', 'max:80', 'distinct'],

            'lines.*.unit_receipts' => ['nullable', 'array'],
            'lines.*.unit_receipts.*.serial_number' => ['nullable', 'string', 'max:80', 'distinct'],
            'lines.*.unit_receipts.*.condition' => ['nullable', 'string', 'max:50'],
            'lines.*.unit_receipts.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $stockInNumber = $this->has('stock_in_number')
            ? trim((string) $this->input('stock_in_number'))
            : null;

        $lines = array_map(function (mixed $line): mixed {
            if (! is_array($line)) {
                return $line;
            }

            if (array_key_exists('serial_numbers', $line) && is_array($line['serial_numbers'])) {
                $line['serial_numbers'] = array_values(array_filter(array_map(
                    static fn (mixed $serial): string => trim((string) $serial),
                    $line['serial_numbers'],
                ), static fn (string $serial): bool => $serial !== ''));
            }

            if (array_key_exists('unit_receipts', $line) && is_array($line['unit_receipts'])) {
                $line['unit_receipts'] = array_values(array_map(
                    static function (mixed $unit): array {
                        $entry = is_array($unit) ? $unit : [];

                        return [
                            'serial_number' => trim((string) ($entry['serial_number'] ?? '')),
                            'condition' => trim((string) ($entry['condition'] ?? '')),
                            'remarks' => array_key_exists('remarks', $entry)
                                ? trim((string) $entry['remarks'])
                                : null,
                        ];
                    },
                    $line['unit_receipts'],
                ));
            }

            return $line;
        }, Arr::wrap($this->input('lines', [])));

        $this->merge([
            'stock_in_number' => $stockInNumber !== '' ? $stockInNumber : null,
            'delivery_order_number' => $this->has('delivery_order_number')
                ? trim((string) $this->input('delivery_order_number'))
                : null,
            'lines' => $lines,
        ]);
    }

    protected function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            $purchaseOrderId = $this->input('purchase_order_id');
            $seenPurchaseOrderLines = [];
            $seenSerials = [];

            foreach (Arr::wrap($this->input('lines', [])) as $index => $line) {
                $lineNumber = $index + 1;
                $purchaseOrderLineId = $line['purchase_order_line_id'] ?? null;

                if ($purchaseOrderId !== null && $purchaseOrderLineId === null) {
                    $validator->errors()->add("lines.$index.purchase_order_line_id", "Line {$lineNumber} must reference a purchase order line.");
                }

                if ($purchaseOrderId === null && $purchaseOrderLineId !== null) {
                    $validator->errors()->add("lines.$index.purchase_order_line_id", "Line {$lineNumber} cannot reference a purchase order line without purchase_order_id.");
                }

                $productId = $line['product_id'] ?? null;
                if ($purchaseOrderId === null && $productId === null) {
                    $validator->errors()->add("lines.$index.product_id", "Line {$lineNumber} requires a product_id.");
                }

                if ($purchaseOrderLineId !== null) {
                    if (array_key_exists((string) $purchaseOrderLineId, $seenPurchaseOrderLines)) {
                        $validator->errors()->add(
                            "lines.$index.purchase_order_line_id",
                            "Line {$lineNumber} duplicates purchase order line {$purchaseOrderLineId}."
                        );
                    }

                    $seenPurchaseOrderLines[(string) $purchaseOrderLineId] = true;
                }

                $allSerials = [
                    ...Arr::wrap($line['serial_numbers'] ?? []),
                    ...array_map(
                        static fn (mixed $unit): string => trim((string) (is_array($unit) ? ($unit['serial_number'] ?? '') : '')),
                        Arr::wrap($line['unit_receipts'] ?? []),
                    ),
                ];

                foreach ($allSerials as $serialIndex => $serial) {
                    $serialValue = trim((string) $serial);
                    if ($serialValue === '') {
                        continue;
                    }

                    $normalized = mb_strtolower($serialValue);

                    if (array_key_exists($normalized, $seenSerials)) {
                        $validator->errors()->add(
                            "lines.$index.serial_numbers.$serialIndex",
                            "Scanned serial {$serialValue} is duplicated in this stock in payload."
                        );
                    }

                    $seenSerials[$normalized] = true;
                }
            }
        });
    }

    protected function allowedFields(): array
    {
        return [
            'stock_in_number',
            'stock_in_date',
            'delivery_order_number',
            'purchase_order_id',
            'supplier_id',
            'qc_person_id',
            'remarks',
            'lines',
        ];
    }
}
