USE intranet_cadh;

-- Contratos do fluxo MVP: condicao de entrada e separacao explicita dos fluxos.
SET @has_entry_condition := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'entry_condition'
);
SET @sql := IF(
  @has_entry_condition = 0,
  'ALTER TABLE patients ADD COLUMN entry_condition VARCHAR(40) NULL AFTER chronic_conditions',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_transition_flow_type := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transitions' AND COLUMN_NAME = 'flow_type'
);
SET @sql := IF(
  @has_transition_flow_type = 0,
  'ALTER TABLE transitions ADD COLUMN flow_type VARCHAR(40) NOT NULL DEFAULT ''care_transition'' AFTER patient_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_transition_destination_ubs := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transitions' AND COLUMN_NAME = 'destination_ubs'
);
SET @sql := IF(
  @has_transition_destination_ubs = 0,
  'ALTER TABLE transitions ADD COLUMN destination_ubs VARCHAR(120) NULL AFTER to_service',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_transition_origin_ubs := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transitions' AND COLUMN_NAME = 'origin_ubs'
);
SET @sql := IF(
  @has_transition_origin_ubs = 0,
  'ALTER TABLE transitions ADD COLUMN origin_ubs VARCHAR(120) NULL AFTER to_service',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_transition_origin_team := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transitions' AND COLUMN_NAME = 'origin_team'
);
SET @sql := IF(
  @has_transition_origin_team = 0,
  'ALTER TABLE transitions ADD COLUMN origin_team VARCHAR(120) NULL AFTER origin_ubs',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_transition_destination_team := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transitions' AND COLUMN_NAME = 'destination_team'
);
SET @sql := IF(
  @has_transition_destination_team = 0,
  'ALTER TABLE transitions ADD COLUMN destination_team VARCHAR(120) NULL AFTER destination_ubs',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_transition_entry_condition := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transitions' AND COLUMN_NAME = 'entry_condition'
);
SET @sql := IF(
  @has_transition_entry_condition = 0,
  'ALTER TABLE transitions ADD COLUMN entry_condition VARCHAR(40) NULL AFTER destination_team',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_transition_justification := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transitions' AND COLUMN_NAME = 'justification'
);
SET @sql := IF(
  @has_transition_justification = 0,
  'ALTER TABLE transitions ADD COLUMN justification TEXT NULL AFTER entry_condition',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE transitions
SET flow_type = CASE
  WHEN UPPER(TRIM(to_service)) LIKE 'CADH%' THEN 'care_sharing'
  ELSE 'care_transition'
END
WHERE flow_type IS NULL OR flow_type = '' OR flow_type = 'care_transition';

UPDATE transitions t
JOIN patients p ON p.id = t.patient_id
SET
  t.origin_ubs = COALESCE(t.origin_ubs, p.ubs_ref, t.from_service),
  t.origin_team = COALESCE(t.origin_team, p.team_ref),
  t.entry_condition = COALESCE(t.entry_condition, p.entry_condition),
  t.justification = COALESCE(t.justification, t.notes),
  t.status = CASE
    WHEN t.status = 'pendente' THEN 'aguardando_agendamento'
    WHEN t.status = 'em_andamento' THEN 'recebido'
    ELSE t.status
  END
WHERE t.flow_type = 'care_sharing';

-- Agenda operacional vinculada ao compartilhamento e com marcos de atendimento.
SET @has_appointment_sharing_id := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_appointments' AND COLUMN_NAME = 'sharing_transition_id'
);
SET @sql := IF(
  @has_appointment_sharing_id = 0,
  'ALTER TABLE patient_appointments ADD COLUMN sharing_transition_id INT NULL AFTER patient_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_appointment_shift := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_appointments' AND COLUMN_NAME = 'shift'
);
SET @sql := IF(
  @has_appointment_shift = 0,
  'ALTER TABLE patient_appointments ADD COLUMN shift VARCHAR(20) NULL AFTER scheduled_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_appointment_arrived_at := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_appointments' AND COLUMN_NAME = 'arrived_at'
);
SET @sql := IF(
  @has_appointment_arrived_at = 0,
  'ALTER TABLE patient_appointments ADD COLUMN arrived_at DATETIME NULL AFTER status',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_appointment_started_at := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_appointments' AND COLUMN_NAME = 'started_at'
);
SET @sql := IF(
  @has_appointment_started_at = 0,
  'ALTER TABLE patient_appointments ADD COLUMN started_at DATETIME NULL AFTER arrived_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_appointment_completed_at := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_appointments' AND COLUMN_NAME = 'completed_at'
);
SET @sql := IF(
  @has_appointment_completed_at = 0,
  'ALTER TABLE patient_appointments ADD COLUMN completed_at DATETIME NULL AFTER started_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_appointment_sharing_fk := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_appointments'
    AND CONSTRAINT_NAME = 'fk_appointments_sharing_transition'
);
SET @sql := IF(
  @has_appointment_sharing_fk = 0,
  'ALTER TABLE patient_appointments ADD CONSTRAINT fk_appointments_sharing_transition FOREIGN KEY (sharing_transition_id) REFERENCES transitions(id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Acompanhamento UBS persistente usa o registro clinico generico com vinculo opcional a transicao.
SET @has_encounter_transition_id := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'encounters' AND COLUMN_NAME = 'transition_id'
);
SET @sql := IF(
  @has_encounter_transition_id = 0,
  'ALTER TABLE encounters ADD COLUMN transition_id INT NULL AFTER follow_up_appointment_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_encounter_transition_fk := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'encounters'
    AND CONSTRAINT_NAME = 'fk_encounters_transition'
);
SET @sql := IF(
  @has_encounter_transition_fk = 0,
  'ALTER TABLE encounters ADD CONSTRAINT fk_encounters_transition FOREIGN KEY (transition_id) REFERENCES transitions(id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Estado explicito do ciclo do plano e data de revisao, mantendo end_date por compatibilidade.
SET @has_care_plan_state := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'care_plans' AND COLUMN_NAME = 'state'
);
SET @sql := IF(
  @has_care_plan_state = 0,
  'ALTER TABLE care_plans ADD COLUMN state VARCHAR(30) NOT NULL DEFAULT ''em_elaboracao'' AFTER patient_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_care_plan_review_date := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'care_plans' AND COLUMN_NAME = 'review_date'
);
SET @sql := IF(
  @has_care_plan_review_date = 0,
  'ALTER TABLE care_plans ADD COLUMN review_date DATE NULL AFTER end_date',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE care_plans SET review_date = end_date WHERE review_date IS NULL AND end_date IS NOT NULL;
