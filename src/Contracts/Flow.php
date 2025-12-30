<?php

namespace PHPTools\Approval\Contracts;

use PHPTools\Approval\Enums\ApprovalFlowType;

interface Flow
{
    public function setTitle(string $title): void;

    public function getTitle(): string;

    public function getType(): ApprovalFlowType;

    public function getExpiresAt(): \DateTimeInterface;

    /**
     * @return array<Approver & \Illuminate\Database\Eloquent\Model>
     */
    public function getApprovers(): iterable;
}
