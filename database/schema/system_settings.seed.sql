INSERT INTO `exam_system_settings` (`setting_key`, `setting_value`)
VALUES
  ('site_name', '在线考试系统'),
  ('auth_mode', 'bearer-token'),
  ('frontend_admin_path', '/admin#/'),
  ('frontend_exam_path', '/exam#/'),
  ('student_login_mode', 'username_password'),
  ('student_default_password', 'student123')
ON DUPLICATE KEY UPDATE
  `setting_value` = VALUES(`setting_value`);
