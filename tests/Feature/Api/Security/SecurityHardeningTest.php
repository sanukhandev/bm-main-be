<?php

namespace Tests\Feature\Api\Security;

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Services\BillingDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiScenario;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use ApiScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiScenario();
        $this->assignCustomerRole($this->branchA, $this->customerA, 'owner');
        $this->actingAs($this->apiUser, 'web');
    }

    public function test_client_cannot_force_branch_or_derived_status(): void
    {
        $customer = $this->branchRequest()->postJson('/api/v1/customers', [
            'branch_id' => $this->branchB,
            'status' => 'inactive',
            'customer_type' => 'individual',
            'display_name' => 'Scoped Customer',
            'roles' => ['owner'],
        ])->assertCreated()->json('data');

        $this->assertSame($this->branchA, $customer['branch_id']);
        $this->assertSame('active', $customer['status']);

        $property = $this->branchRequest()->postJson('/api/v1/properties', [
            'branch_id' => $this->branchB,
            'status' => 'archived',
            'owner_customer_id' => $this->customerA,
            'property_type' => 'apartment',
            'name' => 'Scoped Property',
        ])->assertCreated()->json('data');

        $this->assertSame($this->branchA, $property['branch_id']);
        $this->assertSame('active', $property['status']);
    }

    public function test_cross_branch_foreign_keys_are_rejected(): void
    {
        $propertyB = DB::table('properties')->insertGetId([
            'branch_id' => $this->branchB,
            'owner_customer_id' => $this->customerB,
            'property_code' => 'B-SEC-001',
            'property_type' => 'apartment',
            'name' => 'Other Branch Property',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->branchRequest()->postJson('/api/v1/properties', [
            'owner_customer_id' => $this->customerB,
            'property_type' => 'apartment',
            'name' => 'Invalid Owner Property',
        ])->assertUnprocessable();

        $this->branchRequest()->postJson('/api/v1/maintenance/work-orders', [
            'property_id' => $propertyB,
            'title' => 'Invalid Branch Work Order',
            'description' => 'Should be rejected',
            'priority' => 'normal',
            'lines' => [],
        ])->assertUnprocessable();
    }

    public function test_invoice_rejects_cross_branch_quotation_and_forged_terminal_statuses(): void
    {
        $quotation = DB::table('quotations')->insertGetId([
            'branch_id' => $this->branchB,
            'quotation_no' => 'Q-OTHER-001',
            'title' => 'Other branch quote',
            'quotation_date' => '2026-01-01',
            'subtotal' => '10.00',
            'tax_amount' => '0.00',
            'total_amount' => '10.00',
            'status' => 'draft',
            'created_by' => $this->apiUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payload = [
            'quotation_id' => $quotation,
            'title' => 'Invoice',
            'invoice_date' => '2026-01-01',
            'lines' => [['particulars' => 'Work', 'quantity' => 1, 'unit_price' => 10]],
        ];

        $this->branchRequest()->postJson('/api/v1/invoices', $payload)->assertUnprocessable();
        foreach (['paid', 'void'] as $status) {
            $this->branchRequest()->postJson('/api/v1/invoices', [...$payload, 'quotation_id' => null, 'status' => $status])->assertUnprocessable();
        }
    }

    public function test_billing_reads_allow_accounts_view_or_billing_manage(): void
    {
        $roleId = DB::table('branch_user_roles')
            ->where('branch_id', $this->branchA)
            ->where('user_id', $this->apiUser->id)
            ->value('role_id');
        $accountsViewId = DB::table('permissions')->where('key', 'accounts.view')->value('id');
        $billingManageId = DB::table('permissions')->where('key', 'billing.manage')->value('id');

        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $accountsViewId)->delete();
        $this->branchRequest()->getJson('/api/v1/quotations')->assertOk();
        $this->branchRequest()->getJson('/api/v1/invoices')->assertOk();

        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $billingManageId)->delete();
        $this->branchRequest()->getJson('/api/v1/quotations')->assertForbidden();
        $this->branchRequest()->getJson('/api/v1/invoices')->assertForbidden();
    }

    public function test_agreement_status_and_lifecycle_metadata_cannot_be_forged(): void
    {
        $property = DB::table('properties')->insertGetId([
            'branch_id' => $this->branchA,
            'owner_customer_id' => $this->customerA,
            'property_code' => 'A-SEC-001',
            'property_type' => 'apartment',
            'name' => 'Lifecycle Property',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $agreement = $this->branchRequest()->postJson('/api/v1/owner-agreements', [
            'owner_customer_id' => $this->customerA,
            'property_ids' => [$property],
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => '12000.00',
            'currency_code' => 'AED',
            'payment_count' => 12,
            'payment_mode' => 'cash',
            'installments' => $this->agreementInstallments(12),
        ])->assertCreated()->json('data');

        $this->branchRequest()->patchJson('/api/v1/owner-agreements/'.$agreement['id'], [
            'status' => 'approved',
            'approved_by' => $this->apiUser->id,
            'approved_at' => now()->toIso8601String(),
            'renewed_from_agreement_id' => 999999,
        ])->assertOk();

        $this->assertDatabaseHas('owner_agreements', ['id' => $agreement['id'], 'status' => 'draft']);
    }

    public function test_financial_direction_and_number_are_server_owned(): void
    {
        $agreement = DB::table('tenant_agreements')->insertGetId([
            'branch_id' => $this->branchA,
            'agreement_no' => 'TA-SEC-001',
            'tenant_customer_id' => $this->customerA,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => '1000.00',
            'currency_code' => 'AED',
            'payment_count' => 1,
            'payment_mode' => 'cash',
            'status' => 'commenced',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $installment = DB::table('tenant_agreement_installments')->insertGetId([
            'branch_id' => $this->branchA,
            'tenant_agreement_id' => $agreement,
            'installment_no' => 1,
            'due_date' => '2026-01-01',
            'amount' => '1000.00',
            'paid_amount' => '0.00',
            'payment_mode' => 'cash',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->branchRequest()->withHeader('Idempotency-Key', 'security-payment-001')->postJson('/api/v1/tenant-agreements/'.$agreement.'/payments', [
            'installment_id' => $installment,
            'amount' => '100.00',
            'payment_mode' => 'cash',
            'payment_date' => '2026-09-23',
            'direction' => 'outward',
            'document_no' => 'FORGED-DOCUMENT',
            'posted_by' => 999999,
        ])->assertCreated();

        $response->assertJsonPath('data.direction', 'inward');
        $this->assertNotSame('FORGED-DOCUMENT', $response->json('data.document_no'));
    }

    public function test_inventory_sku_is_unique_within_each_branch(): void
    {
        $payload = ['sku' => 'BRANCH-SKU-1', 'name' => 'Filter', 'unit_of_measure' => 'each'];
        $this->branchRequest()->postJson('/api/v1/maintenance/inventory', $payload)->assertCreated();
        $this->branchRequest()->postJson('/api/v1/maintenance/inventory', [...$payload, 'sku' => 'branch-sku-1'])->assertUnprocessable();
        DB::table('branch_user')->insert(['branch_id' => $this->branchB, 'user_id' => $this->apiUser->id, 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branch_user_roles')->insert(['branch_id' => $this->branchB, 'user_id' => $this->apiUser->id, 'role_id' => DB::table('roles')->where('key', 'branch_admin')->value('id'), 'created_at' => now()]);
        $this->branchRequest($this->branchB)->postJson('/api/v1/maintenance/inventory', $payload)->assertCreated();
    }

    public function test_customer_and_property_deletions_are_audited_without_identity_values(): void
    {
        $customer = DB::table('customers')->insertGetId([
            'branch_id' => $this->branchA, 'customer_code' => 'DEL-001', 'customer_type' => 'individual', 'display_name' => 'Delete Me', 'identity_no' => '784-1990-1234567-1', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $property = DB::table('properties')->insertGetId([
            'branch_id' => $this->branchA, 'owner_customer_id' => $this->customerA, 'property_code' => 'DEL-PROP-001', 'property_type' => 'apartment', 'name' => 'Delete Property', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->branchRequest()->deleteJson('/api/v1/properties/'.$property)->assertNoContent();
        $this->branchRequest()->deleteJson('/api/v1/customers/'.$customer)->assertNoContent();

        $this->assertDatabaseHas('audit_logs', ['action' => 'property.deleted', 'entity_id' => $property]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.deleted', 'entity_id' => $customer]);
        $auditJson = DB::table('audit_logs')->whereIn('action', ['customer.deleted', 'property.deleted'])->get(['before_json', 'after_json'])->toJson();
        $this->assertStringNotContainsString('784-1990-1234567-1', $auditJson);
    }

    public function test_vendor_customer_mutations_require_maintenance_permission_even_without_roles_patch(): void
    {
        DB::table('role_permissions')->where('role_id', DB::table('roles')->where('key', 'branch_admin')->value('id'))
            ->where('permission_id', DB::table('permissions')->where('key', 'maintenance.manage')->value('id'))->delete();
        $this->branchRequest()->postJson('/api/v1/customers', [
            'customer_type' => 'organization', 'display_name' => 'New Vendor', 'roles' => ['vendor'],
        ])->assertForbidden();

        $vendor = $this->customer($this->branchA, 'VENDOR-LOCK');
        $this->assignCustomerRole($this->branchA, $vendor, 'vendor');
        $this->branchRequest()->patchJson('/api/v1/customers/'.$vendor, ['display_name' => 'Changed Vendor'])->assertForbidden();
    }

    public function test_quotation_conversion_is_single_use(): void
    {
        $branch = Branch::query()->findOrFail($this->branchA);
        $billing = app(BillingDocumentService::class);
        $quotation = $billing->quotation($branch, [
            'title' => 'Single conversion', 'quotation_date' => now()->toDateString(),
            'lines' => [['particulars' => 'Work', 'quantity' => '1', 'unit_price' => '10.00']],
        ], $this->apiUser->id);
        $billing->convert($branch, $quotation, $this->apiUser->id);

        try {
            $billing->convert($branch, $quotation, $this->apiUser->id);
            $this->fail('A converted quotation must not create another invoice.');
        } catch (ApiException $exception) {
            $this->assertSame(409, $exception->status);
        }
        $this->assertSame(1, DB::table('invoices')->where('quotation_id', $quotation->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'quotation.converted', 'entity_id' => $quotation->id]);
    }

    public function test_paid_invoice_cannot_be_edited_or_voided(): void
    {
        $invoice = DB::table('invoices')->insertGetId([
            'branch_id' => $this->branchA, 'invoice_no' => 'PAID-SEC-001', 'title' => 'Paid', 'invoice_date' => '2026-01-01',
            'status' => 'paid', 'subtotal' => '10.00', 'tax_amount' => '0.00', 'total_amount' => '10.00',
            'created_by' => $this->apiUser->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payload = ['title' => 'Edited', 'invoice_date' => '2026-01-01', 'lines' => [['particulars' => 'Work', 'quantity' => 1, 'unit_price' => '10.00']]];

        $this->branchRequest()->patchJson('/api/v1/invoices/'.$invoice, $payload)->assertStatus(409)->assertJsonPath('code', 'FINANCIAL_RECORD_IMMUTABLE');
        $this->branchRequest()->deleteJson('/api/v1/invoices/'.$invoice)->assertStatus(409)->assertJsonPath('code', 'FINANCIAL_RECORD_IMMUTABLE');
    }

    public function test_agreement_lifecycle_requires_management_permission(): void
    {
        $property = DB::table('properties')->insertGetId([
            'branch_id' => $this->branchA,
            'owner_customer_id' => $this->customerA,
            'property_code' => 'A-SEC-PERM-001',
            'property_type' => 'apartment',
            'name' => 'Permission Property',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $agreement = $this->branchRequest()->postJson('/api/v1/owner-agreements', [
            'owner_customer_id' => $this->customerA,
            'property_ids' => [$property],
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'total_amount' => '1000.00',
            'currency_code' => 'AED',
            'payment_count' => 1,
            'payment_mode' => 'cash',
            'installments' => $this->agreementInstallments(1),
        ])->assertCreated()->json('data');

        $permissionId = DB::table('permissions')->where('key', 'agreements.manage')->value('id');
        $roleId = DB::table('roles')->where('key', 'branch_admin')->value('id');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();

        $this->branchRequest()->postJson('/api/v1/owner-agreements/'.$agreement['id'].'/submit')
            ->assertForbidden();
    }
}
