<?php

namespace App\Services\Zaakiy;

use App\Models\Customer;
use App\Models\OwnerAgreement;
use App\Models\Property;
use App\Models\TenantAgreement;
use App\Services\Zaakiy\DTOs\ZaakiyConversationContext;
use App\Services\Zaakiy\DTOs\ZaakiySkillResult;
use Illuminate\Support\Facades\DB;

class EntityResolver
{
    /** @return array<int, array{type: string, agreement: OwnerAgreement|TenantAgreement}> */
    public function resolveAgreements(string $message, ZaakiyExecutionContext $context): array
    {
        $references = collect($context->conversation?->entities ?? [])
            ->merge($context->conversation?->resultReferences ?? [])
            ->filter(fn (array $entity): bool => in_array($entity['type'] ?? null, ['owner_agreement', 'tenant_agreement'], true) && is_numeric($entity['id'] ?? null))
            ->map(fn (array $entity): array => ['type' => $entity['type'], 'id' => (int) $entity['id']])
            ->unique(fn (array $entity): string => $entity['type'].':'.$entity['id'])->values()->all();

        if ($references !== [] && (preg_match('/\b(?:this|that|the)\s+agreement\b|\bit\b/i', $message) === 1 || $context->conversation?->intent === 'agreement_360')) {
            $matches = [];
            foreach ($references as $reference) {
                $model = $reference['type'] === 'owner_agreement' ? OwnerAgreement::class : TenantAgreement::class;
                $agreement = $model::query()->forBranch($context->branchId())->whereKey($reference['id'])->with($reference['type'] === 'owner_agreement' ? ['owner', 'properties'] : ['tenant', 'properties'])->first();
                if ($agreement) {
                    $matches[] = ['type' => $reference['type'], 'agreement' => $agreement];
                }
            }

            return array_slice($matches, 0, 2);
        }

        if (preg_match('/\b(TA[-_][A-Z0-9-]+|OA[-_][A-Z0-9-]+)\b/i', $message, $match) !== 1) {
            return [];
        }

        $reference = strtoupper(str_replace('_', '-', $match[1]));
        $type = str_starts_with($reference, 'OA-') ? 'owner_agreement' : 'tenant_agreement';
        $model = $type === 'owner_agreement' ? OwnerAgreement::class : TenantAgreement::class;
        $agreement = $model::query()->forBranch($context->branchId())->where('agreement_no', $reference)->with($type === 'owner_agreement' ? ['owner', 'properties'] : ['tenant', 'properties'])->first();

        return $agreement ? [['type' => $type, 'agreement' => $agreement]] : [];
    }

    /** @return array<int, Customer> */
    public function resolveOwners(string $message, ZaakiyExecutionContext $context): array
    {
        $query = Customer::query()->forBranch($context->branchId())->whereNull('customers.deleted_at')
            ->whereExists(fn ($roles) => $roles->selectRaw('1')->from('customer_role_assignments')
                ->whereColumn('customer_role_assignments.customer_id', 'customers.id')
                ->where('customer_role_assignments.branch_id', $context->branchId())
                ->where('customer_role_assignments.role', 'owner'));
        $references = collect($context->conversation?->entities ?? [])
            ->filter(fn (array $entity): bool => in_array($entity['type'] ?? null, ['owner', 'customer'], true) && is_numeric($entity['id'] ?? null))
            ->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        if ($references !== [] && ($context->conversation?->intent === 'owner_360' || preg_match('/\b(?:this|that)\s+owner\b|\b(?:he|she|they|their)\b/i', $message) === 1)) {
            return $query->whereIn('customers.id', $references)->limit(2)->get()->all();
        }
        if (preg_match('/\b(?:owner|customer)(?:\s+id)?\s+#?(\d+)\b/i', $message, $match) === 1) {
            return $query->whereKey((int) $match[1])->limit(2)->get()->all();
        }
        if (preg_match('/\b[A-Z0-9]+-[A-Z0-9-]+\b/i', $message, $match) === 1) {
            $coded = (clone $query)->where('customer_code', $match[0])->limit(2)->get()->all();
            if ($coded !== []) {
                return $coded;
            }
        }

        $name = preg_replace('/^(?:tell me about|show (?:owner|me)|give me (?:a )?(?:summary|the details) of|what is|what\'s|show everything important about)\s+/i', '', trim($message));
        $name = preg_replace('/^which\s+of\s+/i', '', (string) $name);
        $name = preg_replace('/\'s\s+properties?.*$/i', '', (string) $name);
        $name = trim((string) preg_replace('/\b(?:owner|customer)\b\s*/i', '', $name), " \t\n\r\0\x0B?!.'");
        if ($name === '' || preg_match('/\b(?:what|does|any|when|which|show)\b/i', $name) === 1) {
            return [];
        }

        return $query->where(function ($customers) use ($name): void {
            $customers->where('display_name', $name)->orWhere('legal_name', $name);
        })->limit(5)->get()->all();
    }

