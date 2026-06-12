<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPTools\Approval\Contracts;
use PHPTools\Approval\Enums;

return new class extends Migration
{
    public function up()
    {
        Schema::create(
            'approval_flows',
            function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('approvable_type');
                $table->unsignedInteger('expiration');
                $table->string('flow_type')->default(Enums\ApprovalFlowType::EVERY->value);
                $table->timestamps();
            }
        );

        Schema::create(
            'approval_flow_steps',
            function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('approval_flow_id');
                $table->unsignedInteger('order_number');
                $table->morphs('approver', 'approval_flow_steps_approver_index'); // user / role
                $table->timestamps();

                $table->index(['approval_flow_id', 'order_number'], 'approval_flow_steps_order_index');
            }
        );

        Schema::create(
            'approval_tasks',
            function (Blueprint $table) {
                $table->id();
                $table->string('title')->default('');
                $table->text('description')->nullable();
                $table->morphs('user', 'approval_tasks_user_index');
                $table->string('flow_type')->default(Enums\ApprovalFlowType::EVERY->value);
                $table->string('status')->default(Enums\ApprovalStatus::PENDING->value);
                $table->timestamp('expires_at');
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('rolled_back_at')->nullable();

                foreach (config('approval.column_resolvers', []) as $resolver) {
                    if (\is_subclass_of($resolver, Contracts\ColumnResolver::class)) {
                        $type = \call_user_func([$resolver, 'type']);
                        $name = \call_user_func([$resolver, 'name']);

                        $table->{$type}($name)->nullable();
                    }
                }

                $table->timestamps();
            }
        );

        Schema::create(
            'approvals',
            function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('approval_task_id');
                $table->unsignedInteger('order_number');
                $table->string('approvable_type');
                $table->string('approvable_id')->nullable();
                $table->string('approvable_unique_key');
                $table->string('created_unique_key');
                $table->string('event'); // creating, updating, deleting
                $table->jsonb('old_values');
                $table->jsonb('new_values');
                $table->timestamp('effected_at')->nullable();
                $table->timestamp('rolled_back_at')->nullable();
                $table->timestamps();

                $table->index(['approvable_type', 'approvable_id'], 'approvals_approvable_index');
                $table->index(['approvable_type', 'approvable_unique_key', 'created_unique_key'], 'approvals_approvable_unique_key_index');
                $table->index(['approval_task_id', 'order_number'], 'approvals_order_index');
            }
        );

        Schema::create(
            'approval_steps',
            function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('approval_task_id');
                $table->unsignedInteger('order_number');
                $table->morphs('approver', 'approval_steps_approver_index'); // user / role
                $table->string('user_type')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('status')->default(Enums\ApprovalStatus::PENDING->value);
                $table->string('comment')->default('');
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->index(['user_type', 'user_id'], 'approval_steps_user_index');
                $table->index(['approval_task_id', 'order_number'], 'approval_steps_order_index');
            }
        );
    }

    public function down()
    {
        Schema::dropIfExists('approval_steps');

        Schema::dropIfExists('approvals');

        Schema::dropIfExists('approval_tasks');

        Schema::dropIfExists('approval_flow_steps');

        Schema::dropIfExists('approval_flows');
    }
};
