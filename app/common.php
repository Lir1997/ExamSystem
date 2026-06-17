<?php

declare(strict_types=1);

use think\Response;

if (!function_exists('api_success')) {
    function api_success(array $data = [], string $message = 'ok', int $code = 0): Response
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ]);
    }
}

if (!function_exists('api_error')) {
    function api_error(string $message = 'error', int $code = 1, array $data = []): Response
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ]);
    }
}
// 应用公共文件
