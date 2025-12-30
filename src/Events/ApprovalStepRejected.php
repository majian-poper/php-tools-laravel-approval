<?php

namespace PHPTools\Approval\Events;

use PHPTools\Approval\Models\ApprovalStep;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApprovalStepRejected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ApprovalStep $approval) {}
}
