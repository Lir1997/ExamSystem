<?php

declare(strict_types=1);

namespace app\controller\monitor;

use app\controller\BaseApiController;
use app\service\MonitorAuthService;
use think\Response;

class AuthController extends BaseApiController
{
    public function login(MonitorAuthService $monitorAuthService): Response
    {
        $payload = $this->payload();

        $examCode = strtoupper(trim((string) ($payload['exam_code'] ?? '')));
        $password = trim((string) ($payload['password'] ?? ''));

        if ($examCode === '') {
            return $this->error('请输入考试代码', 422);
        }

        if ($password === '') {
            return $this->error('请输入监考密码', 422);
        }

        $result = $monitorAuthService->loginByExamCode($examCode, $password, $this->request);

        if ($result === null) {
            return $this->error('考试代码、监考地址或密码错误', 401);
        }

        return $this->success($result, '登录成功');
    }

    public function bridge(MonitorAuthService $monitorAuthService): Response
    {
        $bridgeToken = trim((string) $this->request->get('bridge', ''));
        if ($bridgeToken === '') {
            return $this->error('桥接令牌缺失', 422);
        }

        $result = $monitorAuthService->consumeBridgeToken($bridgeToken, $this->request);
        if ($result === null) {
            return $this->error('桥接令牌无效或已过期', 401);
        }

        return $this->success($result, '登录成功');
    }

    public function profile(MonitorAuthService $monitorAuthService): Response
    {
        /** @var \app\model\Exam|null $exam */
        $exam = $this->request->monitorExam ?? null;
        if ($exam === null) {
            return $this->error('未获取到当前监考考试', 401);
        }

        return $this->success($monitorAuthService->examOverview($exam));
    }
}
