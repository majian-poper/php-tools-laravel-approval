<?php

return [
    'enabled' => env('APPROVAL_ENABLED', true),

    'enabled_in_console' => env('APPROVAL_ENABLED_IN_CONSOLE', false),

    'user' => [
        'guards' => [
            'web',
            'api',
        ],
        'resolver' => \PHPTools\Approval\Resolvers\UserResolver::class,
    ],

    'implementations' => [
        'approval_flow' => \PHPTools\Approval\Models\ApprovalFlow::class,
        'approval_flow_step' => \PHPTools\Approval\Models\ApprovalFlowStep::class,
        'approval_task' => \PHPTools\Approval\Models\ApprovalTask::class,
        'approval' => \PHPTools\Approval\Models\Approval::class,
        'approval_step' => \PHPTools\Approval\Models\ApprovalStep::class,
    ],

    'column_resolvers' => [
        \PHPTools\Approval\Resolvers\IpAddressResolver::class,
        \PHPTools\Approval\Resolvers\UserAgentResolver::class,
        \PHPTools\Approval\Resolvers\UrlResolver::class,
    ],

    'chunk_size' => env('APPROVAL_CHUNK_SIZE', 100),

    'default_flow_type' => \PHPTools\Approval\Enums\ApprovalFlowType::EVERY,

    'default_expiration' => env('APPROVAL_DEFAULT_EXPIRATION', 7 * 24 * 60 * 60), // seconds

    'default_approver_resolver' => [],
];
