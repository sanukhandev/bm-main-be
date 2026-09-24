<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\OwnerAgreement;
use App\Models\TenantAgreement;
use App\Services\AgreementLifecycleService;
use Illuminate\Console\Command;
use Throwable;

class ProcessAgreementLifecycle extends Command
{
    protected $signature = 'agreements:process-lifecycle';

    protected $description = 'Commence and expire eligible owner and tenant agreements';

    public function handle(AgreementLifecycleService $lifecycle): int
    {
        $processed = 0;

        foreach (Branch::query()->where('status', 'active')->cursor() as $branch) {
            $today = now($branch->timezone ?: config('app.timezone'))->toDateString();
            foreach ([['owner', OwnerAgreement::class], ['tenant', TenantAgreement::class]] as [$type, $model]) {
                $model::query()->forBranch($branch->id)->where(function ($query) use ($today) {
                    $query->where(fn ($query) => $query->where('status', 'approved')->whereDate('start_date', '<=', $today))
                        ->orWhere(fn ($query) => $query->whereIn('status', ['commenced', 'on_hold'])->whereDate('end_date', '<', $today));
                })->pluck('id')->each(function (int $id) use ($type, $branch, $lifecycle, &$processed): void {
                    try {
                        $agreement = $type === 'owner' ? OwnerAgreement::query()->forBranch($branch->id)->find($id) : TenantAgreement::query()->forBranch($branch->id)->find($id);
                        if (! $agreement) {
                            return;
                        }
                        if ($agreement->status === 'approved') {
                            $lifecycle->transition($type, $id, $branch->id, 'commenced', null, null);
                        } else {
                            $lifecycle->transition($type, $id, $branch->id, 'expired', null, null);
                        }
                        $processed++;
                    } catch (Throwable $exception) {
                        report($exception);
                        $this->error("{$type} agreement {$id}: {$exception->getMessage()}");
                    }
                });
            }
        }

        $this->info("Processed {$processed} agreement lifecycle changes.");

        return self::SUCCESS;
    }
}
