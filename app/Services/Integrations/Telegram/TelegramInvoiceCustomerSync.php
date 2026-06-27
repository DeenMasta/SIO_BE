<?php

namespace App\Services\Integrations\Telegram;

use App\Models\Customer;
use Illuminate\Support\Str;

class TelegramInvoiceCustomerSync
{
    public function syncFromParsedDetails(?string $customerName, ?string $phone = null, ?string $address = null): ?Customer
    {
        $normalizedName = $this->normalizeName($customerName);

        if ($normalizedName === null) {
            return null;
        }

        $existingCustomer = Customer::query()
            ->whereRaw('UPPER(customer_name) = ?', [mb_strtoupper($normalizedName)])
            ->first();

        if ($existingCustomer !== null) {
            return $existingCustomer;
        }

        return Customer::query()->create([
            'customer_name' => $normalizedName,
            'phone' => $phone,
            'address' => $address,
            'remarks' => 'Auto-created from Telegram invoice parsing.',
        ]);
    }

    private function normalizeName(?string $customerName): ?string
    {
        $normalizedName = Str::squish((string) $customerName);

        return $normalizedName !== '' ? Str::limit($normalizedName, 150, '') : null;
    }
}
