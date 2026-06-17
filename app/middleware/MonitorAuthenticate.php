<?php

declare(strict_types=1);

namespace app\middleware;

use app\service\MonitorAuthService;
use Closure;
use think\Request;
use think\Response;

class MonitorAuthenticate
{
    protected const TOUCH_WRITE_INTERVAL_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $authorization = (string) $request->header('authorization', '');

        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return api_error('未登录或监考访问令牌缺失', 401);
        }

        $plainToken = trim($matches[1]);
        if ($plainToken === '') {
            return api_error('监考访问令牌无效', 401);
        }

        $exam = app(MonitorAuthService::class)->touchAccessToken($plainToken, $request);
        if ($exam === null) {
            return api_error('监考访问令牌不存在或已失效', 401);
        }

        $request->monitorExam = $exam;

        return $next($request);
    }
}
