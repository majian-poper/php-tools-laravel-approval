<?php

namespace EnaTools\Approval\Events;

use EnaTools\Approval\Models\ApprovalTask;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApprovalTaskApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ApprovalTask $approvalTask) {}
}
