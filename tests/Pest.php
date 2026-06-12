<?php

use App\Models\Article;
use App\Models\User;
use PHPTools\Approval\Enums\ApprovalFlowType;
use PHPTools\Approval\Facades\ApprovalFacade;
use PHPTools\Approval\Models\ApprovalTask;
use PHPTools\Approval\SimpleFlow;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->beforeEach(fn() => config()->set('approval.enabled_in_console', true))
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function buildTaskFor(User $user, iterable $approvers, ApprovalFlowType $flowType = ApprovalFlowType::EVERY): ApprovalTask
{
    $article = Article::newModel();

    $flow = new SimpleFlow(
        title: 'Approve article',
        description: 'Review please',
        type: $flowType,
        expiresAt: new DateTimeImmutable('+1 day'),
        approvers: $approvers,
    );

    return ApprovalFacade::createTaskFor($article, $flow, $user);
}
