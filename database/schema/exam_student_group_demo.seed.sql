INSERT INTO `exam_exam_student_groups` (`exam_id`, `student_group_id`)
VALUES
  (1, 1),
  (2, 2),
  (3, 2)
ON DUPLICATE KEY UPDATE `student_group_id` = VALUES(`student_group_id`);
