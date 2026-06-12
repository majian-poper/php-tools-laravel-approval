<?php

namespace PHPTools\Approval\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPTools\Approval\Enums\ApprovableEvent;
use PHPTools\Approval\Events;
use PHPTools\Approval\Facades\ApprovalFacade;
use PHPTools\Approval\Models\Approval;
use PHPTools\Approval\Models\ApprovalTask;

class ApproveTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ApprovalTask $approvalTask,
        public readonly int $chunkSize,
        public readonly int $page = 1,
    ) {
        $this->approvalTask->unsetRelations();
    }

    public function displayName(): string
    {
        return \sprintf(
            '%s #%d (%d)',
            class_basename($this),
            $this->approvalTask->getKey(),
            $this->page,
        );
    }

    public function handle(): void
    {
        if (! $this->approvalTask->isApproving()) {
            return;
        }

        $query = $this->approvalTask->approvals()->whereAffectable()->reorder()->orderBy('order_number');

        $approvals = (clone $query)->take($this->chunkSize)->get();

        if ($approvals->isNotEmpty()) {
            foreach ($approvals->groupBy('approvable_type') as $typeGroup) {
                foreach ($typeGroup->groupBy('event') as $event => $eventGroup) {
                    $event = ApprovableEvent::from($event);

                    event(new Events\ApprovalsAffecting($eventGroup, $event));

                    $this->affect($eventGroup, $event);

                    event(new Events\ApprovalsAffected($eventGroup, $event));
                }
            }
        }

        if ((clone $query)->exists()) {
            static::dispatch($this->approvalTask, $this->chunkSize, $this->page + 1)->delay(3);
        } else {
            $this->approvalTask->markAsApproved()->save();

            event(new Events\ApproveTaskJobCompleted($this->approvalTask));
        }
    }

    protected function affect(Collection $approvals, ApprovableEvent $event): void
    {
        /** @var Approval $firstApproval */
        $firstApproval = $approvals->first();

        // affect approvables

        $approvableBuilder = $firstApproval->approvable()->getRelated()->newModelQuery();

        $approvableBuilder->getConnection()->transaction(
            fn() => ApprovalFacade::forceRun(
                fn() => match ($event) {
                    ApprovableEvent::CREATING => $this->create($approvableBuilder, $approvals),
                    ApprovableEvent::UPDATING,
                    ApprovableEvent::TRASHING,
                    ApprovableEvent::RESTORING => $this->update($approvableBuilder, $approvals),
                    ApprovableEvent::FORCE_DELETING => $this->forceDelete($approvableBuilder, $approvals),
                }
            )
        );

        // update approvals effected_at timestamps

        $effectedAt = $firstApproval->freshTimestampString();

        $firstApproval->newModelQuery()->upsert(
            $approvals->each->setAttribute('effected_at', $effectedAt)->map->getAttributes()->all(),
            [$firstApproval->getKeyName()],
            ['approvable_id', 'created_unique_key', 'event', 'old_values', 'new_values', 'effected_at']
        );
    }

    protected function create(Builder $approvableBuilder, Collection $approvals): void
    {
        $this->fillForeignKeys($approvableBuilder, $approvals);

        $approvableKeys = $this->getApprovableKeys($approvableBuilder, $approvals->pluck('created_unique_key')->all());

        $update = Collection::make();
        $insert = Collection::make();

        foreach ($approvals as $approval) {
            if ($approvableKeys->has($approval->created_unique_key)) {
                $approval->approvable_id = $approvableKeys->get($approval->created_unique_key);
                $approval->event = ApprovableEvent::UPDATING;

                $update->push($approval);
            } else {
                $insert->push($approval);
            }
        }

        if ($update->isNotEmpty()) {
            $this->update($approvableBuilder, $update);
        }

        if ($insert->isEmpty()) {
            return;
        }

        $approvableBuilder->fillAndInsert($insert->map->new_values->all());

        $insertApprovableKeys = $this->getApprovableKeys($approvableBuilder, $insert->pluck('created_unique_key')->all());

        if ($insertApprovableKeys->isNotEmpty()) {
            foreach ($insert as $approval) {
                $approval->approvable_id = $insertApprovableKeys->get($approval->created_unique_key);
            }
        }
    }

    protected function fillForeignKeys(Builder $approvableBuilder, Collection $approvals): void
    {
        $foreignUniqueKeys = [];

        foreach ($approvals as $approval) {
            /** @var \PHPTools\Approval\Contracts\Approvable | Model $approvable */
            $approvable = $approvableBuilder->make()->setRawAttributes($approval->new_values);

            foreach ($approvable->getForeignModelKeys() as $foreignModel => $foreignKeyName) {
                $foreignUniqueKeys[$foreignModel][$approval->new_values[$foreignKeyName]] = null;
            }

            $approval->setRelation('approvable', $approvable);
        }

        if (empty($foreignUniqueKeys)) {
            return;
        }

        foreach ($foreignUniqueKeys as $foreignModel => &$foreignKeys) {
            $foreignKeys = $this->approvalTask->approvals()
                ->where('approvable_type', Relation::getMorphAlias($foreignModel))
                ->whereNotNull('approvable_id')
                ->whereIn('approvable_unique_key', \array_keys($foreignKeys))
                ->groupLimit(1, 'approvable_unique_key')
                ->pluck('approvable_id', 'approvable_unique_key')
                ->all();
        }

        foreach ($approvals as $approval) {
            /** @var \PHPTools\Approval\Contracts\Approvable | Model $approvable */
            $approvable = $approval->getRelation('approvable');

            foreach ($approvable->getForeignModelKeys() as $foreignModel => $foreignKeyName) {
                $foreignKeyValue = $foreignUniqueKeys[$foreignModel][$approval->new_values[$foreignKeyName]] ?? null;

                $approval->setAttribute("new_values->{$foreignKeyName}", $foreignKeyValue);

                $approvable->setAttribute($foreignKeyName, $foreignKeyValue);
            }

            $approval->created_unique_key = $approvable->getUniqueKey();
        }
    }

    protected function getApprovableKeys(Builder $approvableBuilder, array $createdUniqueKeys): Collection
    {
        $approvableModel = $approvableBuilder->getModel();

        return $approvableBuilder
            ->whereIn(DB::raw($approvableModel->getUniqueKeyName()), $createdUniqueKeys)
            ->pluck($approvableModel->getKeyName(), DB::raw($approvableModel->getUniqueKeyName() . ' as created_unique_key'));
    }

    protected function update(Builder $approvableBuilder, Collection $approvals): void
    {
        $groupedApprovals = $approvals->groupBy('approvable_id');

        $approvables = $approvableBuilder->lockForUpdate()->findMany($groupedApprovals->keys());

        $values = $approvables
            ->each(
                static function (Model $approvable) use ($groupedApprovals) {
                    /** @var Approval $approval */
                    foreach ($groupedApprovals->get($approvable->getKey()) as $approval) {
                        $approval->old_values = $approvable->only(\array_keys($approval->new_values));

                        $approvable->forceFill($approval->new_values);
                    }
                }
            )
            ->filter->isDirty()
            ->map->getAttributes()
            ->all();

        $approvableBuilder->upsert($values, $approvableBuilder->getModel()->getKeyName());
    }

    protected function forceDelete(Builder $approvableBuilder, Collection $approvals): void
    {
        $approvableBuilder->whereKey($approvals->pluck('approvable_id'))->forceDelete();
    }
}
