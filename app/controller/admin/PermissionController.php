<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\model\AdminRolePermission;
use app\service\RbacService;
use app\trait\AdminAuthorization;
use think\Response;

class PermissionController extends BaseApiController
{
    use AdminAuthorization;

    public function index(): Response
    {
        $unauthorized = $this->requirePermission('permission.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        return $this->success([
            'items' => app(RbacService::class)->permissions(),
        ], '获取权限列表成功');
    }

    public function roles(): Response
    {
        $unauthorized = $this->requirePermission('permission.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $roles = app(RbacService::class)->permissionEditableRoles();
        foreach ($roles as &$role) {
            $role['id'] = (int) ($role['id'] ?? 0);
            $role['status'] = (int) ($role['status'] ?? 0);
            $role['permissions'] = array_map(
                'intval',
                AdminRolePermission::where('role_id', (int) $role['id'])->column('permission_id')
            );
        }
        unset($role);

        return $this->success(['items' => $roles], '获取角色权限成功');
    }

    public function saveRole(): Response
    {
        $unauthorized = $this->requirePermission('permission.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->payload();
        $id = (int) ($payload['id'] ?? 0);
        $code = trim((string) ($payload['code'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $status = (int) ($payload['status'] ?? 1);

        try {
            $role = app(RbacService::class)->saveRole($id, $code, $name, $status);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success([
            'id' => (int) $role->id,
            'code' => (string) $role->code,
            'name' => (string) $role->name,
            'status' => (int) $role->status,
        ], '保存角色成功');
    }

    public function saveRolePermissions(int $roleId): Response
    {
        $unauthorized = $this->requirePermission('permission.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        if ($roleId <= 0) {
            return $this->error('角色 ID 无效', 422);
        }

        $payload = $this->payload();
        $permissionIds = array_values(array_unique(array_map('intval', (array) ($payload['permission_ids'] ?? []))));

        try {
            app(RbacService::class)->syncRolePermissions($roleId, $permissionIds);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success([
            'role_id' => $roleId,
            'permission_ids' => $permissionIds,
        ], '保存角色权限成功');
    }

    public function deleteRole(int $roleId): Response
    {
        $unauthorized = $this->requirePermission('permission.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        if ($roleId <= 0) {
            return $this->error('角色 ID 无效', 422);
        }

        try {
            app(RbacService::class)->deleteRole($roleId);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success(['role_id' => $roleId], '删除角色成功');
    }
}
