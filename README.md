# Laravel Approval

为 Eloquent 模型提供**多步骤、可回滚的审批工作流**。通过 Trait 拦截模型的 `creating / updating / deleting / restoring` 生命周期事件,将变更暂存为审批记录;审批通过后由队列异步、分块地落库,失败或撤回时支持回滚。

特性:

- 拦截 `creating / updating / trashing / forceDeleting / restoring` 事件,自动转为审批任务
- 两种流程类型:`EVERY`(全员通过)与 `ANY`(任一人通过)
- 多层级审批,同 `order_number` 的 approver 可分组(任一通过即推进)
- 异步分块应用变更(`ApproveTaskJob`),支持回滚(`RollBackTaskJob`)
- 可扩展的 `Flow` / `Approver` / `Approvable` / `UserResolver` / `ColumnResolver` 抽象
- 内置 IP、User-Agent、URL 列采集

## 环境要求

- PHP `^8.2`
- Laravel `^12.0`
- 数据库:**PostgreSQL** 或 **MariaDB**(migration 使用了 `jsonb` 列类型,MySQL 暂不兼容)

## 安装

```bash
composer require php-tools/laravel-approval
```

发布并执行 migration:

```bash
php artisan vendor:publish --tag="laravel-approval-migrations"
php artisan migrate
```

发布配置文件:

```bash
php artisan vendor:publish --tag="laravel-approval-config"
```

## 配置

`config/approval.php` 中的主要选项:

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `enabled` | `true` | 全局开关 |
| `enabled_in_console` | `false` | 是否在 console / artisan / 队列环境拦截 |
| `user.guards` | `['web', 'api']` | 解析当前用户的 guards 顺序 |
| `user.resolver` | `Resolvers\UserResolver` | 当前用户解析器 |
| `implementations` | 5 个模型 | 可替换 `approval_flow / approval_flow_step / approval_task / approval / approval_step` |
| `column_resolvers` | `[IpAddress, UserAgent, Url]` | 任务上自动采集的额外列(也会被加入 migration) |
| `chunk_size` | `100` | `ApproveTaskJob` 每批处理的 `approvals` 数量 |
| `default_flow_type` | `ApprovalFlowType::EVERY` | 默认流程类型 |
| `default_expiration` | `7 天` | 任务默认过期秒数 |
| `default_approver_resolver` | `[]` | 默认 approver 解析器列表 |

## 使用

### 1. 让模型支持审批

```php
use Illuminate\Database\Eloquent\Model;
use PHPTools\Approval\Contracts\Approvable;
use PHPTools\Approval\ShouldBeApproved;

class Article extends Model implements Approvable
{
    use ShouldBeApproved;

    public function getUniqueKeyName(): string
    {
        return 'slug';
    }

    public function getUniqueKey(): string
    {
        return (string) $this->slug;
    }

    public function getForeignModelKeys(): array
    {
        // ['App\Models\Author' => 'author_id']
        return [];
    }

    public function getLabel(): string
    {
        return $this->title;
    }
}
```

### 2. 让用户成为审批人

```php
use Illuminate\Contracts\Auth\Authenticatable;
use PHPTools\Approval\Contracts\Approver;

class User extends Authenticatable implements Approver
{
    public function getApproverTitleAttribute(): string
    {
        return $this->name;
    }

    public function contains(Authenticatable $user): bool
    {
        return $this->is($user);
    }

    public function canRollBack(): bool
    {
        return $this->hasRole('admin');
    }
}
```

### 3. 创建审批任务

直接修改模型即可触发拦截:

```php
$article = Article::find(1);
$article->update(['title' => 'New title']);
// 模型未真正更新,而是创建了一个 ApprovalTask,等待审批
```

也可以显式创建,并指定一个 `Flow`:

```php
use PHPTools\Approval\Enums\ApprovalFlowType;
use PHPTools\Approval\Facades\ApprovalFacade;
use PHPTools\Approval\SimpleFlow;

$flow = new SimpleFlow(
    title:      'Publish article',
    description: 'Editorial review',
    flowType:   ApprovalFlowType::EVERY,
    expiresAt:  new DateTimeImmutable('+1 day'),
    approvers:  [$reviewer, [$editorA, $editorB], $chiefEditor],
    //                       ^^^^^^^^^^^^^^^^^^^^
    //          同层级分组:任一通过即可推进到下一层
);

$task = ApprovalFacade::createTaskFor($article, $flow);
```

### 4. 审批与拒绝

```php
// 返回值:本次调用是否使任务进入终态(approving / rejected)。
// 多层级流程下,中间层级通过/拒绝时返回 false,最后一层才返回 true。
$task->approve('looks good', $user);

// 或拒绝
$task->reject('need rework', $user);

// 当用户无权处理该任务时(任务已过期、已不在 pending 状态、该用户不在当前
// 层级审批人内、或已审批过),会抛出 ChangeStatusFailedException。
// 当 EVERY 流程所有 step 通过时,任务进入 approving 状态,
// 并 dispatch ApproveTaskJob 异步将变更落库。
```

### 5. 回滚

```php
if ($task->canBeRolledBackBy($user)) {
    $task->rollBack();
}
```

### 6. 绕过审批

需要在数据迁移、系统任务等场景跳过拦截:

```php
ApprovalFacade::forceRun(function () use ($article) {
    $article->update(['view_count' => $article->view_count + 1]);
});
```

## 数据库表

migration 一次创建 5 张表:

- `approval_flows` / `approval_flow_steps` — 持久化的流程定义
- `approval_tasks` — 一次审批任务(含状态、过期、审批人快照)
- `approvals` — 暂存的变更(`old_values` / `new_values` 为 `jsonb`)
- `approval_steps` — 每个 approver 的审批记录

`approval_tasks` 还会根据 `column_resolvers` 配置**动态追加列**(默认包含 `ip_address`、`user_agent`、`url`)。

## 测试

```bash
composer test
```

## 许可证

MIT License. 详见 [LICENSE](LICENSE.md)。
