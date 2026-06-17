INSERT INTO `exam_admin_users` (`username`, `password`, `name`, `role_code`, `status`)
VALUES (
  'admin',
  '$2y$10$mvRB5CNjPXcmxqhpbGgJbu4h4H1DAqB7bwCMENuUy9xEO8sEpoFpO',
  '系统管理员',
  'admin',
  1
)
ON DUPLICATE KEY UPDATE
  `password` = VALUES(`password`),
  `name` = VALUES(`name`),
  `role_code` = VALUES(`role_code`),
  `status` = VALUES(`status`);
