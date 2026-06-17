INSERT INTO `exam_admin_roles` (`code`, `name`, `status`)
VALUES
  ('admin', '系统管理员', 1),
  ('teacher', '教师', 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `status` = VALUES(`status`);

INSERT INTO `exam_admin_permissions` (`code`, `name`, `path`, `icon`, `menu_visible`, `sort`, `status`)
VALUES
  ('dashboard.view', '工作台', '/', 'House', 1, 10, 1),
  ('user.manage', '用户管理', '/users', 'UserFilled', 1, 15, 1),
  ('permission.manage', '权限管理', '/permissions', 'Lock', 1, 18, 1),
  ('audit.view', '审计中心', '/audits', 'Notebook', 1, 19, 1),
  ('system.settings.view', '系统设置', '/system/settings', 'Setting', 1, 20, 1),
  ('student.manage', '学生管理', '/students', 'Avatar', 1, 30, 1),
  ('student.group.manage', '学生分组', '/student-groups', 'Files', 1, 40, 1),
  ('paper.manage', '试卷管理', '/papers', 'Document', 1, 50, 1),
  ('question.manage', '试题管理', '/questions', 'Tickets', 1, 60, 1),
  ('question.category.manage', '试题分类管理', '/question-categories', 'Collection', 1, 70, 1),
  ('exam.manage', '考试管理', '/exams', 'Calendar', 1, 80, 1),
  ('marking.manage', '成绩管理', '/marking', 'Checked', 1, 90, 1),
  ('teacher.manage', '教师管理', '/users', 'User', 0, 100, 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `path` = VALUES(`path`),
  `icon` = VALUES(`icon`),
  `menu_visible` = VALUES(`menu_visible`),
  `sort` = VALUES(`sort`),
  `status` = VALUES(`status`);

INSERT INTO `exam_admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `exam_admin_roles` r
JOIN `exam_admin_permissions` p
WHERE r.code = 'admin'
ON DUPLICATE KEY UPDATE `permission_id` = VALUES(`permission_id`);

INSERT INTO `exam_admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `exam_admin_roles` r
JOIN `exam_admin_permissions` p
WHERE r.code = 'teacher'
  AND p.code IN ('dashboard.view', 'paper.manage', 'question.manage', 'question.category.manage', 'exam.manage')
ON DUPLICATE KEY UPDATE `permission_id` = VALUES(`permission_id`);
