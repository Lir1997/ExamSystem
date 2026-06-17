<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use think\facade\Route;

Route::get('think', function () {
    return 'hello,ThinkPHP8!';
});

Route::get('/', 'Index/index');
Route::get('hello/:name', 'index/hello');

Route::group('api/admin', function () {
    Route::post('auth/login', 'admin.AuthController/login');
    Route::get('auth/profile', 'admin.AuthController/profile')->middleware('admin.auth');
    Route::get('users/roles', 'admin.UserController/roles')->middleware('admin.auth');
    Route::post('users/reset-password', 'admin.UserController/resetPassword')->middleware('admin.auth');
    Route::post('users/assign-scopes', 'admin.UserController/assignScopes')->middleware('admin.auth');
    Route::get('permissions/roles', 'admin.PermissionController/roles')->middleware('admin.auth');
    Route::post('permissions/roles/save', 'admin.PermissionController/saveRole')->middleware('admin.auth');
    Route::post('permissions/roles/:roleId', 'admin.PermissionController/saveRolePermissions')->middleware('admin.auth');
    Route::delete('permissions/roles/:roleId', 'admin.PermissionController/deleteRole')->middleware('admin.auth');
    Route::get('audits/summary', 'admin.AuditController/summary')->middleware('admin.auth');
    Route::get('users', 'admin.UserController/index')->middleware('admin.auth');
    Route::post('users/save', 'admin.UserController/save')->middleware('admin.auth');
    Route::delete('users/:id', 'admin.UserController/delete')->middleware('admin.auth');
    Route::get('permissions', 'admin.PermissionController/index')->middleware('admin.auth');
    Route::get('audits', 'admin.AuditController/index')->middleware('admin.auth');

    Route::get('system/settings', 'admin.SystemController/settings')
        ->middleware('admin.auth');
    Route::post('system/settings/save', 'admin.SystemController/save')
        ->middleware('admin.auth');
    Route::post('system/settings/clear-sessions', 'admin.SystemController/clearSessions')
        ->middleware('admin.auth');
    Route::post('system/settings/exam-timeout-task/regenerate', 'admin.SystemController/regenerateExamTimeoutTask')
        ->middleware('admin.auth');

    Route::post('students/import', 'admin.StudentController/import')
        ->middleware('admin.auth');
    Route::post('students/import-preview', 'admin.StudentController/importPreview')
        ->middleware('admin.auth');
    Route::post('students/import-commit', 'admin.StudentController/importCommit')
        ->middleware('admin.auth');
    Route::get('import-templates/students', 'admin.StudentController/importTemplate')
        ->middleware('admin.auth');
    Route::get('students', 'admin.StudentController/index')
        ->middleware('admin.auth');
    Route::post('students/save', 'admin.StudentController/save')
        ->middleware('admin.auth');
    Route::delete('students/:id', 'admin.StudentController/delete')
        ->middleware('admin.auth');
    Route::post('students/reset-password', 'admin.StudentController/resetPassword')
        ->middleware('admin.auth');

    Route::get('student-groups', 'admin.StudentGroupController/index')
        ->middleware('admin.auth');
    Route::post('student-groups/save', 'admin.StudentGroupController/save')
        ->middleware('admin.auth');
    Route::delete('student-groups/:id', 'admin.StudentGroupController/delete')
        ->middleware('admin.auth');

    Route::get('papers', 'admin.PaperController/index')
        ->middleware('admin.auth');
    Route::post('papers/save', 'admin.PaperController/save')
        ->middleware('admin.auth');
    Route::delete('papers/:id', 'admin.PaperController/delete')
        ->middleware('admin.auth');

    Route::get('import-templates/questions', 'admin.QuestionController/importTemplate')
        ->middleware('admin.auth');
    Route::post('questions/import-preview', 'admin.QuestionController/importPreview')
        ->middleware('admin.auth');
    Route::post('questions/import-commit', 'admin.QuestionController/importCommit')
        ->middleware('admin.auth');
    Route::get('questions', 'admin.QuestionController/index')
        ->middleware('admin.auth');
    Route::post('questions/save', 'admin.QuestionController/save')
        ->middleware('admin.auth');
    Route::delete('questions/:id', 'admin.QuestionController/delete')
        ->middleware('admin.auth');
    Route::get('question-categories', 'admin.QuestionCategoryController/index')
        ->middleware('admin.auth');
    Route::post('question-categories/save', 'admin.QuestionCategoryController/save')
        ->middleware('admin.auth');
    Route::delete('question-categories/:id', 'admin.QuestionCategoryController/delete')
        ->middleware('admin.auth');
    Route::post('upload/image', 'admin.UploadController/image')
        ->middleware('admin.auth');
    Route::post('upload/file', 'admin.UploadController/file')
        ->middleware('admin.auth');

    Route::get('exams/:id/monitor-credentials', 'admin.MonitorController/credentials')
        ->middleware('admin.auth');
    Route::post('exams/:id/monitor-credentials/regenerate', 'admin.MonitorController/regenerateCredentials')
        ->middleware('admin.auth');
    Route::post('exams/:id/monitor-bridge', 'admin.MonitorController/bridge')
        ->middleware('admin.auth');
    Route::get('exams', 'admin.ExamController/index')
        ->middleware('admin.auth');
    Route::get('exams/:id/scope', 'admin.ExamController/scope')
        ->middleware('admin.auth');
    Route::post('exams/save', 'admin.ExamController/save')
        ->middleware('admin.auth');
    Route::post('exams/assign-groups', 'admin.ExamController/assignGroups')
        ->middleware('admin.auth');
    Route::delete('exams/:id', 'admin.ExamController/delete')
        ->middleware('admin.auth');

    Route::get('marking/results-export', 'admin.MarkingController/export')
        ->middleware('admin.auth');
    Route::get('marking/results/:id', 'admin.MarkingController/detail')
        ->middleware('admin.auth');
    Route::post('marking/results/:id/review', 'admin.MarkingController/review')
        ->middleware('admin.auth');
    Route::get('marking/results', 'admin.MarkingController/index')
        ->middleware('admin.auth');

    Route::get('teachers', 'admin.TeacherController/index')
        ->middleware('admin.auth');
    Route::post('teachers/save', 'admin.TeacherController/save')
        ->middleware('admin.auth');
    Route::post('teachers/assign-scopes', 'admin.TeacherController/assignScopes')
        ->middleware('admin.auth');
    Route::delete('teachers/:id', 'admin.TeacherController/delete')
        ->middleware('admin.auth');
});

