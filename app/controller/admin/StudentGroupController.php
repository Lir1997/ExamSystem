<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\model\StudentGroup;
use app\trait\AdminAuthorization;
use think\Response;
use think\facade\Db;

class StudentGroupController extends BaseApiController
{
    use AdminAuthorization;

    public function index(): Response
    {
        $unauthorized = $this->requirePermission('student.group.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        [$page, $pageSize] = $this->paginationParams();
        $keyword = trim((string) $this->request->get('keyword', ''));

        $query = StudentGroup::field(['id', 'code', 'name', 'status', 'created_at'])
            ->order('id asc');

        if ($keyword !== '') {
            $likeKeyword = '%' . $keyword . '%';
            $query->where(function ($builder) use ($likeKeyword) {
                $builder->whereRaw(
                    '(code LIKE :keyword_code OR name LIKE :keyword_name)',
                    [
                        'keyword_code' => $likeKeyword,
                        'keyword_name' => $likeKeyword,
                    ]
                );
            });
        }

        $total = (clone $query)->count();
        $groups = $query->page($page, $pageSize)->select()->toArray();

        $groupIds = array_column($groups, 'id');
        $memberCountMap = [];

        if ($groupIds !== []) {
            $rows = Db::name('student_group_members')
                ->whereIn('student_group_id', $groupIds)
                ->fieldRaw('student_group_id, COUNT(*) as member_count')
                ->group('student_group_id')
                ->select()
                ->toArray();

            foreach ($rows as $row) {
                $memberCountMap[$row['student_group_id']] = (int) $row['member_count'];
            }
        }

        foreach ($groups as &$group) {
            $group['member_count'] = $memberCountMap[$group['id']] ?? 0;
        }
        unset($group);

        return $this->success($this->paginationData($groups, $total, $page, $pageSize), '获取学生分组列表成功');
    }

    public function save(): Response
    {
        $unauthorized = $this->requirePermission('student.group.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->payload();

        $id = (int) ($payload['id'] ?? 0);
        $code = trim((string) ($payload['code'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $status = (int) ($payload['status'] ?? 1);

        if ($code === '' || $name === '') {
            return $this->error('分组编码和分组名称不能为空', 422);
        }

        /** @var StudentGroup|null $group */
        $group = $id > 0 ? StudentGroup::find($id) : new StudentGroup();
        if ($group === null) {
            return $this->error('学生分组不存在', 404);
        }

        $duplicate = StudentGroup::where('code', $code)
            ->where('id', '<>', (int) $group->id)
            ->find();
        if ($duplicate !== null) {
            return $this->error('分组编码已存在', 422);
        }

        $group->code = $code;
        $group->name = $name;
        $group->status = $status;
        $group->save();

        return $this->success([
            'id' => (int) $group->id,
        ], '保存学生分组成功');
    }

    public function delete(int $id): Response
    {
        $unauthorized = $this->requirePermission('student.group.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        if ($id <= 0) {
            return $this->error('学生分组 ID 无效', 422);
        }

        /** @var StudentGroup|null $group */
        $group = StudentGroup::find($id);
        if ($group === null) {
            return $this->error('学生分组不存在', 404);
        }

        $studentMemberCount = (int) Db::name('student_group_members')
            ->where('student_group_id', $id)
            ->count();
        if ($studentMemberCount > 0) {
            return $this->error("当前分组下仍有关联学生 {$studentMemberCount} 人，无法删除", 422);
        }

        $examRefCount = (int) Db::name('exam_student_groups')
            ->where('student_group_id', $id)
            ->count();
        if ($examRefCount > 0) {
            return $this->error("当前分组仍被 {$examRefCount} 场考试引用，无法删除", 422);
        }

        Db::name('student_groups')->where('id', $id)->delete();

        return $this->success([
            'id' => $id,
        ], '删除学生分组成功');
    }
}
