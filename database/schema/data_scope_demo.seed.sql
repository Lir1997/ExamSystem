INSERT INTO `exam_admin_users` (`username`, `password`, `name`, `role_code`, `status`)
VALUES (
  'teacher01',
  '$2y$10$6MHUtHosS2cjEj37KhUzNuXSXOLa5NYpzoe0XrhKGZLaip/2KCjPG',
  '示例教师',
  'teacher',
  1
)
ON DUPLICATE KEY UPDATE
  `password` = VALUES(`password`),
  `name` = VALUES(`name`),
  `role_code` = VALUES(`role_code`),
  `status` = VALUES(`status`);

INSERT INTO `exam_papers` (`id`, `title`, `structure_code`, `status`, `created_by`)
VALUES
  (1, '2026 春季理论考试 A 卷', 'theory-a', 1, 1),
  (2, '2026 春季理论考试 B 卷', 'theory-b', 1, 1),
  (3, '2026 操作考试样卷', 'operation-demo', 1, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `structure_code` = VALUES(`structure_code`),
  `status` = VALUES(`status`),
  `created_by` = VALUES(`created_by`);

INSERT INTO `exam_admin_data_scopes` (`admin_user_id`, `scope_type`, `scope_value`)
SELECT u.id, 'paper', '2'
FROM `exam_admin_users` u
WHERE u.username = 'teacher01'
ON DUPLICATE KEY UPDATE `scope_value` = VALUES(`scope_value`);
