USE intranet_cadh;

SET @has_risk_classification := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients'
    AND COLUMN_NAME = 'risk_classification'
);
SET @sql := IF(
  @has_risk_classification = 0,
  'ALTER TABLE patients ADD COLUMN risk_classification VARCHAR(30) NOT NULL DEFAULT ''alto_risco'' AFTER status',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_care_status := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients'
    AND COLUMN_NAME = 'care_status'
);
SET @sql := IF(
  @has_care_status = 0,
  'ALTER TABLE patients ADD COLUMN care_status VARCHAR(30) NOT NULL DEFAULT ''recebido'' AFTER risk_classification',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_finalization_reason := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients'
    AND COLUMN_NAME = 'finalization_reason'
);
SET @sql := IF(
  @has_finalization_reason = 0,
  'ALTER TABLE patients ADD COLUMN finalization_reason VARCHAR(40) NULL AFTER care_status',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_finalization_notes := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients'
    AND COLUMN_NAME = 'finalization_notes'
);
SET @sql := IF(
  @has_finalization_notes = 0,
  'ALTER TABLE patients ADD COLUMN finalization_notes TEXT NULL AFTER finalization_reason',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_finalized_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients'
    AND COLUMN_NAME = 'finalized_at'
);
SET @sql := IF(
  @has_finalized_at = 0,
  'ALTER TABLE patients ADD COLUMN finalized_at DATETIME NULL AFTER finalization_notes',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_finalized_by := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients'
    AND COLUMN_NAME = 'finalized_by_user_id'
);
SET @sql := IF(
  @has_finalized_by = 0,
  'ALTER TABLE patients ADD COLUMN finalized_by_user_id INT NULL AFTER finalized_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS patient_appointments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  scheduled_at DATETIME NOT NULL,
  specialty VARCHAR(80) NULL,
  notes TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'agendado',
  created_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  deleted_at DATETIME NULL,
  INDEX idx_appointments_patient (patient_id),
  INDEX idx_appointments_scheduled_at (scheduled_at),
  INDEX idx_appointments_status (status),
  FOREIGN KEY (patient_id) REFERENCES patients(id),
  FOREIGN KEY (created_by_user_id) REFERENCES users(id)
);

INSERT IGNORE INTO permissions (name, description) VALUES
('careflow.view', 'Visualizar fluxo assistencial'),
('careflow.schedule', 'Agendar pacientes'),
('careflow.update', 'Atualizar fluxo assistencial'),
('careflow.finalize', 'Finalizar acompanhamento');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p
WHERE r.name = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.name IN (
  'careflow.view', 'careflow.schedule', 'careflow.update', 'careflow.finalize'
)
WHERE r.name = 'alimentador';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.name = 'careflow.view'
WHERE r.name = 'visualizador';