    /** @return array<int, Customer> */
    public function resolveTenants(string $message, ZaakiyExecutionContext $context): array
    {
        $query = Customer::query()->forBranch($context->branchId())->whereNull('customers.deleted_at')
            ->whereExists(fn ($roles) => $roles->selectRaw('1')->from('customer_role_assignments')
                ->whereColumn('customer_role_assignments.customer_id', 'customers.id')
                ->where('customer_role_assignments.branch_id', $context->branchId())
                ->where('customer_role_assignments.role', 'tenant'));
        $references = collect($context->conversation?->entities ?? [])
            ->filter(fn (array $entity): bool => in_array($entity['type'] ?? null, ['tenant', 'customer'], true) && is_numeric($entity['id'] ?? null))
            ->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        if ($references !== [] && (preg_match('/\b(?:this|that)\s+tenant\b|\b(?:he|she|they|their)\b/i', $message) === 1 || ($context->conversation?->domain === 'accounts' && preg_match('/\b(?:tell me about|show|summary)\b/i', $message) === 1))) {
            return $query->whereIn('customers.id', $references)->limit(2)->get()->all();
        }
        if (preg_match('/\b(?:tenant|customer)(?:\s+id)?\s+#?(\d+)\b/i', $message, $match) === 1) {
            return $query->whereKey((int) $match[1])->limit(2)->get()->all();
        }
        if (preg_match('/\b[A-Z0-9]+-[A-Z0-9-]+\b/i', $message, $match) === 1) {
            $coded = (clone $query)->where('customer_code', $match[0])->limit(2)->get()->all();
            if ($coded !== []) {
                return $coded;
            }
        }

        $name = preg_replace('/^(?:tell me about|show (?:tenant|me)|give me (?:a )?(?:summary|the details) of|what is|what\'s|show everything important about)\s+/i', '', trim($message));
        $name = trim((string) preg_replace('/\b(?:tenant|customer)\b\s*/i', '', $name), " \t\n\r\0\x0B?!.\'");
        if ($name === '' || preg_match('/\b(?:what|does|any|when|which|show)\b/i', $name) === 1) {
            return [];
        }

        return $query->where(function ($customers) use ($name): void {
            $customers->where('display_name', $name)->orWhere('legal_name', $name);
        })->limit(5)->get()->all();
    }

    /** @return array<int, Property> */
    public function resolveProperties(string $message, ZaakiyExecutionContext $context): array
    {
        $query = Property::query()->forBranch($context->branchId())->whereNull('properties.deleted_at');
        $ids = collect($context->conversation?->entities ?? [])
            ->filter(fn (array $entity): bool => ($entity['type'] ?? null) === 'property' && is_numeric($entity['id'] ?? null))
            ->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        if ($ids !== [] && preg_match('/\b(?:this|that|the)\s+property\b|\bit\b/i', $message) === 1) {
            return $query->whereIn('properties.id', $ids)->limit(2)->get()->all();
        }

        if (preg_match('/\bP[-_]([A-Z0-9-]+)\b/i', $message, $match) === 1) {
            return $query->where('property_code', 'P-'.$match[1])->limit(2)->get()->all();
        }

        if (preg_match('/\b(?:property|property\s+id)\s+#?(\d+)\b/i', $message, $match) === 1) {
            return $query->whereKey((int) $match[1])->limit(2)->get()->all();
        }

        $name = null;
        if (preg_match('/\b(?:flat|villa|shop|office|warehouse|land|apartment|space)\s+[a-z0-9-]+\b/i', $message, $match) === 1) {
            $name = trim($match[0]);
        }
        if ($name === null) {
            return [];
        }

        return $query->where(function ($properties) use ($name): void {
            $properties->where('name', $name)->orWhere('unit_number', preg_replace('/^\D+\s*/', '', $name));
        })->limit(5)->get()->all();
    }

    public function reauthorize(ZaakiyConversationContext $conversation, ZaakiyExecutionContext $context): ZaakiyConversationContext
    {
        $entities = $this->authorizeReferences($conversation->entities, $context);
        $references = $this->authorizeReferences($conversation->resultReferences, $context);

        return $conversation->with(['entities' => $entities, 'resultReferences' => $references]);
    }

