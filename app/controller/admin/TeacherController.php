<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\model\AdminUser;
use app\trait\AdminAuthorization;
use think\facade\Db;
use think\Response;

class TeacherController extends BaseApiController
{
    use AdminAuthorization;

    public function index(): Response
    {
        $unauthorized = $this->requirePermission('teacher.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        [$page, $pageSize] = $this->paginationParams();

        $query = AdminUser::where('role_code', 'teacher')
            ->field(['id', 'username', 'name', 'role_code', 'status', 'last_login_at', 'last_login_ip', 'created_at', 'updated_at'])
            ->order('id asc');

        $total = (clone $query)->count();
        $items = $query->page($page, $pageSize)->select()->toArray();

        $teacherIds = array_map(static fn (array $item): int => (int) ($item['id'] ?? 0), $items);
        $scopeMap = [];
        if ($teacherIds !== []) {
            $rows = Db::name('admin_data_scopes')
                ->whereIn('admin_user_id', $teacherIds)
                ->where('scope_type', 'paper')
                ->field(['admin_user_id', 'scope_value'])
                ->select()
                ->toArray();

            foreach ($rows as $row) {
                $scopeMap[(int) ($row['admin_user_id'] ?? 0)][] = (string) ($row['scope_value'] ?? '');
            }
        }

        foreach ($items as &$item) {
            $item['paper_scope_ids'] = $scopeMap[(int) ($item['id'] ?? 0)] ?? [];
        }
        unset($item);

        return $this->success($this->paginationData($items, $total, $page, $pageSize), '获取教师列表成功');
    }

    public function save(): Response
    {
        $unauthorized = $this->requirePermission('teacher.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->payload();

        $id = (int) ($payload['id'] ?? 0);
        $username = trim((string) ($payload['username'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $status = (int) ($payload['status'] ?? 1);

        if ($username === '' || $name === '') {
            return $this->error('账号和姓名不能为空', 422);
        }

        /** @var AdminUser|null $teacher */
        $teacher = $id > 0 ? AdminUser::find($id) : new AdminUser();
        if ($teacher === null) {
            return $this->error('教师账号不存在', 404);
        }

        $duplicate = AdminUser::where('username', $username)
            ->where('id', '<>', (int) $teacher->id)
            ->find();
        if ($duplicate !== null) {
            return $this->error('教师账号已存在', 422);
        }

        $teacher->username = $username;
        $teacher->name = $name;
        $teacher->role_code = 'teacher';
        $teacher->status = $status === 1 ? 1 : 0;

        if ((int) $teacher->id === 0) {
            $teacher->password = password_hash('teacher123', PASSWORD_DEFAULT);
        }

        $teacher->save();

        return $this->success([
            'id' => (int) $teacher->id,
        ], '保存教师成功');
    }

    public function assignScopes(): Response
    {
        $unauthorized = $this->requirePermission('teacher.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->payload();

        $teacherId = (int) ($payload['teacher_id'] ?? 0);
        $scopeType = trim((string) ($payload['scope_type'] ?? ''));
        $scopeValues = array_values(array_unique(array_map('strval', (array) ($payload['scope_values'] ?? []))));

        if ($teacherId <= 0 || $scopeType === '') {
            return $this->error('教师 ID 或范围类型无效', 422);
        }

        /** @var AdminUser|null $teacher */
        $teacher = AdminUser::where('id', $teacherId)->where('role_code', 'teacher')->find();
        if ($teacher === null) {
            return $this->error('教师账号不存在', 404);
        }

        Db::name('admin_data_scopes')
            ->where('admin_user_id', $teacherId)
            ->where('scope_type', $scopeType)
            ->delete();

        foreach ($scopeValues as $scopeValue) {
            if ($scopeValue === '') {
                continue;
            }

            Db::name('admin_data_scopes')->insert([
                'admin_user_id' => $teacherId,
                'scope_type' => $scopeType,
                'scope_value' => $scopeValue,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->success([
            'teacher_id' => $teacherId,
            'scope_type' => $scopeType,
            'scope_values' => $scopeValues,
        ], '保存教师数据范围成功');
    }

    public function delete(int $id): Response
    {
        $userController = app(UserController::class);
        $userController->request = $this->request;

        return $userController->delete($id);
    }
}
