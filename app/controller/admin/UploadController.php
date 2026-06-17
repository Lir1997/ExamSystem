<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\trait\AdminAuthorization;
use think\facade\Filesystem;
use think\Response;

class UploadController extends BaseApiController
{
    use AdminAuthorization;

    public function image(): Response
    {
        $unauthorized = $this->requirePermission('question.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $file = $this->request->file('file');
        if ($file === null) {
            return $this->error('未获取到上传文件', 422);
        }

        $savename = Filesystem::disk('public')->putFile('question-images', $file);
        $url = '/storage/' . str_replace('\\', '/', $savename);

        return $this->success([
            'url' => $url,
        ], '上传成功');
    }

    public function file(): Response
    {
        $unauthorized = $this->requirePermission('question.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $file = $this->request->file('file');
        if ($file === null) {
            return $this->error('未获取到上传文件', 422);
        }

        $originalName = method_exists($file, 'getOriginalName') ? (string) $file->getOriginalName() : '';
        $savename = Filesystem::disk('public')->putFile('question-files', $file);
        $url = '/storage/' . str_replace('\\', '/', $savename);

        return $this->success([
            'url' => $url,
            'name' => $originalName !== '' ? $originalName : basename($savename),
        ], '上传成功');
    }
}
