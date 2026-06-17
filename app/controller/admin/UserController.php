<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\model\AdminUser;
use app\service\RbacService;
use app\trait\AdminAuthorization;
use think\facade\Db;
use think\Response;

class UserController extends BaseApiController
{
    use AdminAuthorization;

    public function index(): Response
    {
        $unauthorized = $this->requirePermission('user.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        [$page, $pageSize] = $this->paginationParams();
        $roleCode = trim((string) $this->request->get('role_code', ''));
        $keyword = trim((string) $this->request->get('keyword', ''));

        $query = AdminUser::order('id asc')
            ->field(['id', 'username', 'name', 'role_code', 'status', 'last_login_at', 'last_login_ip', 'created_at', 'updated_at']);

        if ($roleCode !== '') {
            $query->where('role_code', $roleCode);
        }

        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('username', 'like', $like)->whereOr('name', 'like', $like);
            });
        }

        $total = (clone $query)->count();
        $items = $query->page($page, $pageSize)->select()->toArray();

        $roleMap = [];
        foreach (app(RbacService::class)->roles() as $role) {
            $code = trim((string) ($role['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $roleMap[$code] = [
                'code' => $code,
                'name' => (string) ($role['name'] ?? $code),
            ];
        }

        $userIds = array_values(array_unique(array_filter(array_map(
            static fn (array $item): int => (int) ($item['id'] ?? 0),
            $items
        ))));
        $scopeMap = $this->scopeMap($userIds);

        foreach ($items as &$item) {
            $userId = (int) ($item['id'] ?? 0);
            $item['id'] = $userId;
            $item['status'] = (int) ($item['status'] ?? 0);
            $item['role_name'] = $roleMap[(string) ($item['role_code'] ?? '')]['name'] ?? (string) ($item['role_code'] ?? '');
            $item['scope_ids'] = $scopeMap[$userId] ?? [
                'paper' => [],
                'question' => [],
                'exam' => [],
            ];
            $item['paper_scope_ids'] = $item['scope_ids']['paper'];
        }
        unset($item);

        return $this->success($this->paginationData($items, $total, $page, $pageSize), '获取用户列表成功');
    }

    public function save(): Response
    {
        $unauthorized = $this->requirePermission('user.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->payload();
        $id = (int) ($payload['id'] ?? 0);
        $username = trim((string) ($payload['username'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $roleCode = strtolower(trim((string) ($payload['role_code'] ?? 'teacher')));
        $status = (int) ($payload['status'] ?? 1);
        $password = trim((string) ($payload['password'] ?? ''));

        if ($username === '' || $name === '' || $roleCode === '') {
            return $this->error('账号、姓名和角色不能为空', 422);
        }

        $roleExists = Db::name('admin_roles')->where('code', $roleCode)->count() > 0;
        if (!$roleExists) {
            return $this->error('所选角色不存在', 422);
        }

        /** @var AdminUser|null $user */
        $user = $id > 0 ? AdminUser::find($id) : new AdminUser();
        if ($user === null) {
            return $this->error('用户不存在', 404);
        }

        $duplicate = AdminUser::where('username', $username)->where('id', '<>', (int) $user->id)->find();
        if ($duplicate !== null) {
            return $this->error('用户名已存在', 422);
        }

        $previousRoleCode = strtolower(trim((string) ($user->role_code ?? '')));
        $user->username = $username;
        $user->name = $name;
        $user->role_code = $roleCode;
        $user->status = $status === 1 ? 1 : 0;

        $currentAdmin = $this->currentAdmin();
        if (
            $currentAdmin instanceof AdminUser
            && $id > 0
            && (int) $currentAdmin->id === $id
            && $user->status !== 1
        ) {
            return $this->error('不能停用当前登录账号', 422);
        }

        if ($roleCode === 'admin' && $user->status !== 1) {
            $adminCount = AdminUser::where('role_code', 'admin')
                ->where('status', 1)
                ->where('id', '<>', $id)
                ->count();
            if ($adminCount <= 0) {
                return $this->error('至少需要保留一个启用中的系统管理员', 422);
            }
        }

        if ((int) $user->id === 0 || $password !== '') {
            $user->password = password_hash($password !== '' ? $password : 'teacher123', PASSWORD_DEFAULT);
        }

        $user->save();

        if ($previousRoleCode !== '' && $previousRoleCode !== $roleCode) {
            Db::name('admin_data_scopes')->where('admin_user_id', (int) $user->id)->delete();
        }

        return $this->success(['id' => (int) $user->id], '保存用户成功');
    }

    public function delete(int $id): Response
    {
        $unauthorized = $this->requirePermission('user.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        if ($id <= 0) {
            return $this->error('用户 ID 无效', 422);
        }

        /** @var AdminUser|null $user */
        $user = AdminUser::find($id);
        if ($user === null) {
            return $this->error('用户不存在', 404);
        }

        $currentAdmin = $this->currentAdmin();
        if ($currentAdmin instanceof AdminUser && (int) $currentAdmin->id === $id) {
            return $this->error('不能删除当前登录账号', 422);
        }

        if ((string) $user->role_code === 'admin') {
            $adminCount = AdminUser::where('role_code', 'admin')->where('status', 1)->count();
            if ($adminCount <= 1) {
                return $this->error('至少需要保留一个启用中的系统管理员', 422);
            }
        }

        $references = $this->userDeleteReferences($id);
        if ($references !== []) {
            return $this->error('当前用户仍存在以下引用，无法删除：' . implode('；', $references), 422);
        }

        Db::transaction(function () use ($id): void {
            Db::name('admin_access_tokens')->where('admin_user_id', $id)->delete();
            Db::name('admin_data_scopes')->where('admin_user_id', $id)->delete();
            Db::name('admin_users')->where('id', $id)->delete();
        });

        return $this->success(['id' => $id], '删除用户成功');
    }

    public function roles(): Response
    {
        $unauthorized = $this->requirePermission('user.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        return $this->success([
            'items' => app(RbacService::class)->roles(),
        ], '获取角色列表成功');
    }

    public function resetPassword(): Response
    {
        $unauthorized = $this->requirePermission('user.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->payload();
        $userId = (int) ($payload['user_id'] ?? 0);
        $password = trim((string) ($payload['password'] ?? ''));

        if ($userId <= 0) {
            return $this->error('用户 ID 无效', 422);
        }

        /** @var AdminUser|null $user */
        $user = AdminUser::find($userId);
        if ($user === null) {
            return $this->error('用户不存在', 404);
        }

        $newPassword = $password !== '' ? $password : 'teacher123';
        $user->password = password_hash($newPassword, PASSWORD_DEFAULT);
        $user->save();

        return $this->success([
            'user_id' => $userId,
            'password' => $newPassword,
            'used_default_password' => $password === '',
        ], '重置密码成功');
    }

    public function assignScopes(): Response
    {
        $unauthorized = $this->requirePermission('user.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->payload();
        $userId = (int) ($payload['user_id'] ?? $payload['teacher_id'] ?? 0);
        $scopeType = strtolower(trim((string) ($payload['scope_type'] ?? '')));
        $scopeValues = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) ($payload['scope_values'] ?? [])
        ), static fn (string $value): bool => trim($value) !== '')));

        if ($userId <= 0 || $scopeType === '') {
            return $this->error('用户 ID 或范围类型无效', 422);
        }

        /** @var AdminUser|null $user */
        $user = AdminUser::find($userId);
        if ($user === null) {
            return $this->error('用户不存在', 404);
        }

        $rbacService = app(RbacService::class);
        if (!$rbacService->isValidScopeType($scopeType)) {
            return $this->error('当前范围类型不支持配置', 422);
        }

        if ($rbacService->isAdmin($user)) {
            return $this->error('系统管理员默认拥有全部数据权限，不支持单独配置范围', 422);
        }

        Db::transaction(function () use ($userId, $scopeType, $scopeValues): void {
            Db::name('admin_data_scopes')
                ->where('admin_user_id', $userId)
                ->where('scope_type', $scopeType)
                ->delete();

            foreach ($scopeValues as $scopeValue) {
                Db::name('admin_data_scopes')->insert([
                    'admin_user_id' => $userId,
                    'scope_type' => $scopeType,
                    'scope_value' => $scopeValue,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        });

        return $this->success([
            'user_id' => $userId,
            'scope_type' => $scopeType,
            'scope_values' => $scopeValues,
        ], '保存用户数据范围成功');
    }

    protected function scopeMap(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }

        $rows = Db::name('admin_data_scopes')
            ->whereIn('admin_user_id', $userIds)
            ->field(['admin_user_id', 'scope_type', 'scope_value'])
            ->order('admin_user_id asc,id asc')
            ->select()
            ->toArray();

        $scopeMap = [];
        foreach ($userIds as $userId) {
            $scopeMap[$userId] = [
                'paper' => [],
                'question' => [],
                'exam' => [],
            ];
        }

        foreach ($rows as $row) {
            $userId = (int) ($row['admin_user_id'] ?? 0);
            $scopeType = strtolower(trim((string) ($row['scope_type'] ?? '')));
            $scopeValue = trim((string) ($row['scope_value'] ?? ''));

            if ($userId <= 0 || $scopeValue === '' || !isset($scopeMap[$userId][$scopeType])) {
                continue;
            }

            if (!in_array($scopeValue, $scopeMap[$userId][$scopeType], true)) {
                $scopeMap[$userId][$scopeType][] = $scopeValue;
            }
        }

        return $scopeMap;
    }

    protected function userDeleteReferences(int $id): array
    {
        $references = [];

        $scopeRows = Db::name('admin_data_scopes')
            ->where('admin_user_id', $id)
            ->field(['scope_type', 'scope_value'])
            ->select()
            ->toArray();
        if ($scopeRows !== []) {
            $labels = [];
            foreach ($scopeRows as $row) {
                $scopeType = strtolower(trim((string) ($row['scope_type'] ?? '')));
                $scopeValue = trim((string) ($row['scope_value'] ?? ''));
                if ($scopeValue === '') {
                    continue;
                }

                $labels[] = match ($scopeType) {
                    'paper' => '试卷 ' . $scopeValue,
                    'question' => '试题 ' . $scopeValue,
                    'exam' => '考试 ' . $scopeValue,
                    default => $scopeType . ' ' . $scopeValue,
                };
            }

            $references[] = '数据范围配置 ' . count($scopeRows) . ' 条' . $this->referenceDetailSuffix('涉及资源', $labels);
        }

        $createdPaperCount = (int) Db::name('papers')->where('created_by', $id)->count();
        if ($createdPaperCount > 0) {
            $paperRows = Db::name('papers')
                ->where('created_by', $id)
                ->field(['id', 'title'])
                ->limit(5)
                ->select()
                ->toArray();

            $paperPreview = array_map(static function (array $row): string {
                $title = trim((string) ($row['title'] ?? ''));
                $paperId = (int) ($row['id'] ?? 0);
                return $title !== '' ? $title : '试卷 ID ' . $paperId;
            }, $paperRows);

            $references[] = '创建试卷 ' . $createdPaperCount . ' 份' . $this->referenceDetailSuffix('试卷', $paperPreview);
        }

        $createdQuestionCount = (int) Db::name('questions')->where('created_by', $id)->count();
        if ($createdQuestionCount > 0) {
            $questionRows = Db::name('questions')
                ->where('created_by', $id)
                ->field(['id', 'title'])
                ->limit(5)
                ->select()
                ->toArray();

            $questionPreview = array_map(static function (array $row): string {
                $title = trim((string) ($row['title'] ?? ''));
                $questionId = (int) ($row['id'] ?? 0);
                return $title !== '' ? $title : '试题 ID ' . $questionId;
            }, $questionRows);

            $references[] = '创建试题 ' . $createdQuestionCount . ' 道' . $this->referenceDetailSuffix('试题', $questionPreview);
        }

        $createdExamCount = (int) Db::name('exams')->where('created_by', $id)->count();
        if ($createdExamCount > 0) {
            $examRows = Db::name('exams')
                ->where('created_by', $id)
                ->field(['id', 'title'])
                ->limit(5)
                ->select()
                ->toArray();

            $examPreview = array_map(static function (array $row): string {
                $title = trim((string) ($row['title'] ?? ''));
                $examId = (int) ($row['id'] ?? 0);
                return $title !== '' ? $title : '考试 ID ' . $examId;
            }, $examRows);

            $references[] = '创建考试 ' . $createdExamCount . ' 场' . $this->referenceDetailSuffix('考试', $examPreview);
        }

        return $references;
    }
}
