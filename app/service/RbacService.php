<?php

declare(strict_types=1);

namespace app\service;

use app\model\AdminDataScope;
use app\model\AdminPermission;
use app\model\AdminRole;
use app\model\AdminRolePermission;
use app\model\AdminUser;
use think\facade\Db;

class RbacService
{
    protected const RESERVED_ROLE_CODES = ['admin', 'teacher'];
    protected const SCOPE_TYPES = ['paper', 'question', 'exam'];

    protected const BUILTIN_PERMISSIONS = [
        ['code' => 'dashboard.view', 'name' => '工作台', 'path' => '/', 'icon' => 'House', 'menu_visible' => 1, 'sort' => 10, 'status' => 1],
        ['code' => 'user.manage', 'name' => '用户管理', 'path' => '/users', 'icon' => 'UserFilled', 'menu_visible' => 1, 'sort' => 15, 'status' => 1],
        ['code' => 'permission.manage', 'name' => '权限管理', 'path' => '/permissions', 'icon' => 'Lock', 'menu_visible' => 1, 'sort' => 18, 'status' => 1],
        ['code' => 'audit.view', 'name' => '审计中心', 'path' => '/audits', 'icon' => 'Notebook', 'menu_visible' => 1, 'sort' => 19, 'status' => 1],
        ['code' => 'system.settings.view', 'name' => '系统设置', 'path' => '/system/settings', 'icon' => 'Setting', 'menu_visible' => 1, 'sort' => 20, 'status' => 1],
        ['code' => 'student.manage', 'name' => '学生管理', 'path' => '/students', 'icon' => 'Avatar', 'menu_visible' => 1, 'sort' => 30, 'status' => 1],
        ['code' => 'student.group.manage', 'name' => '学生分组', 'path' => '/student-groups', 'icon' => 'Files', 'menu_visible' => 1, 'sort' => 40, 'status' => 1],
        ['code' => 'paper.manage', 'name' => '试卷管理', 'path' => '/papers', 'icon' => 'Document', 'menu_visible' => 1, 'sort' => 50, 'status' => 1],
        ['code' => 'question.manage', 'name' => '试题管理', 'path' => '/questions', 'icon' => 'Tickets', 'menu_visible' => 1, 'sort' => 60, 'status' => 1],
        ['code' => 'question.category.manage', 'name' => '试题分类管理', 'path' => '/question-categories', 'icon' => 'Collection', 'menu_visible' => 1, 'sort' => 70, 'status' => 1],
        ['code' => 'exam.manage', 'name' => '考试管理', 'path' => '/exams', 'icon' => 'Calendar', 'menu_visible' => 1, 'sort' => 80, 'status' => 1],
        ['code' => 'marking.manage', 'name' => '成绩管理', 'path' => '/marking', 'icon' => 'Checked', 'menu_visible' => 1, 'sort' => 90, 'status' => 1],
        ['code' => 'teacher.manage', 'name' => '教师管理', 'path' => '/users', 'icon' => 'User', 'menu_visible' => 0, 'sort' => 100, 'status' => 1],
    ];

    public function getRole(AdminUser $admin): ?array
    {
        $roleCode = trim((string) $admin->role_code);
        if ($roleCode === '') {
            return null;
        }

        $role = AdminRole::where('code', $roleCode)->where('status', 1)->find();
        if ($role === null) {
            return null;
        }

        return [
            'code' => (string) $role->code,
            'name' => (string) $role->name,
        ];
    }

    public function getPermissions(AdminUser $admin): array
    {
        $roleCode = trim((string) $admin->role_code);
        if ($roleCode === '') {
            return [];
        }

        if ($this->isAdmin($admin)) {
            return array_column($this->permissions(), 'code');
        }

        $role = AdminRole::where('code', $roleCode)->where('status', 1)->find();
        if ($role === null) {
            return [];
        }

        $permissionIds = AdminRolePermission::where('role_id', (int) $role->id)->column('permission_id');
        if ($permissionIds === []) {
            return [];
        }

        return AdminPermission::whereIn('id', array_map('intval', $permissionIds))
            ->where('status', 1)
            ->order('sort asc,id asc')
            ->column('code');
    }

