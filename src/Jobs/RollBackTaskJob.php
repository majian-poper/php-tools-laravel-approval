<?php

namespace EnaTools\Approval\Jobs;

use EnaTools\Approval\Enums\ApprovableEvent;
use EnaTools\Approval\Events;
use EnaTools\Approval\Facades\ApprovalFacade;
use EnaTools\Approval\Models\Approval;
use EnaTools\Approval\Models\ApprovalTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RollBackTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes

    public function __construct(public readonly ApprovalTask $approvalTask, public readonly int $chunkSize) {}

    public function displayName(): string
    {
        return \get_class($this) . ' #' . $this->approvalTask->getKey();
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->displayName()))->dontRelease()];
    }

    public function handle(): void
    {
        if (! $this->approvalTask->isRollingBack()) {
            return;
        }

        $query = $this->approvalTask->approvals()->whereEffected()->whereNotRolledBack()->reorder()->orderByDesc('order_number');

        while (true) {
            $approvals = (clone $query)->take($this->chunkSize)->get();

            if ($approvals->isEmpty()) {
                break;
            }

            foreach ($approvals->groupBy('approvable_type') as $typeGroup) {
                foreach ($typeGroup->groupBy('event') as $event => $eventGroup) {
                    $event = ApprovableEvent::from($event);

                    event(new Events\ApprovalsRollingBack($approvals, $event));

                    $this->rollBack($eventGroup, $event);

                    event(new Events\ApprovalsRolledBack($approvals, $event));
                }
            }
        }

        $this->approvalTask->markAsRolledBack()->save();

        event(new Events\RollBackTaskJobCompleted($this->approvalTask));
    }

    protected function rollBack(Collection $approvals, ApprovableEvent $event): void
    {
        /** @var Approval $firstApproval */
        $firstApproval = $approvals->first();

        // roll back approvables

        $approvableBuilder = $firstApproval->approvable()->getRelated()->newModelQuery();

        $approvableBuilder->getQuery()->getConnection()->transaction(
            fn() => ApprovalFacade::forceRun(
                fn() => match ($event) {
                    ApprovableEvent::CREATING => $this->rollBackCreate($approvableBuilder, $approvals),
                    ApprovableEvent::UPDATING,
                    ApprovableEvent::TRASHING,
                    ApprovableEvent::RESTORING => $this->rollBackUpdate($approvableBuilder, $approvals),
                    ApprovableEvent::FORCE_DELETING => $this->rollBackForceDelete($approvableBuilder, $approvals),
                }
            )
        );

        // update approvals rolled_back_at timestamps

        $firstApproval->newModelQuery()
            ->whereKey($approvals->modelKeys())
            ->update(['rolled_back_at' => $firstApproval->freshTimestampString()]);
    }

    protected function rollBackCreate(Builder $approvableBuilder, Collection $approvals): void
    {
        $approvableBuilder->whereKey($approvals->pluck('approvable_id'))->forceDelete();
    }

    protected function rollBackUpdate(Builder $approvableBuilder, Collection $approvals): void
    {
        $groupedApprovals = $approvals->groupBy('approvable_id');

        $approvables = $approvableBuilder->lockForUpdate()->findMany($groupedApprovals->keys());

        $values = $approvables
            ->each(
                static function ($approvable) use ($groupedApprovals) {
                    /** @var Approval $approval */
                    foreach ($groupedApprovals->get($approvable->getKey()) as $approval) {
                        $approvable->forceFill($approval->old_values);
                    }
                }
            )
            ->filter->isDirty()
            ->map->getAttributes()
            ->all();

        $approvableBuilder->upsert($values, $approvableBuilder->getModel()->getKeyName());
    }

    protected function rollBackForceDelete(Builder $approvableBuilder, Collection $approvals): void
    {
        $values = [];

        foreach ($approvals as $approval) {
            $values[$approval->approvable_id] = $approvableBuilder->make()
                ->forceFill($approval->old_values)
                ->getAttributes();
        }

        $approvableBuilder->fillAndInsert(\array_values($values));
    }
}
