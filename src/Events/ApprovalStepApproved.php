<?php

namespace EnaTools\Approval\Events;

use EnaTools\Approval\Models\ApprovalStep;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApprovalStepApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ApprovalStep $approval) {}
}
