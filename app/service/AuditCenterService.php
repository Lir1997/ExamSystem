<?php

declare(strict_types=1);

namespace app\service;

use app\model\AdminAuditLog;
use app\model\AdminUser;
use think\Request;
use think\Response;
use think\facade\Db;

class AuditCenterService
{
    public const MODULE_ADMIN = 'admin';
    public const MODULE_MONITOR = 'monitor';
    public const MODULE_EXAM = 'exam';

    protected const ADMIN_WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    protected static array $adminQueue = [];
    protected static bool $shutdownRegistered = false;

    public function recordAdminRequest(Request $request, Response $response, float $startedAt): void
    {
        $method = strtoupper((string) $request->method());
        if (!in_array($method, self::ADMIN_WRITE_METHODS, true)) {
            return;
        }

        $path = (string) $request->pathinfo();
        if ($path === '' || !str_starts_with($path, 'api/admin/')) {
            return;
        }

        $responseData = $response->getData();
        $payload = is_array($responseData) ? $responseData : [];
        $resultCode = (int) ($payload['code'] ?? 0);
        $message = trim((string) ($payload['message'] ?? ''));

        $admin = $request->adminUser ?? null;
        $adminUserId = $admin instanceof AdminUser ? (int) $admin->id : null;
        $adminName = $admin instanceof AdminUser ? (string) $admin->name : null;
        $roleCode = $admin instanceof AdminUser ? (string) $admin->role_code : null;

        self::$adminQueue[] = [
            'admin_user_id' => $adminUserId,
            'admin_name' => $adminName,
            'role_code' => $roleCode,
            'module' => self::MODULE_ADMIN,
            'action_key' => $this->actionKey($method, $path),
            'request_method' => $method,
            'request_path' => $path,
            'request_ip' => $request->ip(),
            'request_params_json' => $this->encodePayload($request->param()),
            'response_code' => $resultCode,
            'response_message' => $message,
            'duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $this->registerShutdown();
    }

    public function flushAdminQueue(): void
    {
        if (self::$adminQueue === []) {
            return;
        }

        $rows = self::$adminQueue;
        self::$adminQueue = [];
        AdminAuditLog::insertAll($rows);
    }

    public function listLogs(array $filters, int $page, int $pageSize): array
    {
        $module = (string) ($filters['module'] ?? self::MODULE_ADMIN);

        return match ($module) {
            self::MODULE_MONITOR, self::MODULE_EXAM => $this->paginatedExamMonitorLogs($filters, $page, $pageSize),
            default => $this->paginatedAdminLogs($filters, $page, $pageSize),
        };
    }

    public function summary(array $filters): array
    {
        $module = (string) ($filters['module'] ?? self::MODULE_ADMIN);

        return match ($module) {
            self::MODULE_MONITOR, self::MODULE_EXAM => $this->examMonitorSummary($filters),
            default => $this->adminSummary($filters),
        };
    }

    protected function paginatedAdminLogs(array $filters, int $page, int $pageSize): array
    {
        $query = Db::name('admin_audit_logs')
            ->alias('l')
            ->leftJoin('admin_users u', 'u.id = l.admin_user_id')
            ->field([
                'l.id',
                'l.admin_user_id',
                'l.admin_name',
                'l.role_code',
                'l.module',
                'l.action_key',
                'l.request_method',
                'l.request_path',
                'l.request_ip',
                'l.request_params_json',
                'l.response_code',
                'l.response_message',
                'l.duration_ms',
                'l.created_at',
                'u.username' => 'admin_username',
            ]);
        $this->applyAdminFilters($query, $filters);

        $total = (clone $query)->count();
        $rows = $query->page($page, $pageSize)->order('l.id desc')->select()->toArray();

        return [
            'items' => array_map([$this, 'normalizeAdminLog'], $rows),
            'pagination' => $this->pagination($total, $page, $pageSize),
            'summary' => $this->adminSummary($filters),
        ];
    }

    protected function paginatedExamMonitorLogs(array $filters, int $page, int $pageSize): array
    {
        $query = Db::name('exam_monitor_logs')
            ->alias('l')
            ->leftJoin('exams e', 'e.id = l.exam_id')
            ->leftJoin('students s', 's.id = l.student_id')
            ->field([
                'l.id',
                'l.exam_id',
                'l.session_id',
                'l.student_id',
                'l.source',
                'l.log_type',
                'l.severity',
                'l.action_type',
                'l.action_value',
                'l.note',
                'l.payload_json',
                'l.created_at',
                'e.title' => 'exam_title',
                's.username' => 'student_username',
                's.student_no' => 'student_no',
                's.name' => 'student_name',
            ]);
        $this->applyExamMonitorFilters($query, $filters);

        $total = (clone $query)->count();
        $rows = $query->page($page, $pageSize)->order('l.id desc')->select()->toArray();

        return [
            'items' => array_map([$this, 'normalizeExamMonitorLog'], $rows),
            'pagination' => $this->pagination($total, $page, $pageSize),
            'summary' => $this->examMonitorSummary($filters),
        ];
    }

    protected function adminSummary(array $filters): array
    {
        $query = Db::name('admin_audit_logs')
            ->alias('l')
            ->leftJoin('admin_users u', 'u.id = l.admin_user_id');
        $this->applyAdminFilters($query, $filters);
        $total = (int) $query->count();
        $failed = (int) (clone $query)->where('l.response_code', '<>', 0)->count();

        return [
            'module' => self::MODULE_ADMIN,
            'total' => $total,
            'success' => max($total - $failed, 0),
            'failed' => $failed,
        ];
    }

    protected function examMonitorSummary(array $filters): array
    {
        $query = Db::name('exam_monitor_logs')
            ->alias('l')
            ->leftJoin('exams e', 'e.id = l.exam_id')
            ->leftJoin('students s', 's.id = l.student_id');
        $this->applyExamMonitorFilters($query, $filters);
        $total = (int) $query->count();
        $warning = (int) (clone $query)->where('l.severity', 'warning')->count();
        $danger = (int) (clone $query)->where('l.severity', 'danger')->count();

        return [
            'module' => (string) ($filters['module'] ?? self::MODULE_MONITOR),
            'total' => $total,
            'warning' => $warning,
            'danger' => $danger,
            'info' => max($total - $warning - $danger, 0),
        ];
    }

    protected function applyAdminFilters($query, array $filters): void
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $adminUserId = (int) ($filters['admin_user_id'] ?? 0);
        $method = strtoupper(trim((string) ($filters['method'] ?? '')));
        $responseCode = trim((string) ($filters['response_code'] ?? ''));
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));

        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($builder) use ($like): void {
                $builder
                    ->where('l.action_key', 'like', $like)
                    ->whereOr('l.request_path', 'like', $like)
                    ->whereOr('l.response_message', 'like', $like)
                    ->whereOr('l.admin_name', 'like', $like)
                    ->whereOr('u.username', 'like', $like);
            });
        }

        if ($adminUserId > 0) {
            $query->where('l.admin_user_id', $adminUserId);
        }
        if ($method !== '') {
            $query->where('l.request_method', $method);
        }
        if ($responseCode !== '') {
            $query->where('l.response_code', (int) $responseCode);
        }
        if ($dateFrom !== '') {
            $query->where('l.created_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->where('l.created_at', '<=', $dateTo);
        }
    }

    protected function applyExamMonitorFilters($query, array $filters): void
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $examId = (int) ($filters['exam_id'] ?? 0);
        $studentId = (int) ($filters['student_id'] ?? 0);
        $source = trim((string) ($filters['source'] ?? ''));
        $logType = trim((string) ($filters['log_type'] ?? ''));
        $severity = trim((string) ($filters['severity'] ?? ''));
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        $module = (string) ($filters['module'] ?? self::MODULE_MONITOR);
        $defaultSources = $module === self::MODULE_EXAM ? ['exam_client', 'system'] : ['monitor'];

        $query->whereIn('l.source', $defaultSources);
        $query->where('l.log_type', '<>', 'answer_saved');

        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($builder) use ($like): void {
                $builder
                    ->where('l.log_type', 'like', $like)
                    ->whereOr('l.note', 'like', $like)
                    ->whereOr('e.title', 'like', $like)
                    ->whereOr('s.username', 'like', $like)
                    ->whereOr('s.student_no', 'like', $like)
                    ->whereOr('s.name', 'like', $like);
            });
        }

        if ($examId > 0) {
            $query->where('l.exam_id', $examId);
        }
        if ($studentId > 0) {
            $query->where('l.student_id', $studentId);
        }
        if ($source !== '') {
            $query->where('l.source', $source);
        }
        if ($logType !== '') {
            $query->where('l.log_type', $logType);
        }
        if ($severity !== '') {
            $query->where('l.severity', $severity);
        }
        if ($dateFrom !== '') {
            $query->where('l.created_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->where('l.created_at', '<=', $dateTo);
        }
    }

    protected function normalizeAdminLog(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'admin_user_id' => isset($row['admin_user_id']) ? (int) $row['admin_user_id'] : null,
            'admin_name' => $this->nullableString($row['admin_name'] ?? null),
            'admin_username' => $this->nullableString($row['admin_username'] ?? null),
            'role_code' => $this->nullableString($row['role_code'] ?? null),
            'module' => (string) ($row['module'] ?? self::MODULE_ADMIN),
            'action_key' => (string) ($row['action_key'] ?? ''),
            'request_method' => (string) ($row['request_method'] ?? ''),
            'request_path' => (string) ($row['request_path'] ?? ''),
            'request_ip' => $this->nullableString($row['request_ip'] ?? null),
            'request_params' => $this->decodePayload($row['request_params_json'] ?? null),
            'response_code' => (int) ($row['response_code'] ?? 0),
            'response_message' => $this->nullableString($row['response_message'] ?? null),
            'duration_ms' => (int) ($row['duration_ms'] ?? 0),
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        ];
    }

    protected function normalizeExamMonitorLog(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'exam_id' => (int) ($row['exam_id'] ?? 0),
            'session_id' => isset($row['session_id']) ? (int) $row['session_id'] : null,
            'student_id' => isset($row['student_id']) ? (int) $row['student_id'] : null,
            'exam_title' => $this->nullableString($row['exam_title'] ?? null),
            'student_name' => $this->nullableString($row['student_name'] ?? null),
            'student_username' => $this->nullableString($row['student_username'] ?? null),
            'student_no' => $this->nullableString($row['student_no'] ?? null),
            'source' => (string) ($row['source'] ?? ''),
            'source_label' => $this->sourceLabel((string) ($row['source'] ?? '')),
            'log_type' => (string) ($row['log_type'] ?? ''),
            'log_type_label' => $this->logTypeLabel((string) ($row['log_type'] ?? '')),
            'severity' => (string) ($row['severity'] ?? 'info'),
            'action_key' => (string) ($row['log_type'] ?? ''),
            'action_type' => $this->nullableString($row['action_type'] ?? null),
            'action_value' => (int) ($row['action_value'] ?? 0),
            'note' => $this->nullableString($row['note'] ?? null),
            'payload' => $this->decodePayload($row['payload_json'] ?? null),
            'response_message' => $this->nullableString($row['note'] ?? null),
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        ];
    }

    protected function sourceLabel(string $value): string
    {
        return match ($value) {
            'exam_client' => '考试端',
            'monitor' => '监考端',
            'system' => '系统',
            default => $value,
        };
    }

    protected function logTypeLabel(string $value): string
    {
        return match ($value) {
            'visibility_hidden' => '切出页面',
            'window_blur' => '窗口失焦',
            'fullscreen_exit' => '退出全屏',
            'focus_threshold_action' => '达到阈值处理',
            'monitor_warning' => '监考提醒',
            'monitor_deduct_score' => '监考扣分',
            'monitor_zero_score' => '监考记零分',
            'monitor_force_submit' => '监考强制收卷',
            'extend_time' => '临时加时',
            'force_submit' => '强制收卷',
            'bulk_extend_time' => '批量加时',
            'bulk_force_submit' => '批量收卷',
            'exam_login' => '考试端登录',
            'exam_submit' => '考试交卷',
            'operation_result_upload' => '上传操作题结果',
            default => $value,
        };
    }

    protected function actionKey(string $method, string $path): string
    {
        $normalizedPath = preg_replace('#/\d+(?=/|$)#', '/:id', $path) ?: $path;

        return strtolower($method . ' ' . $normalizedPath);
    }

    protected function encodePayload(mixed $payload): string
    {
        $encoded = json_encode($this->sanitizeValue($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '{}' : $encoded;
    }

    protected function sanitizeValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 2) {
            return is_array($value) ? ['count' => count($value)] : (is_scalar($value) ? $this->shortValue($value) : null);
        }

        if (is_array($value)) {
            $result = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count++ >= 30) {
                    $result['__truncated__'] = true;
                    break;
                }

                $field = (string) $key;
                if (preg_match('/password|token|credential|secret|cipher|bridge/i', $field)) {
                    $result[$field] = '***';
                    continue;
                }

                $result[$field] = $this->sanitizeValue($item, $depth + 1);
            }

            return $result;
        }

        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $this->shortValue($value);
        }

        return null;
    }

    protected function shortValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return function_exists('mb_substr') ? mb_substr($value, 0, 200) : substr($value, 0, 200);
    }

    protected function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    protected function decodePayload(mixed $json): mixed
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function pagination(int $total, int $page, int $pageSize): array
    {
        return [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
        ];
    }

    protected function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        register_shutdown_function(function (): void {
            try {
                $this->flushAdminQueue();
            } catch (\Throwable) {
            }
        });
    }
}
