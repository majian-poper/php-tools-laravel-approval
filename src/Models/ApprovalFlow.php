<?php

namespace PHPTools\Approval\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use PHPTools\Approval\Contracts;
use PHPTools\Approval\Enums;

/**
 * @property string $name
 * @property string $approvable_type
 * @property Enums\ApprovalFlowType $flow_type
 * @property int $expiration
 *
 * @property \Illuminate\Database\Eloquent\Collection<int, ApprovalFlowStep> $steps
 */
class ApprovalFlow extends Model implements Contracts\Flow
{
    protected $fillable = [
        'name',
        'approvable_type',
        'flow_type',
        'expiration',
    ];

    protected $casts = [
        'name' => 'string',
        'approvable_type' => 'string',
        'flow_type' => Enums\ApprovalFlowType::class,
        'expiration' => 'int',
    ];

    protected string $title = '';

    protected string $description = '';

    protected static function booted()
    {
        static::deleting(static fn(self $model) => $model->steps()->delete());
    }

    public function steps(?int $orderNumber = null): HasMany
    {
        return $this
            ->hasMany(config('approval.implementations.approval_flow_step', ApprovalFlowStep::class))
            ->when(\is_int($orderNumber), static fn($q) => $q->where('order_number', $orderNumber))
            ->orderBy('order_number')
            ->orderBy('id');
    }

    // TODO: 以下 steps_one ~ steps_five 为 Filament 表单 Repeater/关系字段所需的具名 HasMany.
    // Filament 在编译期需要静态可解析的关系方法名, 无法通过传参的 steps($orderNumber) 实现,
    // 因此暂时硬编码支持最多 5 层分组. 若未来分组层级需要扩展或 Filament 提供动态关系支持,
    // 应当移除这些方法, 改为统一的动态实现.
    public function steps_one(): HasMany
    {
        return $this->steps(1);
    }

    public function steps_two(): HasMany
    {
        return $this->steps(2);
    }

    public function steps_three(): HasMany
    {
        return $this->steps(3);
    }

    public function steps_four(): HasMany
    {
        return $this->steps(4);
    }

    public function steps_five(): HasMany
    {
        return $this->steps(5);
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title ?: $this->name;
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
        return $this->flow_type;
    }

    public function getExpiresAt(): \DateTimeInterface
    {
        return $this->freshTimestamp()->addSeconds($this->expiration);
    }

    public function getApprovers(): array
    {
        return $this
            ->loadMissing(['steps.approver'])
            ->steps
            ->groupBy('order_number')
            ->map(static fn(Collection $steps): array => $steps->pluck('approver')->unique('approver_title')->all())
            ->all();
    }
}
