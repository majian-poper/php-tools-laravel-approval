<?php

return [
    'enabled' => env('APPROVAL_ENABLED', true),

    'enabled_in_console' => env('APPROVAL_ENABLED_IN_CONSOLE', false),

    'user' => [
        'guards' => [
            'web',
            'api',
        ],
        'resolver' => \EnaTools\Approval\Resolvers\UserResolver::class,
    ],

    'implementations' => [
        'approval_flow' => \EnaTools\Approval\Models\ApprovalFlow::class,
        'approval_flow_step' => \EnaTools\Approval\Models\ApprovalFlowStep::class,
        'approval_task' => \EnaTools\Approval\Models\ApprovalTask::class,
        'approval' => \EnaTools\Approval\Models\Approval::class,
        'approval_step' => \EnaTools\Approval\Models\ApprovalStep::class,
    ],

    'column_resolvers' => [
        \EnaTools\Approval\Resolvers\IpAddressResolver::class,
        \EnaTools\Approval\Resolvers\UserAgentResolver::class,
        \EnaTools\Approval\Resolvers\UrlResolver::class,
    ],

    'chunk_size' => env('APPROVAL_CHUNK_SIZE', 100),

    'default_flow_type' => \EnaTools\Approval\Enums\ApprovalFlowType::EVERY,

    'default_expiration' => env('APPROVAL_DEFAULT_EXPIRATION', 7 * 24 * 60 * 60), // seconds

    'default_approver_resolver' => [],
];
