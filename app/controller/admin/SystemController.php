<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\service\SessionMaintenanceService;
use app\service\SystemSettingService;
use app\trait\AdminAuthorization;
use think\Response;

class SystemController extends BaseApiController
{
    use AdminAuthorization;

    protected const EXAM_TIMEOUT_TASK_LIMIT = 500;

    public function settings(): Response
    {
        $unauthorized = $this->requirePermission('system.settings.view');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $systemSettingService = app(SystemSettingService::class);
        $settings = $systemSettingService->all();
        $taskToken = $systemSettingService->ensureExamTimeoutTaskToken();

        $settings['exam_timeout_task'] = [
            'path' => '/api/task/exam/finalize-timeouts',
            'url' => $this->buildExamTimeoutTaskUrl($taskToken),
            'token' => $taskToken,
            'suggested_interval' => '每 1 分钟访问一次',
            'suggested_limit' => self::EXAM_TIMEOUT_TASK_LIMIT,
        ];

        return $this->success($settings, '获取系统设置成功');
    }

    public function save(): Response
    {
        $unauthorized = $this->requirePermission('system.settings.view');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->payload();

        if (trim((string) ($payload['site_name'] ?? '')) === '') {
            return $this->error('站点名称不能为空', 422);
        }

        app(SystemSettingService::class)->save($payload);

        return $this->success([], '保存系统设置成功');
    }

    public function clearSessions(SessionMaintenanceService $sessionMaintenanceService): Response
    {
        $unauthorized = $this->requirePermission('system.settings.view');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $result = $sessionMaintenanceService->clearAllTokens();

        return $this->success($result, '已清除全部登录态');
    }

    public function regenerateExamTimeoutTask(SystemSettingService $systemSettingService): Response
    {
        $unauthorized = $this->requirePermission('system.settings.view');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $token = $systemSettingService->regenerateExamTimeoutTaskToken();

        return $this->success([
            'path' => '/api/task/exam/finalize-timeouts',
            'url' => $this->buildExamTimeoutTaskUrl($token),
            'token' => $token,
            'suggested_interval' => '每 1 分钟访问一次',
            'suggested_limit' => self::EXAM_TIMEOUT_TASK_LIMIT,
        ], '超时自动收卷任务地址已重新生成');
    }

    protected function buildExamTimeoutTaskUrl(string $token): string
    {
        $scheme = $this->request->scheme();
        $host = trim((string) $this->request->host(true));

        if ($host === '') {
            $host = trim((string) $this->request->server('HTTP_HOST', ''));
        }

        if ($host === '') {
            throw new \RuntimeException('当前请求缺少有效域名，无法生成自动任务地址。');
        }

        return $scheme . '://' . $host . '/api/task/exam/finalize-timeouts?token=' . rawurlencode($token) . '&limit=' . self::EXAM_TIMEOUT_TASK_LIMIT;
    }
}
