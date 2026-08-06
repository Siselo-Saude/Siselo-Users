USE intranet_cadh;

SET @has_encounter_appointment_id := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'encounters'
    AND COLUMN_NAME = 'appointment_id'
);
SET @sql_add_encounter_appointment_id := IF(
  @has_encounter_appointment_id = 0,
  'ALTER TABLE encounters ADD COLUMN appointment_id INT NULL AFTER patient_id',
  'SELECT 1'
);
PREPARE stmt_add_encounter_appointment_id FROM @sql_add_encounter_appointment_id;
EXECUTE stmt_add_encounter_appointment_id;
DEALLOCATE PREPARE stmt_add_encounter_appointment_id;

SET @has_encounter_schema_version := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'encounters'
    AND COLUMN_NAME = 'schema_version'
);
SET @sql_add_encounter_schema_version := IF(
  @has_encounter_schema_version = 0,
  'ALTER TABLE encounters ADD COLUMN schema_version VARCHAR(20) NOT NULL DEFAULT ''1.0'' AFTER record_type',
  'SELECT 1'
);
PREPARE stmt_add_encounter_schema_version FROM @sql_add_encounter_schema_version;
EXECUTE stmt_add_encounter_schema_version;
DEALLOCATE PREPARE stmt_add_encounter_schema_version;

SET @has_encounter_payload_json := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'encounters'
    AND COLUMN_NAME = 'payload_json'
);
SET @sql_add_encounter_payload_json := IF(
  @has_encounter_payload_json = 0,
  'ALTER TABLE encounters ADD COLUMN payload_json JSON NULL AFTER summary',
  'SELECT 1'
);
PREPARE stmt_add_encounter_payload_json FROM @sql_add_encounter_payload_json;
EXECUTE stmt_add_encounter_payload_json;
DEALLOCATE PREPARE stmt_add_encounter_payload_json;

SET @has_encounter_appointment_index := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'encounters'
    AND INDEX_NAME = 'uniq_encounters_appointment'
);
SET @sql_add_encounter_appointment_index := IF(
  @has_encounter_appointment_index = 0,
  'ALTER TABLE encounters ADD UNIQUE INDEX uniq_encounters_appointment (appointment_id)',
  'SELECT 1'
);
PREPARE stmt_add_encounter_appointment_index FROM @sql_add_encounter_appointment_index;
EXECUTE stmt_add_encounter_appointment_index;
DEALLOCATE PREPARE stmt_add_encounter_appointment_index;

SET @has_encounter_appointment_fk := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'encounters'
    AND CONSTRAINT_NAME = 'fk_encounters_appointment'
);
SET @sql_add_encounter_appointment_fk := IF(
  @has_encounter_appointment_fk = 0,
  'ALTER TABLE encounters ADD CONSTRAINT fk_encounters_appointment FOREIGN KEY (appointment_id) REFERENCES patient_appointments(id)',
  'SELECT 1'
);
PREPARE stmt_add_encounter_appointment_fk FROM @sql_add_encounter_appointment_fk;
EXECUTE stmt_add_encounter_appointment_fk;
DEALLOCATE PREPARE stmt_add_encounter_appointment_fk;

SET @has_appointment_outcome_reason := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patient_appointments'
    AND COLUMN_NAME = 'outcome_reason'
);
SET @sql_add_appointment_outcome_reason := IF(
  @has_appointment_outcome_reason = 0,
  'ALTER TABLE patient_appointments ADD COLUMN outcome_reason VARCHAR(40) NULL AFTER status',
  'SELECT 1'
);
PREPARE stmt_add_appointment_outcome_reason FROM @sql_add_appointment_outcome_reason;
EXECUTE stmt_add_appointment_outcome_reason;
DEALLOCATE PREPARE stmt_add_appointment_outcome_reason;

SET @has_appointment_outcome_notes := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patient_appointments'
    AND COLUMN_NAME = 'outcome_notes'
);
SET @sql_add_appointment_outcome_notes := IF(
  @has_appointment_outcome_notes = 0,
  'ALTER TABLE patient_appointments ADD COLUMN outcome_notes TEXT NULL AFTER outcome_reason',
  'SELECT 1'
);
PREPARE stmt_add_appointment_outcome_notes FROM @sql_add_appointment_outcome_notes;
EXECUTE stmt_add_appointment_outcome_notes;
DEALLOCATE PREPARE stmt_add_appointment_outcome_notes;

SET @has_appointment_resolved_at := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patient_appointments'
    AND COLUMN_NAME = 'resolved_at'
);
SET @sql_add_appointment_resolved_at := IF(
  @has_appointment_resolved_at = 0,
  'ALTER TABLE patient_appointments ADD COLUMN resolved_at DATETIME NULL AFTER outcome_notes',
  'SELECT 1'
);
PREPARE stmt_add_appointment_resolved_at FROM @sql_add_appointment_resolved_at;
EXECUTE stmt_add_appointment_resolved_at;
DEALLOCATE PREPARE stmt_add_appointment_resolved_at;

SET @has_appointment_resolved_by := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patient_appointments'
    AND COLUMN_NAME = 'resolved_by_user_id'
);
SET @sql_add_appointment_resolved_by := IF(
  @has_appointment_resolved_by = 0,
  'ALTER TABLE patient_appointments ADD COLUMN resolved_by_user_id INT NULL AFTER resolved_at',
  'SELECT 1'
);
PREPARE stmt_add_appointment_resolved_by FROM @sql_add_appointment_resolved_by;
EXECUTE stmt_add_appointment_resolved_by;
DEALLOCATE PREPARE stmt_add_appointment_resolved_by;

SET @has_appointment_resolved_by_fk := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patient_appointments'
    AND CONSTRAINT_NAME = 'fk_appointments_resolved_by'
);
SET @sql_add_appointment_resolved_by_fk := IF(
  @has_appointment_resolved_by_fk = 0,
  'ALTER TABLE patient_appointments ADD CONSTRAINT fk_appointments_resolved_by FOREIGN KEY (resolved_by_user_id) REFERENCES users(id)',
  'SELECT 1'
);
PREPARE stmt_add_appointment_resolved_by_fk FROM @sql_add_appointment_resolved_by_fk;
EXECUTE stmt_add_appointment_resolved_by_fk;
DEALLOCATE PREPARE stmt_add_appointment_resolved_by_fk;
