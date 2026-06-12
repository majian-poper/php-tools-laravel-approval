<?php

/**
 * 测试目的: 覆盖 src/Resolvers 下 IpAddress/Url/UserAgent/User 四个 resolver 的静态元数据与 resolve() 行为
 * 覆盖范围: 各 resolver 的 name/type/attributeCast 元数据、HTTP 请求来源的解析、缺省 fallback、UserResolver 的 guard 失败/成功分支
 */

use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Request;
use PHPTools\Approval\Exceptions\UnauthorizedException;
use PHPTools\Approval\Resolvers\IpAddressResolver;
use PHPTools\Approval\Resolvers\UrlResolver;
use PHPTools\Approval\Resolvers\UserAgentResolver;
use PHPTools\Approval\Resolvers\UserResolver;

use function Pest\Laravel\actingAs;

// 验证: IpAddressResolver 元数据为 ip_address/ipAddress/string, 供 task 列写入参考
it('exposes static metadata for ip address resolver', function (): void {
    expect(IpAddressResolver::name())->toBe('ip_address')
        ->and(IpAddressResolver::type())->toBe('ipAddress')
        ->and(IpAddressResolver::attributeCast())->toBe('string');
});

// 验证: UrlResolver 元数据为 url/string/string
it('exposes static metadata for url resolver', function (): void {
    expect(UrlResolver::name())->toBe('url')
        ->and(UrlResolver::type())->toBe('string')
        ->and(UrlResolver::attributeCast())->toBe('string');
});

// 验证: UserAgentResolver 元数据为 user_agent/string/string
it('exposes static metadata for user agent resolver', function (): void {
    expect(UserAgentResolver::name())->toBe('user_agent')
        ->and(UserAgentResolver::type())->toBe('string')
        ->and(UserAgentResolver::attributeCast())->toBe('string');
});

// 验证: 注入 REMOTE_ADDR 时 IpAddressResolver 返回该值
it('resolves ip address from request', function (): void {
    request()->server->set('REMOTE_ADDR', '10.0.0.42');

    expect(IpAddressResolver::resolve())->toBe('10.0.0.42');
});

// 验证: 缺失 REMOTE_ADDR 时回退到 127.0.0.1, 不抛错
it('falls back to 127.0.0.1 when no remote address', function (): void {
    // No REMOTE_ADDR set; resolve() should fall back.
    expect(IpAddressResolver::resolve())->toBe('127.0.0.1');
});

// 验证: UserAgent header 直接读取
it('resolves user agent header', function (): void {
    request()->headers->set('User-Agent', 'PHPUnit/Test');

    expect(UserAgentResolver::resolve())->toBe('PHPUnit/Test');
});

// 验证: User-Agent 缺失时返回字面量 "Unknown User Agent"
it('falls back to unknown user agent when missing', function (): void {
    // Remove header to exercise the default fallback.
    request()->headers->remove('User-Agent');

    expect(UserAgentResolver::resolve())->toBe('Unknown User Agent');
});

// 验证: 非 console 上下文且 swap 进了真实 HTTP Request 时, UrlResolver 输出完整 URL (含 query)
it('resolves full url from request when running in http', function (): void {
    App::shouldReceive('runningInConsole')->andReturn(false);

    $httpRequest = Illuminate\Http\Request::create('https://example.test/admin/users?page=2', 'GET');
    Request::swap($httpRequest);

    expect(UrlResolver::resolve())->toBe('https://example.test/admin/users?page=2');
});

// 验证: 没有任何 guard 认证时 UserResolver 抛 UnauthorizedException 而非返回 null
it('throws when no guard has an authenticated user', function (): void {
    expect(static fn(): mixed => UserResolver::resolve())->toThrow(UnauthorizedException::class);
});

// 验证: actingAs(web guard) 后 UserResolver 返回同一个 User 实例
it('returns the authenticated user from the configured guard', function (): void {
    /** @var \App\Models\User $user */
    $user = User::factory()->create();

    actingAs($user, 'web');

    /** @var \App\Models\User $resolvedUser */
    $resolvedUser = UserResolver::resolve();

    expect($resolvedUser->is($user))->toBeTrue();
});
