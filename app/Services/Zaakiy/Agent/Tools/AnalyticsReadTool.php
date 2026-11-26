<?php

namespace App\Services\Zaakiy\Agent\Tools;

use App\Models\User;
use App\Models\ZaakiyThread;
use App\Services\Zaakiy\Agent\ZaakiyAgentTool;
use App\Services\Zaakiy\Agent\ZaakiyToolOutput;
use App\Services\Zaakiy\DTOs\ZaakiyConversationContext;
use App\Services\Zaakiy\IntentFrame;
use App\Services\Zaakiy\ZaakiyExecutionContext;
use App\Services\Zaakiy\ZaakiyReadSkill;
use App\Support\Branch\BranchContext;
use Illuminate\Support\Facades\Validator;

final class AnalyticsReadTool implements ZaakiyAgentTool
{
    /**
     * @param  array<int, string>  $permissions
     * @param  array<int, string>  $focusIntents
     * @param  array<string, array<string, mixed>>  $extraProperties
     */
    public function __construct(
        private readonly string $toolName,
        private readonly string $status,
        private readonly string $description,
        private readonly string $moduleName,
        private readonly string $defaultIntent,
        private readonly ZaakiyReadSkill $skill,
        private readonly array $permissions = [],
        private readonly array $focusIntents = [],
        private readonly array $extraProperties = [],
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function statusLabel(): string
    {
        return $this->status;
    }

    public function declaration(): array
    {
        $properties = [
            'date_from' => ['type' => 'STRING', 'description' => 'Optional start date in YYYY-MM-DD format.'],
            'date_to' => ['type' => 'STRING', 'description' => 'Optional end date in YYYY-MM-DD format.'],
        ];
        if ($this->focusIntents !== []) {
            $properties['focus'] = [
                'type' => 'STRING',
                'enum' => $this->focusIntents,
                'description' => 'Specific sub-analysis to run.',
            ];
        }
        $properties = array_merge($properties, $this->extraProperties);

        return [
            'name' => $this->toolName,
            'description' => $this->description,
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => $properties,
            ],
        ];
    }

    public function requiredPermissions(): array
    {
        return $this->permissions;
    }

    public function isWriteProposal(): bool
    {
        return false;
    }

    public function execute(array $args, User $user, BranchContext $branch, ?ZaakiyThread $thread = null): ZaakiyToolOutput
    {
        $rules = [
            'date_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'property_type' => ['nullable', 'string', 'in:apartment,villa,shop,office,space,labor_camp,warehouse,land'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'agreement_type' => ['nullable', 'string', 'in:owner,tenant'],
            'financial_state' => ['nullable', 'string', 'in:overdue,outstanding,no_outstanding'],
            'owner_id' => ['nullable', 'integer', 'min:1'],
            'minimum_age_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'maintenance_only' => ['nullable', 'boolean'],
            'coverage_issue' => ['nullable', 'boolean'],
        ];
        if ($this->focusIntents !== []) {
            $rules['focus'] = ['nullable', 'string', 'in:'.implode(',', $this->focusIntents)];
        }
        $validator = Validator::make($args, $rules);
        if ($validator->fails()) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'INVALID_ARGUMENTS', 'errors' => $validator->errors()->all()],
                errorCode: 'INVALID_ARGUMENTS',
            );
        }

        $intent = (string) ($args['focus'] ?? $this->defaultIntent);
        $timeRange = (! empty($args['date_from']) && ! empty($args['date_to']) && $args['date_from'] <= $args['date_to'])
            ? ['from' => (string) $args['date_from'], 'to' => (string) $args['date_to']]
            : null;

        $filters = [];
        foreach (['property_type', 'priority', 'agreement_type', 'financial_state'] as $key) {
            if (! empty($args[$key])) {
                $filters[$key] = $args[$key];
            }
        }
        if (! empty($args['minimum_age_days'])) {
            $filters['age_gt'] = (int) $args['minimum_age_days'];
        }
        if (! empty($args['maintenance_only'])) {
            $filters['maintenance'] = true;
        }
        if (! empty($args['coverage_issue'])) {
            $filters['coverage_issue'] = true;
        }

        $entities = [];
        if (! empty($args['owner_id'])) {
            $entities[] = ['type' => 'owner', 'id' => (int) $args['owner_id']];
        }
        $conversation = new ZaakiyConversationContext(
            intent: $intent,
            entities: $entities,
            timeRange: $timeRange,
            filters: $filters,
            branchContext: ['branch_id' => $branch->id(), 'branch_code' => $branch->branch()->code],
        );

        $frame = new IntentFrame(
            intent: $intent,
            modules: [$this->moduleName],
            operation: 'aggregate',
            filters: $filters,
            timeRange: $timeRange,
            question: $intent,
        );
        $result = $this->skill->execute(new ZaakiyExecutionContext($user, $branch, $frame, now()->toIso8601String(), $conversation));

        return new ZaakiyToolOutput(
            modelPayload: $result->toArray(),
            skillResult: $result,
        );
    }
}
