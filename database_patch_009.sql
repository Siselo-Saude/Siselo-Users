USE intranet_cadh;

SET @has_appointment_modality := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patient_appointments'
    AND COLUMN_NAME = 'modality'
);
SET @sql_add_appointment_modality := IF(
  @has_appointment_modality = 0,
  'ALTER TABLE patient_appointments ADD COLUMN modality VARCHAR(40) NULL AFTER specialty',
  'SELECT 1'
);
PREPARE stmt_add_appointment_modality FROM @sql_add_appointment_modality;
EXECUTE stmt_add_appointment_modality;
DEALLOCATE PREPARE stmt_add_appointment_modality;

SET @has_appointment_professional := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patient_appointments'
    AND COLUMN_NAME = 'professional'
);
SET @sql_add_appointment_professional := IF(
  @has_appointment_professional = 0,
  'ALTER TABLE patient_appointments ADD COLUMN professional VARCHAR(160) NULL AFTER modality',
  'SELECT 1'
);
PREPARE stmt_add_appointment_professional FROM @sql_add_appointment_professional;
EXECUTE stmt_add_appointment_professional;
DEALLOCATE PREPARE stmt_add_appointment_professional;

SET @has_appointment_team := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patient_appointments'
    AND COLUMN_NAME = 'team'
);
SET @sql_add_appointment_team := IF(
  @has_appointment_team = 0,
  'ALTER TABLE patient_appointments ADD COLUMN team VARCHAR(160) NULL AFTER professional',
  'SELECT 1'
);
PREPARE stmt_add_appointment_team FROM @sql_add_appointment_team;
EXECUTE stmt_add_appointment_team;
DEALLOCATE PREPARE stmt_add_appointment_team;

SET @has_appointment_priority := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patient_appointments'
    AND COLUMN_NAME = 'priority'
);
SET @sql_add_appointment_priority := IF(
  @has_appointment_priority = 0,
  'ALTER TABLE patient_appointments ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT ''nao'' AFTER team',
  'SELECT 1'
);
PREPARE stmt_add_appointment_priority FROM @sql_add_appointment_priority;
EXECUTE stmt_add_appointment_priority;
DEALLOCATE PREPARE stmt_add_appointment_priority;
