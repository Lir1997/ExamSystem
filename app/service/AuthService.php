<?php

declare(strict_types=1);

namespace app\service;

use app\model\AdminAccessToken;
use app\model\AdminUser;

class AuthService
{
    public const TOKEN_TTL_SECONDS = 7200;
    public const TOKEN_REFRESH_THRESHOLD_SECONDS = 1800;

    public function attempt(string $username, string $password): ?array
    {
        /** @var AdminUser|null $admin */
        $admin = AdminUser::where('username', $username)->find();

        if ($admin === null || (int) $admin->status !== 1) {
            return null;
        }

        if (!password_verify($password, (string) $admin->password)) {
            return null;
        }

        $issuedAt = time();
        $plainToken = hash('sha256', $username . '|' . $issuedAt . '|' . bin2hex(random_bytes(16)));
        $tokenHash = hash('sha256', $plainToken);

        $admin->last_login_at = date('Y-m-d H:i:s', $issuedAt);
        $admin->last_login_ip = request()->ip();
        $admin->save();

        AdminAccessToken::create([
            'admin_user_id' => (int) $admin->id,
            'token' => $tokenHash,
            'expires_at' => date('Y-m-d H:i:s', $issuedAt + self::TOKEN_TTL_SECONDS),
            'last_used_at' => date('Y-m-d H:i:s', $issuedAt),
            'last_used_ip' => request()->ip(),
        ]);

        $rbacService = app(RbacService::class);

        return [
            'token' => $plainToken,
            'user' => [
                'id' => (int) $admin->id,
                'username' => (string) $admin->username,
                'name' => (string) $admin->name,
                'role' => (string) $admin->role_code,
            ],
            'role' => $rbacService->getRole($admin),
            'permissions' => $rbacService->getPermissions($admin),
            'menus' => $rbacService->getMenus($admin),
            'expires_in' => self::TOKEN_TTL_SECONDS,
            'issued_at' => $issuedAt,
        ];
    }

    public function profile(AdminUser $admin): array
    {
        $rbacService = app(RbacService::class);

        return [
            'id' => (int) $admin->id,
            'username' => (string) $admin->username,
            'name' => (string) $admin->name,
            'role' => (string) $admin->role_code,
            'role_meta' => $rbacService->getRole($admin),
            'permissions' => $rbacService->getPermissions($admin),
            'menus' => $rbacService->getMenus($admin),
        ];
    }
}
