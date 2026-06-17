<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\model\AdminUser;
use app\model\Exam;
use app\service\MonitorAuthService;
use app\service\RbacService;
use app\trait\AdminAuthorization;
use think\Response;

class MonitorController extends BaseApiController
{
    use AdminAuthorization;

    public function credentials(int $id, MonitorAuthService $monitorAuthService): Response
    {
        $unauthorized = $this->requirePermission('exam.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $exam = $this->loadAuthorizedExam($id);
        if ($exam === null) {
            return $this->error('考试不存在或无权访问', 404);
        }

        return $this->success($monitorAuthService->credentialsPayload($exam, $this->request));
    }

    public function regenerateCredentials(int $id, MonitorAuthService $monitorAuthService): Response
    {
        $unauthorized = $this->requirePermission('exam.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $exam = $this->loadAuthorizedExam($id);
        if ($exam === null) {
            return $this->error('考试不存在或无权访问', 404);
        }

        $monitorAuthService->regenerateCredentials($exam);

        return $this->success($monitorAuthService->credentialsPayload($exam, $this->request));
    }

    public function bridge(int $id, MonitorAuthService $monitorAuthService): Response
    {
        $unauthorized = $this->requirePermission('exam.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $exam = $this->loadAuthorizedExam($id);
        if ($exam === null) {
            return $this->error('考试不存在或无权访问', 404);
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        return $this->success([
            'url' => $monitorAuthService->createBridgeUrl($exam, $admin, $this->request),
        ]);
    }

    protected function loadAuthorizedExam(int $id): ?Exam
    {
        if ($id <= 0) {
            return null;
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return null;
        }

        /** @var Exam|null $exam */
        $exam = Exam::find($id);
        if ($exam === null) {
            return null;
        }

        return app(RbacService::class)->hasScopeAccess($admin, 'exam', $id) ? $exam : null;
    }
}
