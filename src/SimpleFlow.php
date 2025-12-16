<?php

namespace EnaTools\Approval;

class SimpleFlow implements Contracts\Flow
{
    public function __construct(
        public string $title,
        public Enums\ApprovalFlowType $type,
        public \DateTimeInterface $expiresAt,
        public iterable $approvers
    ) {}

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getType(): Enums\ApprovalFlowType
    {
        return $this->type;
    }

    public function getExpiresAt(): \DateTimeInterface
    {
        return $this->expiresAt;
    }

    /**
     * @return array<\EnaTools\Approval\Contracts\Approver>
     */
    public function getApprovers(): iterable
    {
        return $this->approvers;
    }
}
