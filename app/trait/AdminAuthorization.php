<?php

declare(strict_types=1);

namespace app\trait;

use app\model\AdminUser;
use app\service\RbacService;
use think\Response;

trait AdminAuthorization
{
    protected function currentAdmin(): ?AdminUser
    {
        /** @var AdminUser|null $admin */
        $admin = $this->request->adminUser ?? null;

        return $admin;
    }

    protected function requirePermission(string $permission): ?Response
    {
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        $permissions = app(RbacService::class)->getPermissions($admin);
        if (!in_array($permission, $permissions, true)) {
            return $this->error('无权限访问当前资源', 403);
        }

        return null;
    }
}
