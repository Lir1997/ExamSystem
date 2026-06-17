<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;

class ExamScopeService
{
    public function groupRefsForExamIds(array $examIds): array
    {
        $examIds = $this->normalizedIds($examIds);
        if ($examIds === []) {
            return [];
        }

        $rows = Db::name('exam_student_groups')
            ->alias('eg')
            ->join('student_groups g', 'g.id = eg.student_group_id')
            ->whereIn('eg.exam_id', $examIds)
            ->field(['eg.exam_id', 'g.id' => 'group_id', 'g.name' => 'group_name', 'g.code' => 'group_code'])
            ->order('eg.exam_id asc, g.id asc')
            ->select()
            ->toArray();

        $groupMap = [];
        foreach ($rows as $row) {
            $examId = (int) ($row['exam_id'] ?? 0);
            if ($examId <= 0) {
                continue;
            }

            $groupMap[$examId][] = [
                'id' => (int) ($row['group_id'] ?? 0),
                'name' => (string) ($row['group_name'] ?? ''),
                'code' => (string) ($row['group_code'] ?? ''),
            ];
        }

        return $groupMap;
    }

    public function studentRefsForExamIds(array $examIds): array
    {
        $examIds = $this->normalizedIds($examIds);
        if ($examIds === []) {
            return [];
        }

        $rows = Db::name('exam_student_students')
            ->alias('es')
            ->join('students s', 's.id = es.student_id')
            ->whereIn('es.exam_id', $examIds)
            ->field([
                'es.exam_id',
                's.id' => 'student_id',
                's.username',
                's.student_no',
                's.name',
                's.status',
            ])
            ->order('es.exam_id asc, s.id asc')
            ->select()
            ->toArray();

        $studentMap = [];
        foreach ($rows as $row) {
            $examId = (int) ($row['exam_id'] ?? 0);
            if ($examId <= 0) {
                continue;
            }

            $studentMap[$examId][] = [
                'id' => (int) ($row['student_id'] ?? 0),
                'username' => (string) ($row['username'] ?? ''),
                'student_no' => (string) ($row['student_no'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'status' => (int) ($row['status'] ?? 0),
            ];
        }

        return $studentMap;
    }

    public function examIdsForStudent(int $studentId): array
    {
        if ($studentId <= 0) {
            return [];
        }

        $directExamIds = Db::name('exam_student_students')
            ->where('student_id', $studentId)
            ->column('exam_id');

        $groupIds = Db::name('student_group_members')
            ->where('student_id', $studentId)
            ->column('student_group_id');

        $groupExamIds = [];
        if ($groupIds !== []) {
            $groupExamIds = Db::name('exam_student_groups')
                ->whereIn('student_group_id', $this->normalizedIds($groupIds))
                ->column('exam_id');
        }

        return $this->normalizedIds(array_merge($directExamIds, $groupExamIds));
    }

    public function studentIdsForExam(int $examId): array
    {
        if ($examId <= 0) {
            return [];
        }

        $directStudentIds = Db::name('exam_student_students')
            ->where('exam_id', $examId)
            ->column('student_id');

        $groupIds = Db::name('exam_student_groups')
            ->where('exam_id', $examId)
            ->column('student_group_id');

        $groupStudentIds = [];
        if ($groupIds !== []) {
            $groupStudentIds = Db::name('student_group_members')
                ->whereIn('student_group_id', $this->normalizedIds($groupIds))
                ->column('student_id');
        }

        return $this->normalizedIds(array_merge($directStudentIds, $groupStudentIds));
    }

    public function scopePayload(int $examId): array
    {
        $groups = $this->groupRefsForExamIds([$examId])[$examId] ?? [];
        $students = $this->studentRefsForExamIds([$examId])[$examId] ?? [];

        return [
            'exam_id' => $examId,
            'group_ids' => array_values(array_map(static fn (array $item): int => (int) $item['id'], $groups)),
            'student_ids' => array_values(array_map(static fn (array $item): int => (int) $item['id'], $students)),
            'groups' => $groups,
            'students' => $students,
        ];
    }

    public function existingGroupIds(array $groupIds): array
    {
        $groupIds = $this->normalizedIds($groupIds);
        if ($groupIds === []) {
            return [];
        }

        return $this->normalizedIds(
            Db::name('student_groups')
                ->whereIn('id', $groupIds)
                ->column('id')
        );
    }

    public function existingStudentIds(array $studentIds): array
    {
        $studentIds = $this->normalizedIds($studentIds);
        if ($studentIds === []) {
            return [];
        }

        return $this->normalizedIds(
            Db::name('students')
                ->whereIn('id', $studentIds)
                ->column('id')
        );
    }

    protected function normalizedIds(array $ids): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): int => (int) $value,
            $ids
        ), static fn (int $value): bool => $value > 0)));

        sort($normalized);

        return $normalized;
    }
}
