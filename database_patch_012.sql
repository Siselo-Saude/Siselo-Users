USE intranet_cadh;

SET @has_patient_emergency_contact_name := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patients'
    AND COLUMN_NAME = 'emergency_contact_name'
);

SET @sql_add_patient_emergency_contact_name := IF(
  @has_patient_emergency_contact_name = 0,
  'ALTER TABLE patients ADD COLUMN emergency_contact_name VARCHAR(180) NULL AFTER emergency_contact',
  'SELECT 1'
);

PREPARE stmt_add_patient_emergency_contact_name FROM @sql_add_patient_emergency_contact_name;
EXECUTE stmt_add_patient_emergency_contact_name;
DEALLOCATE PREPARE stmt_add_patient_emergency_contact_name;

