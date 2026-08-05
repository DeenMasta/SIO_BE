<?php

namespace App\Application\ExceptionsReturns\CustomerReturns\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Domain\ExceptionsReturns\Enums\CustomerExchangeStatus;
use App\Domain\ExceptionsReturns\Enums\ExceptionTransactionStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Domain\SalesOutbound\Enums\SaleOrderStatus;
use App\Models\CustomerExchange;
use App\Models\CustomerReturn;
use App\Models\SaleOrder;
use App\Models\StockOut;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCustomerExchangeUseCase implements UseCase
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function execute(mixed $payload = null): CustomerExchange
    {
        $data = (array) $payload;

        return DB::transaction(function () use ($data): CustomerExchange {
            $return = CustomerReturn::query()
                ->with('lines')
                ->lockForUpdate()
                ->findOrFail((int) $data['customer_return_id']);

            if ($return->status !== ExceptionTransactionStatus::Posted) {
                throw ValidationException::withMessages([
                    'customer_return_id' => ['Only POSTED customer returns can create a customer exchange.'],
                ]);
            }

            if ($return->exchange()->exists()) {
                throw ValidationException::withMessages([
                    'customer_return_id' => ['This customer return already has an exchange.'],
                ]);
            }

            $originalStockOut = StockOut::query()
                ->lockForUpdate()
                ->findOrFail((int) $return->original_stock_out_id);

            if ($originalStockOut->sale_order_id === null) {
                throw ValidationException::withMessages([
                    'customer_return_id' => ['Customer exchange requires the original stock out to be linked to a sale order.'],
                ]);
            }

            $saleOrder = SaleOrder::query()->lockForUpdate()->findOrFail((int) $originalStockOut->sale_order_id);
            if ((int) $saleOrder->customer_id !== (int) $return->customer_id) {
                throw ValidationException::withMessages([
                    'customer_return_id' => ['Original sale order customer must match the customer return.'],
                ]);
            }

            if (! in_array($saleOrder->status, [SaleOrderStatus::Confirmed, SaleOrderStatus::Fulfilled], true)) {
                throw ValidationException::withMessages([
                    'customer_return_id' => ['Customer exchange requires a CONFIRMED or FULFILLED original sale order.'],
                ]);
            }

            $returnLines = $return->lines->keyBy('id');
            foreach ((array) $data['lines'] as $index => $line) {
                $returnLineId = (int) $line['customer_return_line_id'];
                if (! $returnLines->has($returnLineId)) {
                    throw ValidationException::withMessages([
                        "lines.$index.customer_return_line_id" => ['The returned line does not belong to this customer return.'],
                    ]);
                }
            }

            $exchange = CustomerExchange::query()->create([
                'customer_return_id' => $return->id,
                'sale_order_id' => $saleOrder->id,
                'status' => CustomerExchangeStatus::Pending,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => (int) $data['created_by'],
            ]);

            foreach ((array) $data['lines'] as $line) {
                $exchangeLine = $exchange->lines()->create([
                    'customer_return_line_id' => (int) $line['customer_return_line_id'],
                    'replacement_product_id' => (int) $line['replacement_product_id'],
                    'qty' => (int) $line['qty'],
                    'remarks' => $line['remarks'] ?? null,
                ]);

                $saleOrderLine = $saleOrder->lines()->create([
                    'line_type' => 'EXCHANGE',
                    'product_id' => (int) $line['replacement_product_id'],
                    'ordered_qty' => (int) $line['qty'],
                    'fulfilled_qty' => 0,
                    'is_free' => true,
                    'unit_price' => 0,
                    'subtotal' => 0,
                    'remarks' => $line['remarks'] ?? 'Customer exchange',
                ]);

                $exchangeLine->update(['sale_order_line_id' => $saleOrderLine->id]);
            }

            if ($saleOrder->status === SaleOrderStatus::Fulfilled) {
                $saleOrder->update(['status' => SaleOrderStatus::Confirmed]);
            }

            $result = $exchange->fresh([
                'customerReturn',
                'saleOrder',
                'lines.customerReturnLine',
                'lines.replacementProduct',
                'lines.saleOrderLine',
            ]);

            $this->auditLogger->log(
                userId: (int) $data['created_by'],
                moduleName: 'ExceptionsReturns',
                entityName: 'CustomerExchange',
                entityId: (int) $result->id,
                action: AuditAction::Create,
                newValues: [
                    'customer_return_id' => (int) $result->customer_return_id,
                    'sale_order_id' => (int) $result->sale_order_id,
                    'status' => $result->status?->value,
                ],
            );

            return $result;
        });
    }
}
