<?php

declare(strict_types=1);

namespace app\middleware;

use app\service\AuditCenterService;
use Closure;
use think\Request;
use think\Response;

class AdminAuditMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $response = $next($request);

        try {
            app(AuditCenterService::class)->recordAdminRequest($request, $response, $startedAt);
        } catch (\Throwable) {
        }

        return $response;
    }
}
