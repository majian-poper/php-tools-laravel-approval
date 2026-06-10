<?php

namespace PHPTools\Approval;

class SimpleFlow implements Contracts\Flow
{
    public function __construct(
        public string $title,
        public string $description,
        public Enums\ApprovalFlowType $type,
        public \DateTimeInterface $expiresAt,
        public iterable $approvers
    ) {}

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getType(): Enums\ApprovalFlowType
    {
        return $this->type;
    }

    public function getExpiresAt(): \DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function getApprovers(): iterable
    {
        return $this->approvers;
    }
}
