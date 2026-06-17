<?php

declare(strict_types=1);

namespace app\controller\monitor;

use app\BaseController;
use think\Response;

class PageController extends BaseController
{
    public function index(): Response
    {
        $file = root_path() . 'public' . DIRECTORY_SEPARATOR . 'monitor' . DIRECTORY_SEPARATOR . 'index.html';

        if (!is_file($file)) {
            return response('monitor build not found', 404)->contentType('text/plain');
        }

        $content = file_get_contents($file);
        if (!is_string($content)) {
            return response('monitor build read failed', 500)->contentType('text/plain');
        }

        return response($content, 200)->contentType('text/html');
    }
}
