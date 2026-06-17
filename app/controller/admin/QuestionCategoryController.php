<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\model\QuestionCategory;
use app\trait\AdminAuthorization;
use think\Response;

class QuestionCategoryController extends BaseApiController
{
    use AdminAuthorization;

    public function index(): Response
    {
        $unauthorized = $this->requirePermission('question.category.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        [$page, $pageSize] = $this->paginationParams();
        $query = QuestionCategory::order('sort asc,id asc');
        $total = (clone $query)->count();
        $items = $query->page($page, $pageSize)->select()->toArray();

        return $this->success($this->paginationData($items, $total, $page, $pageSize), '获取试题分类列表成功');
    }

    public function save(): Response
    {
        $unauthorized = $this->requirePermission('question.category.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->payload();

        $id = (int) ($payload['id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        $code = trim((string) ($payload['code'] ?? ''));
        $status = (int) ($payload['status'] ?? 1);
        $sort = (int) ($payload['sort'] ?? 0);

        if ($name === '' || $code === '') {
            return $this->error('分类名称和分类编码不能为空', 422);
        }

        /** @var QuestionCategory|null $category */
        $category = $id > 0 ? QuestionCategory::find($id) : new QuestionCategory();
        if ($category === null) {
            return $this->error('试题分类不存在', 404);
        }

        $duplicate = QuestionCategory::where('code', $code)
            ->where('id', '<>', (int) $category->id)
            ->find();
        if ($duplicate !== null) {
            return $this->error('分类编码已存在', 422);
        }

        $category->name = $name;
        $category->code = $code;
        $category->status = $status;
        $category->sort = $sort;
        $category->save();

        return $this->success([
            'id' => (int) $category->id,
        ], '保存试题分类成功');
    }

    public function delete(int $id): Response
    {
        $unauthorized = $this->requirePermission('question.category.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        if ($id <= 0) {
            return $this->error('试题分类 ID 无效', 422);
        }

        /** @var QuestionCategory|null $category */
        $category = QuestionCategory::find($id);
        if ($category === null) {
            return $this->error('试题分类不存在', 404);
        }

        $questionRefCount = (int) \think\facade\Db::name('questions')
            ->where('category_id', $id)
            ->count();
        if ($questionRefCount > 0) {
            return $this->error("当前分类下仍有 {$questionRefCount} 道试题，无法删除", 422);
        }

        $paperRefCount = (int) \think\facade\Db::name('papers')
            ->whereLike('config_json', '%"category_id":' . $id . '%')
            ->count();
        if ($paperRefCount > 0) {
            return $this->error("当前分类仍被 {$paperRefCount} 张试卷的随机规则引用，无法删除", 422);
        }

        \think\facade\Db::name('question_categories')->where('id', $id)->delete();

        return $this->success([
            'id' => $id,
        ], '删除试题分类成功');
    }
}
