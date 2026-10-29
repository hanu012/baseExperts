<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\SaveCustomerAddressRequest;
use App\Http\Resources\CustomerAddressResource;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The customer's own address book (customer app: location picker and
 * Profile → My addresses). Everything resolves from the caller's token; an
 * address id that belongs to someone else is a 404, never a 403, so ids
 * cannot be probed.
 *
 * NOT PAGINATED, unlike other personal lists: it is capped at
 * CustomerAddress::MAX_PER_CUSTOMER, and the picker needs the whole set to
 * render at once.
 */
class CustomerAddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        if ($customer === null) {
            return $this->noCustomer();
        }

        $addresses = $customer->addresses()
            ->orderByDesc('is_default')
            ->latest()
            ->get();

        return ApiResponse::success(CustomerAddressResource::collection($addresses));
    }

    public function store(SaveCustomerAddressRequest $request): JsonResponse
    {
        $customer = $this->customer($request);

        if ($customer === null) {
            return $this->noCustomer();
        }

        if ($customer->addresses()->count() >= CustomerAddress::MAX_PER_CUSTOMER) {
            return ApiResponse::error(
                'ADDRESS_LIMIT_REACHED',
                'You can save up to '.CustomerAddress::MAX_PER_CUSTOMER.' addresses. Delete one to add another.',
                422
            );
        }

        $address = DB::transaction(function () use ($customer, $request) {
            $address = $customer->addresses()->create($this->attributes($request));

            // The first address is the default whatever was asked — a
            // customer with addresses always has exactly one.
            if ($request->boolean('is_default') || $customer->addresses()->count() === 1) {
                $address->makeDefault();
            }

            return $address;
        });

        return ApiResponse::success(new CustomerAddressResource($address->fresh()), 201);
    }

    public function update(SaveCustomerAddressRequest $request, int $address): JsonResponse
    {
        $record = $this->owned($request, $address);

        if ($record === null) {
            return $this->notFound();
        }

        DB::transaction(function () use ($record, $request) {
            $record->update($this->attributes($request));

            if ($request->boolean('is_default')) {
                $record->makeDefault();
            }
        });

        return ApiResponse::success(new CustomerAddressResource($record->fresh()));
    }

    public function destroy(Request $request, int $address): JsonResponse
    {
        $record = $this->owned($request, $address);

        if ($record === null) {
            return $this->notFound();
        }

        DB::transaction(function () use ($record) {
            $wasDefault = $record->is_default;
            $customerId = $record->customer_id;

            $record->delete();

            // Keep the "exactly one default" rule: the newest remaining
            // address takes over.
            if ($wasDefault) {
                CustomerAddress::query()
                    ->where('customer_id', $customerId)
                    ->latest()
                    ->first()
                    ?->makeDefault();
            }
        });

        return ApiResponse::success(['deleted' => true]);
    }

    /**
     * Marks the address the customer just chose as their default. The app
     * also sends its point to customers/me/location, which is what vendor
     * matching reads.
     */
    public function makeDefault(Request $request, int $address): JsonResponse
    {
        $record = $this->owned($request, $address);

        if ($record === null) {
            return $this->notFound();
        }

        $record->makeDefault();

        return ApiResponse::success(new CustomerAddressResource($record->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(SaveCustomerAddressRequest $request): array
    {
        $attributes = $request->safe()->except('is_default');

        // A stale name from an earlier "other" must not linger on a home
        // or office address.
        if (($attributes['label'] ?? null) !== 'other') {
            $attributes['other_label'] = null;
        }

        return $attributes;
    }

    private function customer(Request $request): ?Customer
    {
        return $request->user()->customer;
    }

    private function owned(Request $request, int $id): ?CustomerAddress
    {
        $customer = $this->customer($request);

        return $customer?->addresses()->whereKey($id)->first();
    }

    private function noCustomer(): JsonResponse
    {
        return ApiResponse::error('NOT_FOUND', 'No customer profile exists for this account.', 404);
    }

    private function notFound(): JsonResponse
    {
        return ApiResponse::error('NOT_FOUND', 'Address not found.', 404);
    }
}