    public function resolve(string $message, ZaakiyExecutionContext $context): ?ZaakiySkillResult
    {
        preg_match_all('/\b(?:TA|OA|P|WO|IR|OV|CUS)[-_][A-Z0-9-]+\b/i', $message, $matches);
        if ($matches[0] === []) {
            return null;
        }

        $records = [];
        foreach (array_values(array_unique($matches[0])) as $reference) {
            $normalized = strtoupper($reference);
            $match = null;
            $type = null;
            if (str_starts_with($normalized, 'P-')) {
                $match = DB::table('properties')->where('branch_id', $context->branchId())->where('property_code', $reference)->first(['id', 'property_code', 'name']);
                $type = 'property';
            } elseif (str_starts_with($normalized, 'TA-')) {
                $match = DB::table('tenant_agreements')->where('branch_id', $context->branchId())->where('agreement_no', $reference)->first(['id', 'agreement_no', 'status']);
                $type = 'tenant_agreement';
            } elseif (str_starts_with($normalized, 'OA-')) {
                $match = DB::table('owner_agreements')->where('branch_id', $context->branchId())->where('agreement_no', $reference)->first(['id', 'agreement_no', 'status']);
                $type = 'owner_agreement';
            } elseif (str_starts_with($normalized, 'WO-')) {
                $match = DB::table('work_orders')->where('branch_id', $context->branchId())->where('work_order_no', $reference)->first(['id', 'work_order_no', 'status']);
                $type = 'work_order';
            } elseif (str_starts_with($normalized, 'CUS-')) {
                $match = DB::table('customers')->where('branch_id', $context->branchId())->where('customer_code', $reference)->whereNull('deleted_at')->first(['id', 'customer_code', 'display_name']);
                $type = 'customer';
            }
            $records[] = $match
                ? ['entity_type' => $type, 'reference' => $reference, 'fields' => (array) $match]
                : ['reference' => $reference, 'ambiguous_or_not_found' => true];
        }

        return new ZaakiySkillResult(
            intent: 'entity_resolution',
            subject: 'resolve branch-scoped business references',
            records: $records,
            warnings: ['Unresolved references are never guessed.'],
            meta: ['branch_scoped' => true],
        );
    }

    private function authorizeReferences(array $references, ZaakiyExecutionContext $context): array
    {
        $authorized = [];
        foreach ($references as $reference) {
            $type = $reference['type'] ?? null;
            $id = filter_var($reference['id'] ?? null, FILTER_VALIDATE_INT);
            if (! is_string($type) || ! $id || ! in_array($type, ['customer', 'owner', 'tenant', 'property', 'owner_agreement', 'tenant_agreement', 'payment', 'work_order', 'invoice', 'quotation'], true)) {
                continue;
            }
            $row = match ($type) {
                'customer', 'owner' => DB::table('customers')->where('branch_id', $context->branchId())->whereKey($id)->whereNull('deleted_at')->first(['id', 'customer_code as label']),
                'tenant', 'owner' => DB::table('customers')->where('branch_id', $context->branchId())->whereKey($id)->whereNull('deleted_at')->whereExists(fn ($query) => $query->selectRaw('1')->from('customer_role_assignments')->whereColumn('customer_role_assignments.customer_id', 'customers.id')->where('customer_role_assignments.branch_id', $context->branchId())->where('customer_role_assignments.role', $type))->first(['id', 'customer_code as label']),
                'property' => DB::table('properties')->where('branch_id', $context->branchId())->whereKey($id)->first(['id', 'property_code as label']),
                'owner_agreement' => DB::table('owner_agreements')->where('branch_id', $context->branchId())->whereKey($id)->first(['id', 'agreement_no as label']),
                'tenant_agreement' => DB::table('tenant_agreements')->where('branch_id', $context->branchId())->whereKey($id)->first(['id', 'agreement_no as label']),
                'work_order' => DB::table('work_orders')->where('branch_id', $context->branchId())->whereKey($id)->first(['id', 'work_order_no as label']),
                'invoice' => DB::table('invoices')->where('branch_id', $context->branchId())->whereKey($id)->first(['id', 'invoice_no as label']),
                'quotation' => DB::table('quotations')->where('branch_id', $context->branchId())->whereKey($id)->first(['id', 'quotation_no as label']),
                'payment' => $context->can('accounts.view') ? DB::table('account_transactions')->where('branch_id', $context->branchId())->whereKey($id)->first(['id', 'document_no as label']) : null,
            };
            if ($row) {
                $authorized[] = ['type' => $type, 'id' => (int) $row->id, 'label' => (string) $row->label];
            }
        }

        return array_values(array_slice($authorized, 0, 25));
    }
}
