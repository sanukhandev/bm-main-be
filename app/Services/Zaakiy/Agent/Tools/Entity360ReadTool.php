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

final class Entity360ReadTool implements ZaakiyAgentTool
{
    public function __construct(
        private readonly string $toolName,
        private readonly string $status,
        private readonly string $description,
        private readonly string $intentName,
        private readonly string $idParam,
        private readonly string $entityType,
        private readonly ZaakiyReadSkill $skill,
        private readonly bool $requiresAgreementType = false,
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
            $this->idParam => [
                'type' => 'INTEGER',
                'description' => 'Numeric ID of the record within the active branch (obtain from lookup_entities first if unknown).',
            ],
        ];
        $required = [$this->idParam];
        if ($this->requiresAgreementType) {
            $properties['agreement_type'] = [
                'type' => 'STRING',
                'enum' => ['owner_agreement', 'tenant_agreement'],
                'description' => 'Whether this agreement is an owner_agreement or tenant_agreement.',
            ];
            $required[] = 'agreement_type';
        }

        return [
            'name' => $this->toolName,
            'description' => $this->description,
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => $properties,
                'required' => $required,
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
        $rules = [$this->idParam => ['required', 'integer', 'min:1']];
        if ($this->requiresAgreementType) {
            $rules['agreement_type'] = ['required', 'string', 'in:owner_agreement,tenant_agreement'];
        }
        $validator = Validator::make($args, $rules);
        if ($validator->fails()) {
            return new ZaakiyToolOutput(
                modelPayload: ['error_code' => 'INVALID_ARGUMENTS', 'errors' => $validator->errors()->all()],
                errorCode: 'INVALID_ARGUMENTS',
            );
        }

        $id = (int) $args[$this->idParam];
        $entityType = $this->requiresAgreementType ? (string) $args['agreement_type'] : $this->entityType;
        $question = match ($this->intentName) {
            'property_360' => "property #{$id}",
            'tenant_360' => "tenant #{$id}",
            'owner_360' => "owner #{$id}",
            default => 'this agreement',
        };

        $conversation = new ZaakiyConversationContext(
            intent: $this->intentName,
            entities: [['type' => $entityType, 'id' => $id]],
            branchContext: ['branch_id' => $branch->id(), 'branch_code' => $branch->branch()->code],
        );
        $frame = new IntentFrame(
            intent: $this->intentName,
            modules: [$this->intentName],
            operation: 'detail',
            entities: [$entityType],
            question: $question,
        );
        $result = $this->skill->execute(new ZaakiyExecutionContext($user, $branch, $frame, now()->toIso8601String(), $conversation));

        return new ZaakiyToolOutput(
            modelPayload: $result->toArray(),
            skillResult: $result,
        );
    }
}
