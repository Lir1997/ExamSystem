<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;

class SessionMaintenanceService
{
    public function clearAllTokens(): array
    {
        $tables = [
            'admin_access_tokens',
            'student_access_tokens',
            'monitor_access_tokens',
            'monitor_bridge_tokens',
        ];

        $counts = [];

        Db::transaction(function () use ($tables, &$counts): void {
            foreach ($tables as $table) {
                $count = (int) Db::name($table)->count();
                Db::name($table)->delete(true);
                $counts[$table] = $count;
            }
        });

        return [
            'cleared' => $counts,
            'total' => array_sum($counts),
        ];
    }
}
