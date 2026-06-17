INSERT INTO `exam_exams` (
  `id`,
  `title`,
  `paper_id`,
  `status`,
  `started_at`,
  `ended_at`,
  `attempt_limit`,
  `deadline_strategy`,
  `allow_view_score`,
  `allow_view_paper`,
  `allow_view_analysis`,
  `show_question_score`,
  `show_question_difficulty`,
  `auto_fullscreen`,
  `enable_focus_monitor`,
  `focus_loss_limit`,
  `focus_loss_action`,
  `focus_loss_deduct_score`,
  `created_by`
)
VALUES
  (1, '2026 春季理论考试 A 场', 1, 1, '2026-05-20 09:00:00', '2026-05-20 10:30:00', 1, 'force_close', 1, 0, 0, 1, 1, 1, 0, 0, 0, 'none', 0, 1),
  (2, '2026 春季理论考试 B 场', 2, 1, '2026-05-21 09:00:00', '2026-05-21 10:30:00', 1, 'continue_until_duration', 1, 1, 0, 1, 1, 1, 1, 1, 3, 'force_submit', 0, 1),
  (3, '2026 操作考试演示场', 3, 1, '2026-05-22 14:00:00', '2026-05-22 16:00:00', 1, 'force_close', 0, 0, 0, 0, 0, 1, 1, 1, 2, 'deduct_score', 10, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `paper_id` = VALUES(`paper_id`),
  `status` = VALUES(`status`),
  `started_at` = VALUES(`started_at`),
  `ended_at` = VALUES(`ended_at`),
  `attempt_limit` = VALUES(`attempt_limit`),
  `deadline_strategy` = VALUES(`deadline_strategy`),
  `allow_view_score` = VALUES(`allow_view_score`),
  `allow_view_paper` = VALUES(`allow_view_paper`),
  `allow_view_analysis` = VALUES(`allow_view_analysis`),
  `show_question_score` = VALUES(`show_question_score`),
  `show_question_difficulty` = VALUES(`show_question_difficulty`),
  `auto_fullscreen` = VALUES(`auto_fullscreen`),
  `enable_focus_monitor` = VALUES(`enable_focus_monitor`),
  `focus_loss_limit` = VALUES(`focus_loss_limit`),
  `focus_loss_action` = VALUES(`focus_loss_action`),
  `focus_loss_deduct_score` = VALUES(`focus_loss_deduct_score`),
  `created_by` = VALUES(`created_by`);

INSERT INTO `exam_admin_data_scopes` (`admin_user_id`, `scope_type`, `scope_value`)
SELECT u.id, 'exam', '2'
FROM `exam_admin_users` u
WHERE u.username = 'teacher01'
ON DUPLICATE KEY UPDATE `scope_value` = VALUES(`scope_value`);
