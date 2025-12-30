<?php

namespace PHPTools\Approval\Contracts;

use PHPTools\Approval\Enums\ApprovableEvent;
use PHPTools\Approval\Models\Approval;

interface Approvable
{
    public static function shouldBeApproved(): bool;

    public function getUniqueKeyName(): string;

    public function getUniqueKey(): string;

    public function getForeignModelKeys(): array;

    public function getLabel(): string;

    public function requestFor(ApprovableEvent $event): self;

    public function getRequestEvent(): ApprovableEvent;

    public function toApproval(): Approval;

    public function toApprovalAttributes(): array;
}
