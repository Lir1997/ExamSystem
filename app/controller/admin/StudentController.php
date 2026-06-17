<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\model\Student;
use app\model\StudentGroup;
use app\service\SystemSettingService;
use app\trait\AdminAuthorization;
use think\Response;
use think\facade\Db;

class StudentController extends BaseApiController
{
    use AdminAuthorization;

    protected array $passwordHashCache = [];

    public function index(): Response
    {
        $unauthorized = $this->requirePermission('student.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $keyword = trim((string) $this->request->get('keyword', ''));
        $groupId = (int) $this->request->get('group_id', 0);
        $status = $this->request->get('status', '');
        [$page, $pageSize] = $this->paginationParams();

        $query = Db::name('students')
            ->alias('s')
            ->field(['s.id', 's.username', 's.name', 's.student_no', 's.id_card', 's.status', 's.created_at'])
            ->order('s.id asc');

        if ($keyword !== '') {
            $likeKeyword = '%' . $keyword . '%';
            $query->where(function ($builder) use ($likeKeyword) {
                $builder->whereRaw(
                    '(s.username LIKE :keyword_username OR s.name LIKE :keyword_name OR s.student_no LIKE :keyword_student_no OR s.id_card LIKE :keyword_id_card)',
                    [
                        'keyword_username' => $likeKeyword,
                        'keyword_name' => $likeKeyword,
                        'keyword_student_no' => $likeKeyword,
                        'keyword_id_card' => $likeKeyword,
                    ]
                );
            });
        }

        if ($status !== '' && in_array((string) $status, ['0', '1'], true)) {
            $query->where('s.status', (int) $status);
        }

        if ($groupId > 0) {
            $studentIds = Db::name('student_group_members')
                ->where('student_group_id', $groupId)
                ->column('student_id');

            if ($studentIds === []) {
                return $this->success($this->paginationData([], 0, $page, $pageSize), '获取学生列表成功');
            }

            $query->whereIn('s.id', array_map('intval', $studentIds));
        }

        $total = (clone $query)->count();
        $students = $query
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $studentIds = array_column($students, 'id');
        $groupMap = [];

        if ($studentIds !== []) {
            $rows = Db::name('student_group_members')
                ->alias('m')
                ->join('student_groups g', 'g.id = m.student_group_id')
                ->whereIn('m.student_id', $studentIds)
                ->field(['m.student_id', 'g.id' => 'group_id', 'g.name' => 'group_name', 'g.code' => 'group_code'])
                ->select()
                ->toArray();

            foreach ($rows as $row) {
                $groupMap[$row['student_id']][] = [
                    'id' => (int) $row['group_id'],
                    'name' => (string) $row['group_name'],
                    'code' => (string) $row['group_code'],
                ];
            }
        }

        foreach ($students as &$student) {
            $student['groups'] = $groupMap[$student['id']] ?? [];
        }
        unset($student);

        return $this->success($this->paginationData($students, $total, $page, $pageSize), '获取学生列表成功');
    }

    public function save(): Response
    {
        $unauthorized = $this->requirePermission('student.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->payload();

        $id = (int) ($payload['id'] ?? 0);
        $username = trim((string) ($payload['username'] ?? ''));
        $studentNo = trim((string) ($payload['student_no'] ?? ''));
        $idCard = trim((string) ($payload['id_card'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $password = trim((string) ($payload['password'] ?? ''));
        $status = (int) ($payload['status'] ?? 1);
        $groupIds = array_values(array_unique(array_map('intval', (array) ($payload['group_ids'] ?? []))));

        if ($username === '' || $studentNo === '' || $name === '') {
            return $this->error('账号、学号、姓名不能为空', 422);
        }

        /** @var Student|null $student */
        $student = $id > 0 ? Student::find($id) : new Student();
        if ($student === null) {
            return $this->error('学生不存在', 404);
        }

        $duplicateUsername = Student::where('username', $username)
            ->where('id', '<>', (int) $student->id)
            ->find();
        if ($duplicateUsername !== null) {
            return $this->error('学生账号已存在', 422);
        }

        $duplicateStudentNo = Student::where('student_no', $studentNo)
            ->where('id', '<>', (int) $student->id)
            ->find();
        if ($duplicateStudentNo !== null) {
            return $this->error('学生学号已存在', 422);
        }

        if ($idCard !== '') {
            $duplicateIdCard = Student::where('id_card', $idCard)
                ->where('id', '<>', (int) $student->id)
                ->find();
            if ($duplicateIdCard !== null) {
                return $this->error('学生身份证号已存在', 422);
            }
        }

        $student->username = $username;
        $student->student_no = $studentNo;
        $student->id_card = $idCard !== '' ? $idCard : null;
        $student->name = $name;
        $student->status = $status;

        $defaultPassword = $this->defaultStudentPassword();

        if ((int) $student->id === 0) {
            $finalPassword = $password !== '' ? $password : $defaultPassword;
            $student->password = $this->hashPassword($finalPassword);
        } elseif ($password !== '') {
            $student->password = $this->hashPassword($password);
        }

        $student->save();

        Db::name('student_group_members')->where('student_id', (int) $student->id)->delete();
        foreach ($groupIds as $groupId) {
            if ($groupId <= 0) {
                continue;
            }

            Db::name('student_group_members')->insert([
                'student_group_id' => $groupId,
                'student_id' => (int) $student->id,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->success([
            'id' => (int) $student->id,
            'default_password_used' => $password === '',
            'default_password' => $defaultPassword,
        ], '保存学生成功');
    }

    public function delete(int $id): Response
    {
        $unauthorized = $this->requirePermission('student.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        if ($id <= 0) {
            return $this->error('学生 ID 无效', 422);
        }

        /** @var Student|null $student */
        $student = Student::find($id);
        if ($student === null) {
            return $this->error('学生不存在', 404);
        }

        Db::name('student_group_members')->where('student_id', $id)->delete();
        Db::name('student_access_tokens')->where('student_id', $id)->delete();
        Db::name('students')->where('id', $id)->delete();

        return $this->success([
            'id' => $id,
        ], '删除学生成功');
    }

    public function resetPassword(): Response
    {
        $unauthorized = $this->requirePermission('student.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->payload();
        $studentId = (int) ($payload['student_id'] ?? 0);
        $customPassword = trim((string) ($payload['password'] ?? ''));

        if ($studentId <= 0) {
            return $this->error('学生 ID 无效', 422);
        }

        /** @var Student|null $student */
        $student = Student::find($studentId);
        if ($student === null) {
            return $this->error('学生不存在', 404);
        }

        $newPassword = $customPassword !== '' ? $customPassword : $this->defaultStudentPassword();
        $student->password = $this->hashPassword($newPassword);
        $student->save();

        Db::name('student_access_tokens')->where('student_id', $studentId)->delete();

        return $this->success([
            'student_id' => $studentId,
            'password' => $newPassword,
            'used_default_password' => $customPassword === '',
        ], '重置学生密码成功');
    }

    public function import(): Response
    {
        return $this->importCommit();
    }

    public function importPreview(): Response
    {
        $unauthorized = $this->requirePermission('student.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        try {
            $analysis = $this->parseStudentImportUpload();
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $payload = $this->buildStudentImportPreviewPayload($analysis);

        return $this->success($payload, $payload['can_import'] ? '学生导入解析完成' : '学生导入解析发现错误');
    }

    public function importCommit(): Response
    {
        $unauthorized = $this->requirePermission('student.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        @set_time_limit(300);

        try {
            $analysis = $this->parseStudentImportUpload();
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        if (($analysis['error_count'] ?? 0) > 0 || !is_array($analysis['items'] ?? null) || $analysis['items'] === []) {
            return $this->error('导入文件仍存在解析错误，请修正后再确认导入', 422);
        }

        $createdCount = 0;
        $updatedCount = 0;

        Db::transaction(function () use ($analysis, &$createdCount, &$updatedCount): void {
            foreach ($analysis['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $studentId = (int) ($item['student_id'] ?? 0);

                /** @var Student|null $student */
                $student = $studentId > 0 ? Student::find($studentId) : new Student();
                if ($student === null) {
                    throw new \RuntimeException('导入目标学生不存在，请重新解析后再导入');
                }

                $student->username = (string) ($item['username'] ?? '');
                $student->student_no = (string) ($item['student_no'] ?? '');
                $student->id_card = ($item['id_card'] ?? '') !== '' ? (string) $item['id_card'] : null;
                $student->name = (string) ($item['name'] ?? '');
                $student->status = (int) ($item['status'] ?? 1);

                $password = $item['password'] ?? null;
                if ((int) $student->id === 0) {
                    $student->password = $this->hashPassword((string) $password);
                    $createdCount++;
                } elseif (is_string($password) && $password !== '') {
                    $student->password = $this->hashPassword($password);
                    $updatedCount++;
                } else {
                    $updatedCount++;
                }

                $student->save();

                Db::name('student_group_members')->where('student_id', (int) $student->id)->delete();
                foreach ((array) ($item['group_ids'] ?? []) as $groupId) {
                    $groupId = (int) $groupId;
                    if ($groupId <= 0) {
                        continue;
                    }

                    Db::name('student_group_members')->insert([
                        'student_group_id' => $groupId,
                        'student_id' => (int) $student->id,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        });

        return $this->success([
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'imported_count' => $createdCount + $updatedCount,
            'error_count' => 0,
            'errors' => [],
            'default_password' => (string) ($analysis['default_password'] ?? $this->defaultStudentPassword()),
        ], '学生批量导入成功');
    }

    public function importTemplate(): Response
    {
        $unauthorized = $this->requirePermission('student.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $format = strtolower(trim((string) $this->request->get('format', 'csv')));
        $rows = $this->studentImportTemplateRows();

        if ($format === 'xlsx') {
            $content = $this->buildXlsxTemplate($rows);
            return $this->downloadBinaryTemplate($content, 'student_import_template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }

        $content = $this->buildCsvTemplate($rows);
        return $this->downloadBinaryTemplate($content, 'student_import_template.csv', 'text/csv; charset=utf-8');
    }

    protected function parseImportRows(string $filePath, string $extension): array
    {
        return match ($extension) {
            'csv' => $this->parseCsvRows($filePath),
            'xlsx' => $this->parseXlsxRows($filePath),
            default => throw new \RuntimeException('当前仅支持 CSV 和 XLSX 文件导入'),
        };
    }

    protected function parseStudentImportUpload(): array
    {
        $file = $this->request->file('file');
        if ($file === null) {
            throw new \RuntimeException('未获取到导入文件');
        }

        $realPath = $file->getRealPath();
        if (!is_string($realPath) || $realPath === '' || !is_file($realPath)) {
            throw new \RuntimeException('导入文件无效');
        }

        $originalName = method_exists($file, 'getOriginalName') ? (string) $file->getOriginalName() : '';
        $extension = strtolower(pathinfo($originalName !== '' ? $originalName : $realPath, PATHINFO_EXTENSION));
        $rows = $this->parseImportRows($realPath, $extension);

        return $this->buildStudentImportAnalysis($rows, $originalName !== '' ? $originalName : basename($realPath));
    }

    protected function buildStudentImportAnalysis(array $rows, string $fileName): array
    {
        if (count($rows) <= 1) {
            throw new \RuntimeException('导入文件没有可用数据');
        }

        $defaultPassword = $this->defaultStudentPassword();
        $header = array_map(static fn ($item): string => trim((string) $item), $rows[0]);
        $requiredHeaders = ['username', 'student_no', 'name'];
        $errors = [];

        foreach ($requiredHeaders as $requiredHeader) {
            if (!in_array($requiredHeader, $header, true)) {
                $errors[] = [
                    'row_no' => 1,
                    'message' => '导入文件缺少必需列：' . $requiredHeader,
                ];
            }
        }

        if ($errors !== []) {
            return [
                'file_name' => $fileName,
                'items' => [],
                'errors' => $errors,
                'total_rows' => 0,
                'valid_count' => 0,
                'error_count' => count($errors),
                'can_import' => false,
                'default_password' => $defaultPassword,
            ];
        }

        $groupMap = [];
        $groups = StudentGroup::field(['id', 'code', 'name'])->select()->toArray();
        foreach ($groups as $group) {
            $groupCode = $this->normalizeStudentImportGroupCode((string) ($group['code'] ?? ''));
            if ($groupCode === '') {
                continue;
            }

            $groupMap[$groupCode] = [
                'id' => (int) ($group['id'] ?? 0),
                'code' => (string) ($group['code'] ?? ''),
                'name' => (string) ($group['name'] ?? ''),
            ];
        }

        $items = [];
        $totalRows = 0;
        $seenUsernames = [];
        $seenStudentNos = [];
        $seenIdCards = [];

        foreach ($rows as $index => $row) {
            if ($index === 0) {
                continue;
            }

            $lineNo = $index + 1;
            $item = $this->mapRowByHeader($header, $row);
            if ($this->isEmptyImportRow($item)) {
                continue;
            }

            $totalRows++;
            $rowErrors = [];

            $username = trim((string) ($item['username'] ?? ''));
            $studentNo = trim((string) ($item['student_no'] ?? ''));
            $idCard = trim((string) ($item['id_card'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            $password = trim((string) ($item['password'] ?? ''));
            $statusText = trim((string) ($item['status'] ?? '1'));
            $groupCodesText = trim((string) ($item['group_codes'] ?? ''));

            if ($username === '' || $studentNo === '' || $name === '') {
                $rowErrors[] = '缺少账号、学号或姓名';
            }

            $usernameKey = $this->normalizeStudentImportKey($username);
            if ($usernameKey !== '') {
                if (array_key_exists($usernameKey, $seenUsernames)) {
                    $rowErrors[] = '账号与第 ' . $seenUsernames[$usernameKey] . ' 行重复：' . $username;
                } else {
                    $seenUsernames[$usernameKey] = $lineNo;
                }
            }

            $studentNoKey = $this->normalizeStudentImportKey($studentNo);
            if ($studentNoKey !== '') {
                if (array_key_exists($studentNoKey, $seenStudentNos)) {
                    $rowErrors[] = '学号与第 ' . $seenStudentNos[$studentNoKey] . ' 行重复：' . $studentNo;
                } else {
                    $seenStudentNos[$studentNoKey] = $lineNo;
                }
            }

            $idCardKey = $this->normalizeStudentImportKey($idCard);
            if ($idCardKey !== '') {
                if (array_key_exists($idCardKey, $seenIdCards)) {
                    $rowErrors[] = '身份证号与第 ' . $seenIdCards[$idCardKey] . ' 行重复：' . $idCard;
                } else {
                    $seenIdCards[$idCardKey] = $lineNo;
                }
            }

            $status = 1;
            if ($statusText !== '' && !in_array($statusText, ['0', '1'], true)) {
                $rowErrors[] = '状态仅支持 0 或 1';
            } elseif ($statusText !== '') {
                $status = (int) $statusText;
            }

            $groupIds = [];
            $groupCodes = [];
            $groupNames = [];
            $normalizedGroupCodes = [];

            if ($groupCodesText !== '') {
                $codes = preg_split('/[|,，]/u', $groupCodesText) ?: [];
                foreach ($codes as $code) {
                    $normalizedCode = $this->normalizeStudentImportGroupCode((string) $code);
                    if ($normalizedCode === '' || in_array($normalizedCode, $normalizedGroupCodes, true)) {
                        continue;
                    }

                    $normalizedGroupCodes[] = $normalizedCode;
                    $group = $groupMap[$normalizedCode] ?? null;
                    if ($group === null) {
                        $rowErrors[] = '包含不存在的分组编码：' . trim((string) $code);
                        continue;
                    }

                    $groupIds[] = (int) $group['id'];
                    $groupCodes[] = (string) $group['code'];
                    $groupNames[] = (string) $group['name'];
                }
            }

            /** @var Student|null $studentByUsername */
            $studentByUsername = $username !== '' ? Student::where('username', $username)->find() : null;
            /** @var Student|null $studentByStudentNo */
            $studentByStudentNo = $studentNo !== '' ? Student::where('student_no', $studentNo)->find() : null;
            $student = null;

            if ($studentByUsername !== null && $studentByStudentNo !== null && (int) $studentByUsername->id !== (int) $studentByStudentNo->id) {
                $rowErrors[] = '账号与学号匹配到不同学生，请核对后重试';
            } else {
                $student = $studentByUsername ?? $studentByStudentNo;
            }

            if ($idCard !== '') {
                $duplicateIdCard = Student::where('id_card', $idCard);
                if ($student !== null) {
                    $duplicateIdCard->where('id', '<>', (int) $student->id);
                }

                if ($duplicateIdCard->find() !== null) {
                    $rowErrors[] = '学生身份证号已存在：' . $idCard;
                }
            }

            if ($rowErrors !== []) {
                foreach ($rowErrors as $message) {
                    $errors[] = [
                        'row_no' => $lineNo,
                        'message' => $message,
                    ];
                }
                continue;
            }

            $isUpdate = $student !== null;
            $passwordMode = $password !== '' ? 'custom' : ($isUpdate ? 'keep' : 'default');
            $items[] = [
                'row_no' => $lineNo,
                'action' => $isUpdate ? 'update' : 'create',
                'action_label' => $isUpdate ? '更新' : '新增',
                'student_id' => $isUpdate ? (int) $student->id : null,
                'username' => $username,
                'student_no' => $studentNo,
                'id_card' => $idCard,
                'name' => $name,
                'status' => $status,
                'status_label' => $status === 1 ? '启用' : '停用',
                'group_ids' => $groupIds,
                'group_codes' => $groupCodes,
                'group_names' => $groupNames,
                'group_label' => $groupNames !== []
                    ? '设置 ' . count($groupNames) . ' 个分组'
                    : ($isUpdate ? '导入后清空现有分组' : '导入后不分配分组'),
                'password_mode' => $passwordMode,
                'password_label' => $passwordMode === 'custom'
                    ? ($isUpdate ? '重置为文件内密码' : '使用文件内密码')
                    : ($isUpdate ? '保持原密码' : '使用系统默认密码'),
                'password' => $passwordMode === 'custom' ? $password : ($isUpdate ? null : $defaultPassword),
            ];
        }

        return [
            'file_name' => $fileName,
            'items' => $items,
            'errors' => $errors,
            'total_rows' => $totalRows,
            'valid_count' => count($items),
            'error_count' => count($errors),
            'can_import' => $totalRows > 0 && count($errors) === 0 && count($items) > 0,
            'default_password' => $defaultPassword,
        ];
    }

    protected function buildStudentImportPreviewPayload(array $analysis): array
    {
        $items = [];
        foreach ((array) ($analysis['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $items[] = [
                'row_no' => (int) ($item['row_no'] ?? 0),
                'action' => (string) ($item['action'] ?? ''),
                'action_label' => (string) ($item['action_label'] ?? ''),
                'student_id' => $item['student_id'] ?? null,
                'username' => (string) ($item['username'] ?? ''),
                'student_no' => (string) ($item['student_no'] ?? ''),
                'id_card' => (string) ($item['id_card'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'status' => (int) ($item['status'] ?? 1),
                'status_label' => (string) ($item['status_label'] ?? ''),
                'group_codes' => array_values(array_map(static fn ($value): string => (string) $value, (array) ($item['group_codes'] ?? []))),
                'group_names' => array_values(array_map(static fn ($value): string => (string) $value, (array) ($item['group_names'] ?? []))),
                'group_label' => (string) ($item['group_label'] ?? ''),
                'password_mode' => (string) ($item['password_mode'] ?? ''),
                'password_label' => (string) ($item['password_label'] ?? ''),
            ];
        }

        return [
            'file_name' => (string) ($analysis['file_name'] ?? ''),
            'items' => $items,
            'errors' => array_values((array) ($analysis['errors'] ?? [])),
            'total_rows' => (int) ($analysis['total_rows'] ?? 0),
            'valid_count' => (int) ($analysis['valid_count'] ?? 0),
            'error_count' => (int) ($analysis['error_count'] ?? 0),
            'can_import' => (bool) ($analysis['can_import'] ?? false),
            'default_password' => (string) ($analysis['default_password'] ?? $this->defaultStudentPassword()),
        ];
    }

    protected function parseCsvRows(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line);
        }

        return $rows;
    }

    protected function parseXlsxRows(string $filePath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('当前环境缺少 ZipArchive 扩展，暂时无法解析 XLSX 文件');
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('无法打开 XLSX 文件');
        }

        try {
            $sharedStrings = $this->loadXlsxSharedStrings($zip);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if (!is_string($sheetXml) || trim($sheetXml) === '') {
                return [];
            }

            $sheet = @simplexml_load_string($sheetXml);
            if ($sheet === false) {
                throw new \RuntimeException('XLSX 工作表解析失败');
            }

            $namespaces = $sheet->getNamespaces(true);
            if (isset($namespaces[''])) {
                $sheet->registerXPathNamespace('main', $namespaces['']);
            }

            $rows = [];
            $rowNodes = $sheet->xpath('//main:sheetData/main:row');
            if (!is_array($rowNodes)) {
                return [];
            }

            foreach ($rowNodes as $rowNode) {
                $values = [];
                $cells = $rowNode->xpath('./*[local-name()="c"]');
                if (!is_array($cells)) {
                    continue;
                }

                foreach ($cells as $cell) {
                    $ref = (string) ($cell['r'] ?? '');
                    $columnIndex = $this->xlsxColumnIndexFromRef($ref);
                    $values[$columnIndex] = $this->xlsxCellValue($cell, $sharedStrings);
                }

                if ($values === []) {
                    continue;
                }

                ksort($values);
                $maxIndex = max(array_keys($values));
                $row = [];
                for ($index = 0; $index <= $maxIndex; $index++) {
                    $row[] = isset($values[$index]) ? trim((string) $values[$index]) : '';
                }

                $rows[] = $row;
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    protected function mapRowByHeader(array $header, array $row): array
    {
        $item = [];
        foreach ($header as $index => $column) {
            $item[$column] = isset($row[$index]) ? trim((string) $row[$index]) : '';
        }
        return $item;
    }

    protected function isEmptyImportRow(array $item): bool
    {
        foreach ($item as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    protected function normalizeStudentImportKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    protected function normalizeStudentImportGroupCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    protected function defaultStudentPassword(): string
    {
        $settings = app(SystemSettingService::class)->all();
        $password = trim((string) ($settings['student_default_password'] ?? ''));
        return $password !== '' ? $password : 'student123';
    }

    protected function hashPassword(string $password): string
    {
        if (!array_key_exists($password, $this->passwordHashCache)) {
            $this->passwordHashCache[$password] = password_hash($password, PASSWORD_DEFAULT);
        }

        return $this->passwordHashCache[$password];
    }

    protected function studentImportTemplateRows(): array
    {
        return [
            ['username', 'student_no', 'id_card', 'name', 'password', 'status', 'group_codes'],
            ['student001', '2026001', '370101200001011234', '张三', 'student001', '1', 'class-01|class-02'],
            ['student002', '2026002', '', '李四', '', '1', 'class-01'],
        ];
    }

    protected function buildCsvTemplate(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $escaped = array_map(static function ($value): string {
                $text = (string) $value;
                $text = str_replace('"', '""', $text);

                return '"' . $text . '"';
            }, $row);
            $lines[] = implode(',', $escaped);
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $lines);
    }

    protected function buildXlsxTemplate(array $rows): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('当前环境缺少 ZipArchive 扩展，暂时无法生成 Excel 模板');
        }

        $tempBase = tempnam(sys_get_temp_dir(), 'student-template-');
        if ($tempBase === false) {
            throw new \RuntimeException('无法创建临时模板文件');
        }

        @unlink($tempBase);
        $tempFile = $tempBase . '.xlsx';

        $zip = new \ZipArchive();
        if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tempFile);
            throw new \RuntimeException('无法创建 Excel 模板');
        }

        $contentTypes = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML;

        $rels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;

        $workbook = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="students" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;

        $workbookRels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;

        $styles = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>
  <fills count="1"><fill><patternFill patternType="none"/></fill></fills>
  <borders count="1"><border/></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML;

        $sheetXml = $this->buildTemplateSheetXml($rows);

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/styles.xml', $styles);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $content = file_get_contents($tempFile);
        @unlink($tempFile);

        if (!is_string($content) || $content === '') {
            throw new \RuntimeException('Excel 模板生成失败');
        }

        return $content;
    }

    protected function buildTemplateSheetXml(array $rows): string
    {
        $xmlRows = [];
        foreach ($rows as $rowIndex => $row) {
            $cellXml = [];
            foreach ($row as $columnIndex => $value) {
                $cellRef = $this->xlsxColumnName($columnIndex) . ($rowIndex + 1);
                $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cellXml[] = '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
            }

            $xmlRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cellXml) . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . implode('', $xmlRows) . '</sheetData>'
            . '</worksheet>';
    }

    protected function loadXlsxSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($xml) || trim($xml) === '') {
            return [];
        }

        $document = @simplexml_load_string($xml);
        if ($document === false) {
            return [];
        }

        $namespaces = $document->getNamespaces(true);
        if (isset($namespaces[''])) {
            $document->registerXPathNamespace('main', $namespaces['']);
        }

        $nodes = $document->xpath('//main:si');
        if (!is_array($nodes)) {
            return [];
        }

        $strings = [];
        foreach ($nodes as $node) {
            $parts = $node->xpath('.//*[local-name()="t"]');
            if (!is_array($parts) || $parts === []) {
                $strings[] = '';
                continue;
            }

            $text = '';
            foreach ($parts as $part) {
                $text .= (string) $part;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    protected function xlsxCellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 'inlineStr') {
            $parts = $cell->xpath('.//*[local-name()="t"]');
            if (!is_array($parts) || $parts === []) {
                return '';
            }

            $text = '';
            foreach ($parts as $part) {
                $text .= (string) $part;
            }

            return $text;
        }

        $valueNode = $cell->xpath('.//*[local-name()="v"]');
        $value = is_array($valueNode) && isset($valueNode[0]) ? (string) $valueNode[0] : '';

        if ($type === 's') {
            $index = (int) $value;

            return isset($sharedStrings[$index]) ? (string) $sharedStrings[$index] : '';
        }

        return $value;
    }

    protected function xlsxColumnIndexFromRef(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?? '';
        $index = 0;

        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return max($index - 1, 0);
    }

    protected function xlsxColumnName(int $index): string
    {
        $name = '';
        $index++;

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $name = chr(65 + $remainder) . $name;
            $index = intdiv($index - 1, 26);
        }

        return $name;
    }

    protected function downloadBinaryTemplate(string $content, string $filename, string $contentType): Response
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'tpl-download-');
        if ($tempFile === false) {
            throw new \RuntimeException('无法创建模板下载文件');
        }

        file_put_contents($tempFile, $content);

        return download($tempFile, $filename)->header([
            'Content-Type' => $contentType,
        ]);
    }
}
