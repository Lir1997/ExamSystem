<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\service\AuditCenterService;
use app\trait\AdminAuthorization;
use think\Response;

class AuditController extends BaseApiController
{
    use AdminAuthorization;

    public function index(AuditCenterService $auditCenterService): Response
    {
        $unauthorized = $this->requirePermission('audit.view');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        [$page, $pageSize] = $this->paginationParams();
        $filters = [
            'module' => trim((string) $this->request->get('module', 'admin')),
            'keyword' => trim((string) $this->request->get('keyword', '')),
            'admin_user_id' => (int) $this->request->get('admin_user_id', 0),
            'exam_id' => (int) $this->request->get('exam_id', 0),
            'student_id' => (int) $this->request->get('student_id', 0),
            'source' => trim((string) $this->request->get('source', '')),
            'log_type' => trim((string) $this->request->get('log_type', '')),
            'severity' => trim((string) $this->request->get('severity', '')),
            'method' => trim((string) $this->request->get('method', '')),
            'response_code' => trim((string) $this->request->get('response_code', '')),
            'date_from' => trim((string) $this->request->get('date_from', '')),
            'date_to' => trim((string) $this->request->get('date_to', '')),
        ];

        return $this->success($auditCenterService->listLogs($filters, $page, $pageSize), '获取审计日志成功');
    }

    public function summary(AuditCenterService $auditCenterService): Response
    {
        $unauthorized = $this->requirePermission('audit.view');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $filters = [
            'module' => trim((string) $this->request->get('module', 'admin')),
            'keyword' => trim((string) $this->request->get('keyword', '')),
            'admin_user_id' => (int) $this->request->get('admin_user_id', 0),
            'exam_id' => (int) $this->request->get('exam_id', 0),
            'student_id' => (int) $this->request->get('student_id', 0),
            'source' => trim((string) $this->request->get('source', '')),
            'log_type' => trim((string) $this->request->get('log_type', '')),
            'severity' => trim((string) $this->request->get('severity', '')),
            'method' => trim((string) $this->request->get('method', '')),
            'response_code' => trim((string) $this->request->get('response_code', '')),
            'date_from' => trim((string) $this->request->get('date_from', '')),
            'date_to' => trim((string) $this->request->get('date_to', '')),
        ];

        return $this->success($auditCenterService->summary($filters), '获取审计概览成功');
    }
}