Route::group('api/task', function () {
    Route::get('exam/finalize-timeouts', 'task.ExamTaskController/finalizeTimeouts');
});

Route::group('api/exam', function () {
    Route::get('auth/settings', 'exam.AuthController/settings');
    Route::post('auth/login', 'exam.AuthController/login');
    Route::get('auth/profile', 'exam.AuthController/profile')->middleware('student.auth');
    Route::get('papers/:examId/session', 'exam.PaperController/session')->middleware('student.auth');
    Route::get('papers/:examId', 'exam.PaperController/detail')->middleware('student.auth');
    Route::post('papers/:examId/integrity/focus-event', 'exam.IntegrityController/reportFocusEvent')->middleware('student.auth');
    Route::post('papers/:examId/answers/save', 'exam.AnswerController/save')->middleware('student.auth');
    Route::post('papers/:examId/submit', 'exam.AnswerController/submit')->middleware('student.auth');
    Route::get('results/:examId', 'exam.ResultController/detail')->middleware('student.auth');
    Route::get('operation/:questionId', 'exam.OperationController/detail')->middleware('student.auth');
    Route::get('operation/:questionId/download-meta', 'exam.OperationController/downloadMeta')->middleware('student.auth');
    Route::post('operation/:questionId/upload-result', 'exam.OperationController/uploadResult')->middleware('student.auth');
});

Route::group('api/monitor', function () {
    Route::post('auth/login', 'monitor.AuthController/login');
    Route::get('auth/bridge', 'monitor.AuthController/bridge');
    Route::get('auth/profile', 'monitor.AuthController/profile')->middleware('monitor.auth');
    Route::post('operations/extend-time', 'monitor.OperationController/extendTime')->middleware('monitor.auth');
    Route::post('operations/bulk-extend-time', 'monitor.OperationController/bulkExtendTime')->middleware('monitor.auth');
    Route::post('operations/force-submit', 'monitor.OperationController/forceSubmit')->middleware('monitor.auth');
    Route::post('operations/bulk-force-submit', 'monitor.OperationController/bulkForceSubmit')->middleware('monitor.auth');
});

Route::get('monitor', 'monitor.PageController/index');
Route::get('monitor/:slug', 'monitor.PageController/index');
