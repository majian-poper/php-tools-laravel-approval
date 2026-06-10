<?php

namespace PHPTools\Approval;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPTools\Approval\Facades\ApprovalFacade;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 * @see \Illuminate\Database\Eloquent\Model
 */
trait ShouldBeApproved
{
    protected Enums\ApprovableEvent $requestEvent = Enums\ApprovableEvent::UPDATING;

    public static function bootShouldBeApproved(): void
    {
        static::registerModelEvent(
            'deleting',
            static function (self $model): ?bool {
                $event = \method_exists($model, 'trashed') ? 'trashing' : 'forceDeleting';

                if ($model->fireModelEvent($event) === false) {
                    return false;
                }

                return null;
            }
        );

        foreach (Enums\ApprovableEvent::cases() as $event) {
            static::registerModelEvent(
                Str::camel($event->value),
                static function (self $model) use ($event): ?bool {
                    if (! static::shouldBeApproved()) {
                        return null;
                    }

                    $model->requestFor($event);

                    DB::transaction(static fn(): Models\ApprovalTask => ApprovalFacade::createTaskFor($model));

                    return false;
                }
            );
        }
    }

    public static function shouldBeApproved(): bool
    {
        return \is_subclass_of(static::class, Contracts\Approvable::class) && ApprovalFacade::isEnabled();
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(config('approval.implementations.approval', Models\Approval::class), 'approvable');
    }

    public function getLabel(): string
    {
        return $this->getTable();
    }

    public function requestFor(Enums\ApprovableEvent $event): self
    {
        $this->requestEvent = $event;

        return $this;
    }

    public function getRequestEvent(): Enums\ApprovableEvent
    {
        return $this->requestEvent;
    }

    public function toApproval(): Models\Approval
    {
        /** @var Models\Approval $approval */
        $approval = $this->approvals()->make($this->toApprovalAttributes());

        return $approval;
    }

    public function toApprovalAttributes(): array
    {
        $event = $this->requestEvent;

        [$oldValues, $newValues] = match ($event) {
            Enums\ApprovableEvent::CREATING => $this->getCreatingAttributes(),
            Enums\ApprovableEvent::UPDATING => $this->getUpdatingAttributes(),
            Enums\ApprovableEvent::TRASHING => $this->getTrashingAttributes(),
            Enums\ApprovableEvent::FORCE_DELETING => $this->getForceDeletingAttributes(),
            Enums\ApprovableEvent::RESTORING => $this->getRestoringAttributes(),
        };

        if (\method_exists($this, $customMethod = 'getCustom' . Str::camel($event->value) . 'Attributes')) {
            [$customOldValues, $customNewValues] = \call_user_func([$this, $customMethod]);

            $oldValues = \array_merge($oldValues, $customOldValues);
            $newValues = \array_merge($newValues, $customNewValues);
        }

        return [
            'event' => $event,
            'old_values' => $oldValues ?: (object) [],
            'new_values' => $newValues ?: (object) [],
        ];
    }

    protected function getCreatingAttributes(): array
    {
        return [[], $this->getAttributes()];
    }

    protected function getUpdatingAttributes(): array
    {
        $old = $new = [];

        foreach ($this->getDirtyForUpdate() as $key => $value) {
            $old[$key] = $this->getRawOriginal($key);
            $new[$key] = $value;
        }

        return [$old, $new];
    }

    protected function getTrashingAttributes(): array
    {
        $deletedAtColumn = $this->getDeletedAtColumn();

        return [
            [$deletedAtColumn => null],
            [$deletedAtColumn => $this->freshTimestampString()]
        ];
    }

    protected function getForceDeletingAttributes(): array
    {
        return [$this->getRawOriginal(), []];
    }

    protected function getRestoringAttributes(): array
    {
        $deletedAtColumn = $this->getDeletedAtColumn();

        return [
            [$deletedAtColumn => $this->fromDateTime($this->getAttribute($deletedAtColumn))],
            [$deletedAtColumn => null]
        ];
    }
}
