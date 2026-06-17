<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\model\AdminUser;
use app\service\AuthService;
use think\Response;

class AuthController extends BaseApiController
{
    public function login(AuthService $authService): Response
    {
        $payload = $this->payload();

        $username = trim((string) ($payload['username'] ?? ''));
        $password = trim((string) ($payload['password'] ?? ''));

        if ($username === '' || $password === '') {
            return $this->error('请输入用户名和密码', 422);
        }

        $payload = $authService->attempt($username, $password);

        if ($payload === null) {
            return $this->error('用户名或密码错误', 401);
        }

        return $this->success($payload, '登录成功');
    }

    public function profile(): Response
    {
        /** @var AdminUser|null $admin */
        $admin = $this->request->adminUser ?? null;
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        $profile = app(AuthService::class)->profile($admin);

        return $this->success($profile);
    }
}
