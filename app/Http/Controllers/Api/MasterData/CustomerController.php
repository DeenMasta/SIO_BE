<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Application\MasterData\Customers\UseCases\CreateCustomerUseCase;
use App\Application\MasterData\Customers\UseCases\DeleteCustomerUseCase;
use App\Application\MasterData\Customers\UseCases\ListCustomersUseCase;
use App\Application\MasterData\Customers\UseCases\UpdateCustomerUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MasterData\Customer\StoreCustomerRequest;
use App\Http\Requests\Api\MasterData\Customer\UpdateCustomerRequest;
use App\Http\Resources\Api\MasterData\CustomerResource;
use App\Models\Customer;
use App\Models\QuickStockOut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        private readonly ListCustomersUseCase $listCustomers,
        private readonly CreateCustomerUseCase $createCustomer,
        private readonly UpdateCustomerUseCase $updateCustomer,
        private readonly DeleteCustomerUseCase $deleteCustomer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $customers = $this->listCustomers->execute([
            'per_page' => (int) $request->integer('per_page', 15),
            'q' => trim((string) $request->query('q', '')),
            'status' => $request->query('status'),
        ]);

        return ApiResponse::paginated(
            $customers,
            CustomerResource::collection($customers->items()),
            'Customers retrieved successfully.',
        );
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $customer = $this->createCustomer->execute($request->validated());

        return ApiResponse::success(new CustomerResource($customer), 'Customer created successfully.', 201);
    }

    public function storeForQuickStockOut(StoreCustomerRequest $request): JsonResponse
    {
        $this->authorize('create', QuickStockOut::class);

        $customer = $this->createCustomer->execute($request->validated());

        return ApiResponse::success(new CustomerResource($customer), 'Customer created for quick stock out successfully.', 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $customer->load([
            'saleOrders' => fn ($query) => $query
                ->whereNotNull('invoice_number')
                ->where('invoice_number', '!=', '')
                ->orderByDesc('so_date')
                ->orderByDesc('id'),
        ]);

        return ApiResponse::success(new CustomerResource($customer), 'Customer retrieved successfully.');
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $updated = $this->updateCustomer->execute([
            'customer' => $customer,
            ...$request->validated(),
        ]);

        return ApiResponse::success(new CustomerResource($updated), 'Customer updated successfully.');
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        $this->deleteCustomer->execute($customer);

        return ApiResponse::success(null, 'Customer deleted successfully.');
    }
}
