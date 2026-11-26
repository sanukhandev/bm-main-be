<?php

namespace App\Services\Zaakiy\Agent\Tools;

use App\Models\User;
use App\Models\ZaakiyThread;
use App\Services\Zaakiy\Agent\ZaakiyAgentTool;
use App\Services\Zaakiy\Agent\ZaakiyToolOutput;
use App\Support\Branch\BranchContext;
use App\Support\SqlLike;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class LookupEntitiesTool implements ZaakiyAgentTool
{
    public function name(): string
    {
        return 'lookup_entities';
    }

    public function statusLabel(): string
    {
        return 'Searching branch records...';
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Search active records in the current branch by partial name, unit number, phone, or reference code (tenant, owner, vendor, property, owner_agreement, tenant_agreement, work_order). Always call this first when you do not yet know the exact numeric entity ID.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Search keyword such as a name, unit number, property code, agreement number, or work order number.',
                    ],
                    'entity_type' => [
                        'type' => 'STRING',
                        'enum' => ['all', 'tenant', 'owner', 'vendor', 'property', 'owner_agreement', 'tenant_agreement', 'work_order'],
                        'description' => 'Optional entity category to narrow the search.',
                    ],
                ],
                'required' => ['query'],
            ],
        ];
    }

    public function requiredPermissions(): array
    {
        return [];
    }

    public function isWriteProposal(): bool
    {
        return false;
    }

    public function execute(array $args, User $user, BranchContext $branch, ?ZaakiyThread $thread = null): ZaakiyToolOutput
    {
        $validator = Validator::make($args, [
            'query' => ['required', 'string', 'min:1', 'max:120'],
            'entity_type' => ['sometimes', 'string', 'in:all,tenant,owner,vendor,property,owner_agreement,tenant_agreement,work_order'],
        ]);
        if ($validator->fails()) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'INVALID_ARGUMENTS', 'message' => 'A valid search query is required.'],
                errorCode: 'INVALID_ARGUMENTS',
            );
        }

        $query = trim((string) $args['query']);
        $type = (string) ($args['entity_type'] ?? 'all');
        $branchId = $branch->id();
        $like = SqlLike::contains($query);
        $candidates = [];

        if (in_array($type, ['all', 'tenant', 'owner', 'vendor'], true)) {
            $roles = $type === 'all' ? ['tenant', 'owner', 'vendor'] : [$type];
            $rows = DB::table('customers')
                ->join('customer_role_assignments', function ($join) use ($branchId): void {
                    $join->on('customer_role_assignments.customer_id', '=', 'customers.id')
                        ->where('customer_role_assignments.branch_id', '=', $branchId);
                })
                ->where('customers.branch_id', $branchId)
                ->whereNull('customers.deleted_at')
                ->whereIn('customer_role_assignments.role', $roles)
                ->where(function ($q) use ($like): void {
                    $q->whereRaw("customers.display_name LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("COALESCE(customers.legal_name, '') LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("customers.customer_code LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("COALESCE(customers.phone, '') LIKE ? ESCAPE '!'", [$like]);
                })
                ->orderBy('customers.display_name')
                ->limit(6)
                ->get(['customers.id', 'customers.customer_code', 'customers.display_name', 'customers.status', 'customer_role_assignments.role']);

            foreach ($rows as $row) {
                $candidates[] = [
                    'type' => $row->role,
                    'id' => (int) $row->id,
                    'label' => (string) $row->display_name,
                    'code' => (string) $row->customer_code,
                    'subtitle' => strtoupper((string) $row->role).' · '.$row->customer_code,
                    'status' => (string) $row->status,
                    'follow_up_prompt' => sprintf('Tell me about %s #%d (%s)', $row->role, (int) $row->id, $row->display_name),
                ];
            }
        }

        if (in_array($type, ['all', 'property'], true)) {
            $rows = DB::table('properties')
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($like): void {
                    $q->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("property_code LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("COALESCE(unit_number, '') LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("COALESCE(building_name, '') LIKE ? ESCAPE '!'", [$like]);
                })
                ->orderBy('property_code')
                ->limit(6)
                ->get(['id', 'property_code', 'name', 'building_name', 'property_type', 'status']);

            foreach ($rows as $row) {
                $candidates[] = [
                    'type' => 'property',
                    'id' => (int) $row->id,
                    'label' => (string) $row->name,
                    'code' => (string) $row->property_code,
                    'subtitle' => trim($row->property_code.' · '.($row->building_name ?: $row->property_type)),
                    'status' => (string) $row->status,
                    'follow_up_prompt' => sprintf('Tell me about property #%d (%s)', (int) $row->id, $row->property_code),
                ];
            }
        }

        if (in_array($type, ['all', 'owner_agreement'], true)) {
            $rows = DB::table('owner_agreements')
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($like): void {
                    $q->whereRaw("agreement_no LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("COALESCE(file_no, '') LIKE ? ESCAPE '!'", [$like]);
                })
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'agreement_no', 'status', 'start_date', 'end_date']);

            foreach ($rows as $row) {
                $candidates[] = [
                    'type' => 'owner_agreement',
                    'id' => (int) $row->id,
                    'label' => (string) $row->agreement_no,
                    'code' => (string) $row->agreement_no,
                    'subtitle' => 'Owner Agreement · '.$row->status,
                    'status' => (string) $row->status,
                    'follow_up_prompt' => sprintf('Show details for owner agreement %s', $row->agreement_no),
                ];
            }
        }

        if (in_array($type, ['all', 'tenant_agreement'], true)) {
            $rows = DB::table('tenant_agreements')
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($like): void {
                    $q->whereRaw("agreement_no LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("COALESCE(file_no, '') LIKE ? ESCAPE '!'", [$like]);
                })
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'agreement_no', 'status', 'start_date', 'end_date']);

            foreach ($rows as $row) {
                $candidates[] = [
                    'type' => 'tenant_agreement',
                    'id' => (int) $row->id,
                    'label' => (string) $row->agreement_no,
                    'code' => (string) $row->agreement_no,
                    'subtitle' => 'Tenant Agreement · '.$row->status,
                    'status' => (string) $row->status,
                    'follow_up_prompt' => sprintf('Show details for tenant agreement %s', $row->agreement_no),
                ];
            }
        }

        if (in_array($type, ['all', 'work_order'], true)) {
            $rows = DB::table('work_orders')
                ->where('branch_id', $branchId)
                ->where(function ($q) use ($like): void {
                    $q->whereRaw("work_order_no LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("title LIKE ? ESCAPE '!'", [$like]);
                })
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'work_order_no', 'title', 'priority', 'status']);

            foreach ($rows as $row) {
                $candidates[] = [
                    'type' => 'work_order',
                    'id' => (int) $row->id,
                    'label' => (string) ($row->work_order_no.' — '.$row->title),
                    'code' => (string) $row->work_order_no,
                    'subtitle' => ucfirst((string) $row->priority).' · '.$row->status,
                    'status' => (string) $row->status,
                    'follow_up_prompt' => sprintf('Show maintenance status for %s', $row->work_order_no),
                ];
            }
        }

        $bounded = array_slice($candidates, 0, 8);
        $disambiguation = count($bounded) > 1
            ? ['query' => $query, 'candidates' => array_slice($bounded, 0, 5)]
            : null;

        return new ZaakiyToolOutput(
            modelPayload: [
                'query' => $query,
                'entity_type' => $type,
                'match_count' => count($bounded),
                'ambiguous' => $disambiguation !== null,
                'matches' => $bounded,
            ],
            disambiguation: $disambiguation,
        );
    }
}
