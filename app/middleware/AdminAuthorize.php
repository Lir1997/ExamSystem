<?php

declare(strict_types=1);

namespace app\middleware;

use app\model\AdminUser;
use app\service\RbacService;
use Closure;
use think\Request;
use think\Response;

class AdminAuthorize
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        /** @var AdminUser|null $admin */
        $admin = $request->adminUser ?? null;
        if ($admin === null) {
            return api_error('未登录或用户上下文缺失', 401);
        }

        $permissions = app(RbacService::class)->getPermissions($admin);
        if (!in_array($permission, $permissions, true)) {
            return api_error('无权限访问当前资源', 403);
        }

        $request->adminPermissions = $permissions;

        return $next($request);
    }
}
