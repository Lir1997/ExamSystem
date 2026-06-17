<?php

declare(strict_types=1);

namespace app\middleware;

use app\model\AdminAccessToken;
use app\model\AdminUser;
use app\service\AuthService;
use Closure;
use think\Request;
use think\Response;

class AdminAuthenticate
{
    protected const TOUCH_WRITE_INTERVAL_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $authorization = (string) $request->header('authorization', '');

        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return api_error('未登录或访问令牌缺失', 401);
        }

        $plainToken = trim($matches[1]);
        if ($plainToken === '') {
            return api_error('访问令牌无效', 401);
        }

        $tokenHash = hash('sha256', $plainToken);

        /** @var AdminAccessToken|null $tokenRecord */
        $tokenRecord = AdminAccessToken::where('token', $tokenHash)->find();
        if ($tokenRecord === null) {
            return api_error('访问令牌不存在或已失效', 401);
        }

        if ($tokenRecord->revoked_at !== null) {
            return api_error('访问令牌已失效', 401);
        }

        if ($tokenRecord->expires_at !== null && strtotime((string) $tokenRecord->expires_at) < time()) {
            return api_error('访问令牌已过期', 401);
        }

        /** @var AdminUser|null $admin */
        $admin = AdminUser::where('id', (int) $tokenRecord->admin_user_id)->find();
        if ($admin === null || (int) $admin->status !== 1) {
            return api_error('账号不存在或已停用', 401);
        }

        $now = time();
        $expiresAt = $tokenRecord->expires_at ? strtotime((string) $tokenRecord->expires_at) : null;
        $lastUsedAt = $tokenRecord->last_used_at ? strtotime((string) $tokenRecord->last_used_at) : null;
        $shouldSave = false;

        if ($expiresAt !== null && ($expiresAt - $now) <= AuthService::TOKEN_REFRESH_THRESHOLD_SECONDS) {
            $tokenRecord->expires_at = date('Y-m-d H:i:s', $now + AuthService::TOKEN_TTL_SECONDS);
            $shouldSave = true;
        }

        if ($lastUsedAt === null || ($now - $lastUsedAt) >= self::TOUCH_WRITE_INTERVAL_SECONDS) {
            $tokenRecord->last_used_at = date('Y-m-d H:i:s', $now);
            $tokenRecord->last_used_ip = $request->ip();
            $shouldSave = true;
        }

        if ($shouldSave) {
            $tokenRecord->save();
        }

        $request->adminUser = $admin;

        return $next($request);
    }
}
