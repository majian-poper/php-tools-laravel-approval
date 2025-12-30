<?php

namespace PHPTools\Approval\Enums;

enum ApprovalFlowType: string
{
    case EVERY = 'every';

    case ANY = 'any';

    public static function options(): array
    {
        return collect(static::cases())
            ->mapWithKeys(static fn(self $type): array => [$type->value => $type->getLabel()])
            ->toArray();
    }

    public function getLabel(): string
    {
        return __("approval::enums.flow-type.{$this->name}");
    }
}
