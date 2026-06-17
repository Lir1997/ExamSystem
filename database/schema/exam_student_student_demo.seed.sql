INSERT INTO `exam_exam_student_students` (`exam_id`, `student_id`)
VALUES
  (1, 1),
  (1, 2),
  (2, 65),
  (3, 129)
ON DUPLICATE KEY UPDATE `student_id` = VALUES(`student_id`);
