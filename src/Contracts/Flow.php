<?php

namespace PHPTools\Approval\Contracts;

use PHPTools\Approval\Enums\ApprovalFlowType;

interface Flow
{
    public function setTitle(string $title): static;

    public function getTitle(): string;

    public function setDescription(string $description): static;

    public function getDescription(): string;

    public function getType(): ApprovalFlowType;

    public function getExpiresAt(): \DateTimeInterface;

    /**
     * @return array<Approver | array<Approver>>
     */
    public function getApprovers(): iterable;
}
