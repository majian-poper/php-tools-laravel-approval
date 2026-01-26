<?php

namespace PHPTools\Approval\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use PHPTools\Approval\Models\ApprovalTask;

class ApproveTaskJobCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ApprovalTask $approvalTask) {}
}
