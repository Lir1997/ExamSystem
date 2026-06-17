INSERT INTO `exam_student_groups` (`id`, `code`, `name`, `status`)
VALUES
  (1, 'group-a', '一班', 1),
  (2, 'group-b', '二班', 1)
ON DUPLICATE KEY UPDATE
  `code` = VALUES(`code`),
  `name` = VALUES(`name`),
  `status` = VALUES(`status`);

INSERT INTO `exam_students` (`id`, `username`, `password`, `student_no`, `id_card`, `name`, `status`)
VALUES
  (1, 'student01', '$2y$10$mvRB5CNjPXcmxqhpbGgJbu4h4H1DAqB7bwCMENuUy9xEO8sEpoFpO', 'S2026001', '370101200001011234', '张三', 1),
  (2, 'student02', '$2y$10$mvRB5CNjPXcmxqhpbGgJbu4h4H1DAqB7bwCMENuUy9xEO8sEpoFpO', 'S2026002', '370101200002021235', '李四', 1),
  (3, 'student03', '$2y$10$mvRB5CNjPXcmxqhpbGgJbu4h4H1DAqB7bwCMENuUy9xEO8sEpoFpO', 'S2026003', '370101200003031236', '王五', 1)
ON DUPLICATE KEY UPDATE
  `username` = VALUES(`username`),
  `password` = VALUES(`password`),
  `student_no` = VALUES(`student_no`),
  `id_card` = VALUES(`id_card`),
  `name` = VALUES(`name`),
  `status` = VALUES(`status`);

INSERT INTO `exam_student_group_members` (`student_group_id`, `student_id`)
VALUES
  (1, 1),
  (1, 2),
  (2, 3)
ON DUPLICATE KEY UPDATE `student_id` = VALUES(`student_id`);
