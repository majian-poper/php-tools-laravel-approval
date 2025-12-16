<?php

namespace EnaTools\Approval\Facades;

use EnaTools\Approval\ApprovalManager;
use EnaTools\Approval\Contracts;
use EnaTools\Approval\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void enable(bool $enabled = true)
 * @method static bool isEnabled()
 * @method static mixed forceRun(\Closure $callback)
 * @method static Models\ApprovalTask createTaskFor(Contracts\Approvable $approvable, ?Contracts\Flow $flow = null, ?Authenticatable $user = null)
 * @method static Models\ApprovalTask createTask(iterable $approvables, Contracts\Flow $flow, Authenticatable $user)
 * @method static array<string, mixed> resolveCustomColumns()
 * @method static Authenticatable & Model resolveUser()
 * @method static Contracts\Flow | Models\ApprovalFlow resolveFlowFor(Contracts\Approvable $approvable)
 * @method static Contracts\Flow makeSimpleFlow(string $title)
 */
class ApprovalFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ApprovalManager::class;
    }
}
