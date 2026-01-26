<?php

namespace PHPTools\Approval;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;

class ApprovalManager
{
    /**
     * The app instance.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * The configuration repository instance.
     *
     * @var \Illuminate\Contracts\Config\Repository
     */
    protected $config;

    /**
     * Indicates whether the approval manager is enabled.
     *
     * @var bool
     */
    protected bool $enabled = true;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->config = $app->make('config');
    }

    public function enable(bool $enabled = true): void
    {
        $this->enabled = $enabled;
    }

    public function isEnabled(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $configEnabled = $this->config->get('approval.enabled', true);

        if ($this->app->runningInConsole()) {
            return $configEnabled && $this->config->get('approval.enabled_in_console', false);
        }

        return $configEnabled;
    }

    /**
     * Execute the given callback while approval is disabled.
     *
     * @param \Closure $callback
     *
     * @return mixed
     */
    public function forceRun(\Closure $callback)
    {
        if ($enabled = $this->isEnabled()) {
            $this->enable(false);
        }

        $result = $callback();

        $this->enable($enabled);

        return $result;
    }

    /**
     * Create a new approval task for the given approvable model.
     *
     * @param Contracts\Approvable & Model $approvable
     * @param Contracts\Flow | null $flow
     * @param Authenticatable | null $user
     *
     * @return Models\ApprovalTask
     */
    public function createTaskFor(Contracts\Approvable $approvable, ?Contracts\Flow $flow = null, ?Authenticatable $user = null): Models\ApprovalTask
    {
        $flow ??= $this->resolveFlowFor($approvable);
        $user ??= $this->resolveUser();

        return $this->createTask([$approvable], $flow, $user);
    }

    /**
     * Create a new approval task for the given approvable model.
     *
     * @param iterable<Contracts\Approvable & Model> $approvables
     * @param Contracts\Flow $flow
     * @param Authenticatable $user
     *
     * @return Models\ApprovalTask
     *
     * @throws Exceptions\NoApproverForCreationException
     * @throws Exceptions\NoApprovableForCreationException
     */
    public function createTask(iterable $approvables, Contracts\Flow $flow, Authenticatable $user): Models\ApprovalTask
    {
        $taskModelClass = $this->config->get('approval.implementations.approval_task', Models\ApprovalTask::class);

        $task = $taskModelClass::query()->getConnection()->transaction(
            function () use ($taskModelClass, $user, $flow, $approvables): Models\ApprovalTask {
                $task = new $taskModelClass(
                    [
                        'title' => $flow->getTitle(),
                        'flow_type' => $flow->getType(),
                        'status' => Enums\ApprovalStatus::PENDING,
                        'expires_at' => $flow->getExpiresAt(),
                        ...$this->resolveCustomColumns(),
                    ]
                );

                $task->user()->associate($user)->save();

                $this->pushApprovers($task, $flow->getApprovers());

                $this->pushApprovables($task, $approvables);

                return $task;
            }
        );

        event(new Events\ApprovalTaskCreated($task));

        return $task;
    }

    /**
     * Resolve custom columns for approval tasks.
     *
     * @return array<string, mixed>
     */
    public function resolveCustomColumns(): array
    {
        $customs = [];

        foreach (config('approval.column_resolvers', []) as $resolver) {
            if (\is_subclass_of($resolver, Contracts\ColumnResolver::class)) {
                $column = \call_user_func([$resolver, 'name']);
                $value = \call_user_func([$resolver, 'resolve']);

                $customs[$column] = $value;
            }
        }

        return $customs;
    }

    /**
     * @param Contracts\Approvable & Model $approvable
     *
     * @return Contracts\Flow | Models\ApprovalFlow
     */
    public function resolveFlowFor(Contracts\Approvable $approvable): Contracts\Flow
    {
        $flowModelClass = $this->config->get('approval.implementations.approval_flow', Models\ApprovalFlow::class);

        $databaseFlow = $flowModelClass::query()
            ->where('approvable_type', $approvable->getMorphClass())
            ->latest()
            ->first();

        return $databaseFlow ?? $this->makeSimpleFlow(
            __(
                'approval::approval_flow.title',
                ['event' => $approvable->getRequestEvent()->getLabel(), 'label' => $approvable->getLabel()]
            )
        );
    }

    public function makeSimpleFlow(string $title): Contracts\Flow
    {
        return new SimpleFlow(
            $title,
            $this->resolveDefaultFlowType(),
            $this->resolveDefaultExpiresAt(),
            $this->resolveDefaultApprovers()
        );
    }

    /**
     * @return Authenticatable & Model
     */
    public function resolveUser(): Authenticatable
    {
        $userResolver = $this->config->get('approval.user.resolver');

        $user = \is_subclass_of($userResolver, Contracts\UserResolver::class)
            ? \call_user_func([$userResolver, 'resolve'])
            : null;

        throw_if(blank($user), Exceptions\UnauthorizedException::class);

        return $user;
    }

    protected function resolveDefaultFlowType(): Enums\ApprovalFlowType
    {
        $flowType = $this->config->get('approval.default_flow_type');

        if (! $flowType instanceof Enums\ApprovalFlowType) {
            return Enums\ApprovalFlowType::EVERY;
        }

        return $flowType;
    }

    protected function resolveDefaultExpiresAt(): CarbonInterface
    {
        $expiration = $this->config->get('approval.default_expiration');

        if (! \is_numeric($expiration) || ($expiration = (int) $expiration) <= 0) {
            $expiration = 7 * 24 * 60 * 60;
        }

        return Date::now()->endOfDay()->addSeconds($expiration);
    }

    protected function resolveDefaultApprovers(): array
    {
        $resolvers = Arr::wrap($this->config->get('approval.default_approver_resolver'));

        $approvers = [];

        foreach ($resolvers as $resolver) {
            if (\is_subclass_of($resolver, Contracts\ApproverResolver::class)) {
                $approvers[] = \call_user_func([$resolver, 'resolve']);
            }
        }

        return collect($approvers)
            ->ensure(Contracts\Approver::class)
            ->unique('approver_title')
            ->values()
            ->all();
    }

    protected function pushApprovers(Models\ApprovalTask $task, iterable $approvers): Models\ApprovalTask
    {
        $builder = $task->steps()->getRelated()->newModelQuery();

        $orderNumber = 0;

        $stepValues = [];

        /** @var Contracts\Approver & Model $approver */
        foreach ($approvers as $approver) {
            if (! $approver instanceof Contracts\Approver) {
                continue;
            }

            $stepValues[] = [
                'approval_task_id' => $task->id,
                'order_number' => ++$orderNumber,
                'approver_type' => $approver->getMorphClass(),
                'approver_id' => $approver->getKey(),
                'status' => Enums\ApprovalStatus::PENDING,
            ];
        }

        if ($orderNumber === 0) {
            throw new Exceptions\NoApproverForCreationException;
        }

        $builder->fillAndInsert($stepValues);

        return $task;
    }

    protected function pushApprovables(Models\ApprovalTask $task, iterable $approvables): Models\ApprovalTask
    {
        $builder = $task->approvals()->getRelated()->newModelQuery();

        $size = $this->getChunkInsertSize();

        $orderNumber = 0;

        $approvalValues = [];

        /** @var Contracts\Approvable & Model $approvable */
        foreach ($approvables as $approvable) {
            if (! $approvable instanceof Contracts\Approvable) {
                continue;
            }

            $approvalValues[] = [
                'approval_task_id' => $task->id,
                'order_number' => ++$orderNumber,
                'approvable_type' => $approvable->getMorphClass(),
                'approvable_id' => $approvable->getKey(),
                'approvable_unique_key' => $uniqueKey = $approvable->getUniqueKey(),
                'created_unique_key' => $uniqueKey,
                ...$approvable->toApprovalAttributes(),
            ];

            if (count($approvalValues) === $size) {
                $builder->fillAndInsert($approvalValues);

                $approvalValues = [];
            }
        }

        if (count($approvalValues) > 0) {
            $builder->fillAndInsert($approvalValues);
        }

        if ($orderNumber === 0) {
            throw new Exceptions\NoApprovableForCreationException;
        }

        return $task;
    }

    /**
     * @param Contracts\Approvable & Model $approvable
     *
     * @return int
     */
    protected function getChunkInsertSize(): int
    {
        static $chunkSize;

        return $chunkSize ??= collect([$this->config->get('approval.chunk_size'), 100])
            ->filter(static fn($value): bool => \is_int($value) && $value > 0)
            ->first();
    }
}
