<?php

namespace PHPTools\Approval\Enums;

enum ApprovalStatus: string
{
    case PENDING = 'pending';

    case APPROVING = 'approving';

    case APPROVED = 'approved';

    case REJECTED = 'rejected';

    case ROLLING_BACK = 'rolling_back';

    case ROLLED_BACK = 'rolled_back';

    public static function options(): array
    {
        return collect(static::cases())
            ->mapWithKeys(static fn(self $type): array => [$type->value => $type->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return __("approval::enums.status.{$this->name}");
    }
}
