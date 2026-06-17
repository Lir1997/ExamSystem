<?php

declare(strict_types=1);

namespace app\controller\task;

use app\controller\BaseApiController;
use app\service\ExamResultService;
use app\service\ExamSessionService;
use app\service\SystemSettingService;
use think\Response;

class ExamTaskController extends BaseApiController
{
    public function finalizeTimeouts(
        ExamSessionService $examSessionService,
        ExamResultService $examResultService,
        SystemSettingService $systemSettingService
    ): Response {
        $token = trim((string) $this->request->get('token', ''));
        $expectedToken = $systemSettingService->ensureExamTimeoutTaskToken();

        if ($token === '' || !hash_equals($expectedToken, $token)) {
            return $this->error('自动任务令牌无效。', 403);
        }

        $limit = (int) $this->request->get('limit', 500);
        $result = $examSessionService->finalizeTimedOutSessions($limit, $examResultService);

        return $this->success($result, '超时自动收卷任务执行完成。');
    }
}
