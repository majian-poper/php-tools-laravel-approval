<?php

namespace PHPTools\Approval\Enums;

enum ApprovableEvent: string
{
    case CREATING = 'creating';

    case UPDATING = 'updating';

    case RESTORING = 'restoring';

    case TRASHING = 'trashing';

    case FORCE_DELETING = 'force-deleting';

    public static function tryFromActionName(string $methodName, bool $withTrashed = false): ?self
    {
        return match ($methodName) {
            'create' => static::CREATING,
            'update' => static::UPDATING,
            'restore' => static::RESTORING,
            'delete' => $withTrashed ? static::TRASHING : static::FORCE_DELETING,
            'forceDelete' => static::FORCE_DELETING,
            default => null,
        };
    }

    public static function options(): array
    {
        return collect(static::cases())
            ->mapWithKeys(static fn(self $type): array => [$type->value => $type->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return __("approval::enums.event.{$this->name}");
    }
}