    public function getMenus(AdminUser $admin): array
    {
        if ($this->isAdmin($admin)) {
            return array_values(array_map(
                static fn (array $permission): array => [
                    'code' => (string) ($permission['code'] ?? ''),
                    'name' => (string) ($permission['name'] ?? ''),
                    'path' => (string) ($permission['path'] ?? ''),
                    'icon' => (string) ($permission['icon'] ?? ''),
                ],
                array_filter($this->permissions(), static fn (array $permission): bool => (int) ($permission['menu_visible'] ?? 0) === 1 && (int) ($permission['status'] ?? 0) === 1)
            ));
        }

        $roleCode = trim((string) $admin->role_code);
        if ($roleCode === '') {
            return [];
        }

        $role = AdminRole::where('code', $roleCode)->where('status', 1)->find();
        if ($role === null) {
            return [];
        }

        $permissionIds = AdminRolePermission::where('role_id', (int) $role->id)->column('permission_id');
        if ($permissionIds === []) {
            return [];
        }

        $permissions = AdminPermission::whereIn('id', array_map('intval', $permissionIds))
            ->where('status', 1)
            ->where('menu_visible', 1)
            ->order('sort asc,id asc')
            ->select();

        $menus = [];
        foreach ($permissions as $permission) {
            $menus[] = [
                'code' => (string) $permission->code,
                'name' => (string) $permission->name,
                'path' => (string) $permission->path,
                'icon' => (string) $permission->icon,
            ];
        }

        return $menus;
    }

    public function getDataScopes(AdminUser $admin, string $scopeType): array
    {
        if (!$this->isValidScopeType($scopeType)) {
            return [];
        }

        return array_values(array_unique(array_map(
            'strval',
            AdminDataScope::where('admin_user_id', (int) $admin->id)
                ->where('scope_type', strtolower(trim($scopeType)))
                ->column('scope_value')
        )));
    }

    public function hasDataScopeRestriction(AdminUser $admin, string $scopeType): bool
    {
        if ($this->isAdmin($admin) || !$this->isValidScopeType($scopeType)) {
            return false;
        }

        return AdminDataScope::where('admin_user_id', (int) $admin->id)
            ->where('scope_type', strtolower(trim($scopeType)))
            ->count() > 0;
    }

    public function hasScopeAccess(AdminUser $admin, string $scopeType, int|string $scopeValue): bool
    {
        if ($this->isAdmin($admin)) {
            return true;
        }

        if (!$this->isValidScopeType($scopeType)) {
            return false;
        }

        $scopeValues = $this->getDataScopes($admin, $scopeType);
        if ($scopeValues === []) {
            return true;
        }

        return in_array((string) $scopeValue, $scopeValues, true);
    }

    public function roles(): array
    {
        return AdminRole::order('id asc')->select()->toArray();
    }

    public function permissionEditableRoles(): array
    {
        return array_values(array_filter($this->roles(), static function (array $role): bool {
            return strtolower(trim((string) ($role['code'] ?? ''))) !== 'admin';
        }));
    }

    public function scopeTypes(): array
    {
        return self::SCOPE_TYPES;
    }

    public function isValidScopeType(string $scopeType): bool
    {
        return in_array(strtolower(trim($scopeType)), self::SCOPE_TYPES, true);
    }

    public function isAdmin(AdminUser $admin): bool
    {
        return strtolower(trim((string) $admin->role_code)) === 'admin';
    }

