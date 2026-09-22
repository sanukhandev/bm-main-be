<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $password = Hash::make(
                app()->environment(['local', 'testing']) ? 'password' : Str::random(40),
            );

            $dubai = $this->branch('DXB', 'Dubai Branch', $now);
            $sharjah = $this->branch('SHJ', 'Sharjah Branch', $now);

            $superAdmin = $this->user('super.admin@example.com', 'Super Admin', $password, $now);
            $dubaiAdmin = $this->user('dubai.admin@example.com', 'Dubai Branch Admin', $password, $now);
            $sharjahAdmin = $this->user('sharjah.admin@example.com', 'Sharjah Branch Admin', $password, $now);

            $superRole = DB::table('roles')->where('key', 'super_admin')->value('id');
            $branchRole = DB::table('roles')->where('key', 'branch_admin')->value('id');

            DB::table('user_global_roles')->updateOrInsert(
                ['user_id' => $superAdmin, 'role_id' => $superRole],
                ['created_at' => $now],
            );

            foreach ([
                [$dubai, $superAdmin, true],
                [$sharjah, $superAdmin, false],
                [$dubai, $dubaiAdmin, true],
                [$sharjah, $sharjahAdmin, true],
            ] as [$branchId, $userId, $isDefault]) {
                DB::table('branch_user')->updateOrInsert(
                    ['branch_id' => $branchId, 'user_id' => $userId],
                    ['status' => 'active', 'is_default' => $isDefault, 'created_at' => $now, 'updated_at' => $now],
                );
            }

            foreach ([[$dubai, $dubaiAdmin], [$sharjah, $sharjahAdmin]] as [$branchId, $userId]) {
                DB::table('branch_user_roles')->updateOrInsert(
                    ['branch_id' => $branchId, 'user_id' => $userId, 'role_id' => $branchRole],
                    ['created_at' => $now],
                );
            }

            $dubaiOwner = $this->customer($dubai, 'OWN-DXB-001', 'Dubai Holdings LLC', 'organization', $now);
            $dubaiTenant = $this->customer($dubai, 'TEN-DXB-001', 'Ahmed Al Mansoori', 'individual', $now);
            $sharjahOwner = $this->customer($sharjah, 'OWN-SHJ-001', 'Sharjah Properties LLC', 'organization', $now);
            $sharjahTenant = $this->customer($sharjah, 'TEN-SHJ-001', 'Fatima Hassan', 'individual', $now);

            $this->customerRole($dubai, $dubaiOwner, 'owner', $now);
            $this->customerRole($dubai, $dubaiTenant, 'tenant', $now);
            $this->customerRole($sharjah, $sharjahOwner, 'owner', $now);
            $this->customerRole($sharjah, $sharjahTenant, 'tenant', $now);

            $dubaiProperty1 = $this->property($dubai, $dubaiOwner, 'PROP-DXB-101', 'Flat 101', 'apartment', $now);
            $dubaiProperty2 = $this->property($dubai, $dubaiOwner, 'PROP-DXB-102', 'Flat 102', 'apartment', $now);
            $sharjahProperty = $this->property($sharjah, $sharjahOwner, 'PROP-SHJ-004', 'Shop 04', 'shop', $now);

            $dubaiOwnerAgreement = $this->agreement(
                'owner_agreements',
                $dubai,
                'OA-DXB-001',
                'owner_customer_id',
                $dubaiOwner,
                120000,
                'commenced',
                $now,
            );
            $sharjahOwnerAgreement = $this->agreement(
                'owner_agreements',
                $sharjah,
                'OA-SHJ-001',
                'owner_customer_id',
                $sharjahOwner,
                60000,
                'approved',
                $now,
            );

            $this->ownerAgreementProperty($dubai, $dubaiOwnerAgreement, $dubaiProperty1, $dubaiOwner, $now);
            $this->ownerAgreementProperty($dubai, $dubaiOwnerAgreement, $dubaiProperty2, $dubaiOwner, $now);
            $this->ownerAgreementProperty($sharjah, $sharjahOwnerAgreement, $sharjahProperty, $sharjahOwner, $now);

            $this->installments('owner_agreement_installments', 'owner_agreement_id', $dubaiOwnerAgreement, $dubai, 4, 30000, $now, 'quarterly');
            $this->installments('owner_agreement_installments', 'owner_agreement_id', $sharjahOwnerAgreement, $sharjah, 4, 15000, $now, 'quarterly');

            $dubaiTenantAgreement = $this->agreement(
                'tenant_agreements',
                $dubai,
                'TA-DXB-001',
                'tenant_customer_id',
                $dubaiTenant,
                72000,
                'commenced',
                $now,
            );
            $sharjahTenantAgreement = $this->agreement(
                'tenant_agreements',
                $sharjah,
                'TA-SHJ-001',
                'tenant_customer_id',
                $sharjahTenant,
                36000,
                'draft',
                $now,
            );

            $this->tenantAgreementProperty($dubai, $dubaiTenantAgreement, $dubaiProperty1, $dubaiOwnerAgreement, $now);
            $this->tenantAgreementProperty($sharjah, $sharjahTenantAgreement, $sharjahProperty, $sharjahOwnerAgreement, $now);

            $this->installments('tenant_agreement_installments', 'tenant_agreement_id', $dubaiTenantAgreement, $dubai, 12, 6000, $now, 'monthly');
            $this->installments('tenant_agreement_installments', 'tenant_agreement_id', $sharjahTenantAgreement, $sharjah, 12, 3000, $now, 'monthly');
        });
    }

    private function branch(string $code, string $name, $now): int
    {
        DB::table('branches')->updateOrInsert(
            ['code' => $code],
            [
                'name' => $name,
                'timezone' => 'Asia/Dubai',
                'currency_code' => 'AED',
                'status' => 'active',
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        return (int) DB::table('branches')->where('code', $code)->value('id');
    }

    private function user(string $email, string $name, string $password, $now): int
    {
        DB::table('users')->updateOrInsert(
            ['email' => $email],
            [
                'name' => $name,
                'email_verified_at' => $now,
                'password' => $password,
                'status' => 'active',
                'remember_token' => Str::random(10),
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        return (int) DB::table('users')->where('email', $email)->value('id');
    }

    private function customer(int $branchId, string $code, string $name, string $type, $now): int
    {
        DB::table('customers')->updateOrInsert(
            ['branch_id' => $branchId, 'customer_code' => $code],
            [
                'customer_type' => $type,
                'display_name' => $name,
                'legal_name' => $type === 'organization' ? $name : null,
                'status' => 'active',
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        return (int) DB::table('customers')
            ->where('branch_id', $branchId)
            ->where('customer_code', $code)
            ->value('id');
    }

    private function customerRole(int $branchId, int $customerId, string $role, $now): void
    {
        DB::table('customer_role_assignments')->updateOrInsert(
            ['branch_id' => $branchId, 'customer_id' => $customerId, 'role' => $role],
            ['updated_at' => $now, 'created_at' => $now],
        );
    }

    private function property(int $branchId, int $ownerId, string $code, string $name, string $type, $now): int
    {
        DB::table('properties')->updateOrInsert(
            ['branch_id' => $branchId, 'property_code' => $code],
            [
                'owner_customer_id' => $ownerId,
                'property_type' => $type,
                'name' => $name,
                'unit_number' => preg_match('/\d+$/', $name, $matches) ? $matches[0] : null,
                'status' => 'active',
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        return (int) DB::table('properties')
            ->where('branch_id', $branchId)
            ->where('property_code', $code)
            ->value('id');
    }

    private function agreement(string $table, int $branchId, string $number, string $partyColumn, int $partyId, int $total, string $status, $now): int
    {
        $values = [
            $partyColumn => $partyId,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => $total,
            'currency_code' => 'AED',
            'payment_count' => $table === 'owner_agreements' ? 4 : 12,
            'payment_frequency' => $table === 'owner_agreements' ? 'quarterly' : 'monthly',
            'payment_mode' => 'bank_transfer',
            'status' => $status,
            'commenced_at' => $status === 'commenced' ? $now : null,
            'approved_at' => in_array($status, ['approved', 'commenced'], true) ? $now : null,
            'updated_at' => $now,
            'created_at' => $now,
        ];

        DB::table($table)->updateOrInsert(
            ['branch_id' => $branchId, 'agreement_no' => $number],
            $values,
        );

        return (int) DB::table($table)
            ->where('branch_id', $branchId)
            ->where('agreement_no', $number)
            ->value('id');
    }

    private function ownerAgreementProperty(int $branchId, int $agreementId, int $propertyId, int $ownerId, $now): void
    {
        DB::table('owner_agreement_properties')->updateOrInsert(
            ['owner_agreement_id' => $agreementId, 'property_id' => $propertyId],
            ['branch_id' => $branchId, 'owner_customer_id' => $ownerId, 'updated_at' => $now, 'created_at' => $now],
        );
    }

    private function tenantAgreementProperty(int $branchId, int $agreementId, int $propertyId, int $sourceOwnerAgreementId, $now): void
    {
        DB::table('tenant_agreement_properties')->updateOrInsert(
            ['tenant_agreement_id' => $agreementId, 'property_id' => $propertyId],
            ['branch_id' => $branchId, 'source_owner_agreement_id' => $sourceOwnerAgreementId, 'updated_at' => $now, 'created_at' => $now],
        );
    }

    private function installments(string $table, string $agreementColumn, int $agreementId, int $branchId, int $count, int $amount, $now, string $frequency): void
    {
        for ($number = 1; $number <= $count; $number++) {
            $dueDate = $frequency === 'quarterly'
                ? sprintf('2026-%02d-01', 1 + (($number - 1) * 3))
                : sprintf('2026-%02d-01', $number);

            DB::table($table)->updateOrInsert(
                [$agreementColumn => $agreementId, 'installment_no' => $number],
                [
                    'branch_id' => $branchId,
                    'due_date' => $dueDate,
                    'amount' => $amount,
                    'paid_amount' => 0,
                    'payment_mode' => 'bank_transfer',
                    'status' => 'pending',
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }
}
