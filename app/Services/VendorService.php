<?php

namespace App\Services;

use App\Models\Customer;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\DB;

class VendorService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly AuditService $audit,
    ) {}

    public function create(BranchContext $context, array $data, int $userId): Customer
    {
        return DB::transaction(function () use ($context, $data, $userId): Customer {
            $customer = new Customer([
                'customer_code' => $this->numbers->next($context->branch(), 'VENDOR_CUSTOMER', (int) now()->format('Y')),
                'customer_type' => $data['customer_type'] ?? 'organization',
                'display_name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
            ]);
            $customer->forceFill(['branch_id' => $context->id(), 'status' => $data['status'] ?? 'active'])->save();
            $customer->businessRoles()->create(['branch_id' => $context->id(), 'role' => 'vendor']);
            $this->audit->record('customer.created', $customer, null, ['customer_code' => $customer->customer_code, 'display_name' => $customer->display_name, 'roles' => ['vendor']], [], $context->id(), $userId);

            return $customer->load('businessRoles');
        });
    }

    public function update(Customer $vendor, array $data, BranchContext $context, int $userId): Customer
    {
        $before = $vendor->only(['display_name', 'phone', 'email', 'status']);
        $vendor->update(['display_name' => $data['name'], 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'status' => $data['status'] ?? $vendor->status]);
        $this->audit->record('customer.updated', $vendor, [...$before, 'role' => 'vendor'], [...$vendor->only(['display_name', 'phone', 'email', 'status']), 'role' => 'vendor'], [], $context->id(), $userId);

        return $vendor->refresh();
    }

    public function archive(Customer $vendor): void
    {
        $vendor->update(['status' => 'inactive']);
    }
}
