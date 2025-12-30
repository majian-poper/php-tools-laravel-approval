<?php

namespace PHPTools\Approval\Events;

use PHPTools\Approval\Enums\ApprovableEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ApprovalsRolledBack
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Collection $approvals, public readonly ApprovableEvent $event) {}
}