    public function saveRole(int $id, string $code, string $name, int $status): AdminRole
    {
        $code = strtolower(trim($code));
        $name = trim($name);

        if ($code === '' || !preg_match('/^[a-z][a-z0-9_.-]{1,49}$/', $code)) {
            throw new \RuntimeException('角色编码仅支持小写字母、数字、点、中划线和下划线，且需以字母开头');
        }

        if ($name === '') {
            throw new \RuntimeException('角色名称不能为空');
        }

        /** @var AdminRole|null $role */
        $role = $id > 0 ? AdminRole::find($id) : new AdminRole();
        if ($id > 0 && $role === null) {
            throw new \RuntimeException('角色不存在');
        }

        $duplicate = AdminRole::where('code', $code)
            ->where('id', '<>', (int) ($role?->id ?? 0))
            ->find();
        if ($duplicate !== null) {
            throw new \RuntimeException('角色编码已存在');
        }

        $previousCode = $role !== null ? strtolower(trim((string) ($role->code ?? ''))) : '';
        if ($previousCode === 'admin') {
            throw new \RuntimeException('系统管理员角色不支持在权限管理中编辑');
        }

        if ($previousCode !== '' && in_array($previousCode, self::RESERVED_ROLE_CODES, true) && $previousCode !== $code) {
            throw new \RuntimeException('系统内置角色编码不允许修改');
        }

        if ($previousCode === '' && in_array($code, self::RESERVED_ROLE_CODES, true)) {
            throw new \RuntimeException('系统内置角色编码不可重复创建');
        }

        $role->code = $previousCode !== '' && in_array($previousCode, self::RESERVED_ROLE_CODES, true)
            ? $previousCode
            : $code;
        $role->name = $name;
        $role->status = $status === 1 ? 1 : 0;
        $role->save();

        return $role;
    }

    public function deleteRole(int $roleId): void
    {
        /** @var AdminRole|null $role */
        $role = AdminRole::find($roleId);
        if ($role === null) {
            throw new \RuntimeException('角色不存在');
        }

        $roleCode = strtolower(trim((string) ($role->code ?? '')));
        if (in_array($roleCode, self::RESERVED_ROLE_CODES, true)) {
            throw new \RuntimeException('系统内置角色不允许删除');
        }

        $userCount = AdminUser::where('role_code', $roleCode)->count();
        if ($userCount > 0) {
            throw new \RuntimeException('当前角色仍有 ' . $userCount . ' 个用户在使用，无法删除');
        }

        Db::transaction(function () use ($roleId): void {
            Db::name('admin_role_permissions')->where('role_id', $roleId)->delete();
            Db::name('admin_roles')->where('id', $roleId)->delete();
        });
    }

    public function permissions(): array
    {
        $dbPermissions = AdminPermission::order('sort asc,id asc')->select()->toArray();
        $map = [];

        foreach (self::BUILTIN_PERMISSIONS as $permission) {
            $map[(string) $permission['code']] = $permission;
        }

        foreach ($dbPermissions as $permission) {
            $code = trim((string) ($permission['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $map[$code] = array_merge($map[$code] ?? [], $permission);
        }

        $items = array_values($map);
        usort($items, static function (array $a, array $b): int {
            $sortCompare = ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0));
            if ($sortCompare !== 0) {
                return $sortCompare;
            }

            return strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
        });

        return array_map(static function (array $item): array {
            $item['id'] = isset($item['id']) ? (int) $item['id'] : 0;
            $item['menu_visible'] = (int) ($item['menu_visible'] ?? 0);
            $item['sort'] = (int) ($item['sort'] ?? 0);
            $item['status'] = (int) ($item['status'] ?? 0);

            return $item;
        }, $items);
    }

    public function syncRolePermissions(int $roleId, array $permissionIds): array
    {
        /** @var AdminRole|null $role */
        $role = AdminRole::find($roleId);
        if ($role === null) {
            throw new \RuntimeException('角色不存在');
        }

        if (strtolower(trim((string) ($role->code ?? ''))) === 'admin') {
            throw new \RuntimeException('系统管理员默认拥有全部权限，不支持单独编辑');
        }

        $permissionIds = array_values(array_unique(array_filter(array_map(
            'intval',
            $permissionIds
        ), static fn (int $id): bool => $id > 0)));

        Db::transaction(function () use ($roleId, $permissionIds): void {
            Db::name('admin_role_permissions')->where('role_id', $roleId)->delete();

            foreach ($permissionIds as $permissionId) {
                Db::name('admin_role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        });

        return $permissionIds;
    }
}
