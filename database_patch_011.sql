USE intranet_cadh;

SET @has_follow_up_appointment_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'encounters'
    AND COLUMN_NAME = 'follow_up_appointment_id'
);
SET @sql_add_follow_up_appointment_id := IF(
  @has_follow_up_appointment_id = 0,
  'ALTER TABLE encounters ADD COLUMN follow_up_appointment_id INT NULL AFTER appointment_id',
  'SELECT 1'
);
PREPARE stmt_add_follow_up_appointment_id FROM @sql_add_follow_up_appointment_id;
EXECUTE stmt_add_follow_up_appointment_id;
DEALLOCATE PREPARE stmt_add_follow_up_appointment_id;

SET @has_follow_up_appointment_index := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'encounters'
    AND INDEX_NAME = 'uniq_encounters_follow_up_appointment'
);
SET @sql_add_follow_up_appointment_index := IF(
  @has_follow_up_appointment_index = 0,
  'ALTER TABLE encounters ADD UNIQUE INDEX uniq_encounters_follow_up_appointment (follow_up_appointment_id)',
  'SELECT 1'
);
PREPARE stmt_add_follow_up_appointment_index FROM @sql_add_follow_up_appointment_index;
EXECUTE stmt_add_follow_up_appointment_index;
DEALLOCATE PREPARE stmt_add_follow_up_appointment_index;

SET @has_follow_up_appointment_fk := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'encounters'
    AND CONSTRAINT_NAME = 'fk_encounters_follow_up_appointment'
);
SET @sql_add_follow_up_appointment_fk := IF(
  @has_follow_up_appointment_fk = 0,
  'ALTER TABLE encounters ADD CONSTRAINT fk_encounters_follow_up_appointment FOREIGN KEY (follow_up_appointment_id) REFERENCES patient_appointments(id)',
  'SELECT 1'
);
PREPARE stmt_add_follow_up_appointment_fk FROM @sql_add_follow_up_appointment_fk;
EXECUTE stmt_add_follow_up_appointment_fk;
DEALLOCATE PREPARE stmt_add_follow_up_appointment_fk;
