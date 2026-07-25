USE intranet_cadh;

SET @has_must_change_password := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'must_change_password'
);

SET @sql_add_must_change_password := IF(
  @has_must_change_password = 0,
  'ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1'
);

PREPARE stmt_add_must_change_password FROM @sql_add_must_change_password;
EXECUTE stmt_add_must_change_password;
DEALLOCATE PREPARE stmt_add_must_change_password;

SET @has_force_password_change_on_login := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'force_password_change_on_login'
);

SET @sql_drop_force_password_change_on_login := IF(
  @has_force_password_change_on_login > 0,
  'ALTER TABLE users DROP COLUMN force_password_change_on_login',
  'SELECT 1'
);

PREPARE stmt_drop_force_password_change_on_login FROM @sql_drop_force_password_change_on_login;
EXECUTE stmt_drop_force_password_change_on_login;
DEALLOCATE PREPARE stmt_drop_force_password_change_on_login;

SET @has_user_type := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'user_type'
);

SET @sql_add_user_type := IF(
  @has_user_type = 0,
  'ALTER TABLE users ADD COLUMN user_type VARCHAR(10) NULL AFTER must_change_password',
  'SELECT 1'
);

PREPARE stmt_add_user_type FROM @sql_add_user_type;
EXECUTE stmt_add_user_type;
DEALLOCATE PREPARE stmt_add_user_type;

SET @has_specialty := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'specialty'
);

SET @sql_add_specialty := IF(
  @has_specialty = 0,
  'ALTER TABLE users ADD COLUMN specialty VARCHAR(80) NULL AFTER user_type',
  'SELECT 1'
);

PREPARE stmt_add_specialty FROM @sql_add_specialty;
EXECUTE stmt_add_specialty;
DEALLOCATE PREPARE stmt_add_specialty;

UPDATE users
SET
  password_hash = '$2y$10$hmCr8lV/O.MLFyFJSpmyiOmM6xUVpzIHSy5kPTQOOhmQGQhexVOV2',
  is_active = 1,
  must_change_password = 0
WHERE email = 'admin@local';

SET @has_patient_email := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patients'
    AND COLUMN_NAME = 'email'
);

SET @sql_add_patient_email := IF(
  @has_patient_email = 0,
  'ALTER TABLE patients ADD COLUMN email VARCHAR(190) NULL AFTER address',
  'SELECT 1'
);

PREPARE stmt_add_patient_email FROM @sql_add_patient_email;
EXECUTE stmt_add_patient_email;
DEALLOCATE PREPARE stmt_add_patient_email;

SET @has_patient_emergency_contact := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patients'
    AND COLUMN_NAME = 'emergency_contact'
);

SET @sql_add_patient_emergency_contact := IF(
  @has_patient_emergency_contact = 0,
  'ALTER TABLE patients ADD COLUMN emergency_contact VARCHAR(190) NULL AFTER email',
  'SELECT 1'
);

PREPARE stmt_add_patient_emergency_contact FROM @sql_add_patient_emergency_contact;
EXECUTE stmt_add_patient_emergency_contact;
DEALLOCATE PREPARE stmt_add_patient_emergency_contact;

SET @has_patient_health_insurance := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patients'
    AND COLUMN_NAME = 'health_insurance'
);

SET @sql_add_patient_health_insurance := IF(
  @has_patient_health_insurance = 0,
  'ALTER TABLE patients ADD COLUMN health_insurance VARCHAR(160) NULL AFTER emergency_contact',
  'SELECT 1'
);

PREPARE stmt_add_patient_health_insurance FROM @sql_add_patient_health_insurance;
EXECUTE stmt_add_patient_health_insurance;
DEALLOCATE PREPARE stmt_add_patient_health_insurance;

SET @has_patient_blood_type := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patients'
    AND COLUMN_NAME = 'blood_type'
);

SET @sql_add_patient_blood_type := IF(
  @has_patient_blood_type = 0,
  'ALTER TABLE patients ADD COLUMN blood_type VARCHAR(5) NULL AFTER health_insurance',
  'SELECT 1'
);

PREPARE stmt_add_patient_blood_type FROM @sql_add_patient_blood_type;
EXECUTE stmt_add_patient_blood_type;
DEALLOCATE PREPARE stmt_add_patient_blood_type;

SET @has_patient_allergies := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patients'
    AND COLUMN_NAME = 'allergies'
);

SET @sql_add_patient_allergies := IF(
  @has_patient_allergies = 0,
  'ALTER TABLE patients ADD COLUMN allergies TEXT NULL AFTER blood_type',
  'SELECT 1'
);

PREPARE stmt_add_patient_allergies FROM @sql_add_patient_allergies;
EXECUTE stmt_add_patient_allergies;
DEALLOCATE PREPARE stmt_add_patient_allergies;

SET @has_patient_chronic_conditions := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patients'
    AND COLUMN_NAME = 'chronic_conditions'
);

SET @sql_add_patient_chronic_conditions := IF(
  @has_patient_chronic_conditions = 0,
  'ALTER TABLE patients ADD COLUMN chronic_conditions TEXT NULL AFTER allergies',
  'SELECT 1'
);

PREPARE stmt_add_patient_chronic_conditions FROM @sql_add_patient_chronic_conditions;
EXECUTE stmt_add_patient_chronic_conditions;
DEALLOCATE PREPARE stmt_add_patient_chronic_conditions;

SET @has_patient_status := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patients'
    AND COLUMN_NAME = 'status'
);

SET @sql_add_patient_status := IF(
  @has_patient_status = 0,
  'ALTER TABLE patients ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT ''ativo'' AFTER chronic_conditions',
  'SELECT 1'
);

PREPARE stmt_add_patient_status FROM @sql_add_patient_status;
EXECUTE stmt_add_patient_status;
DEALLOCATE PREPARE stmt_add_patient_status;

CREATE TABLE IF NOT EXISTS encounters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  encounter_date DATE NOT NULL,
  specialty VARCHAR(80) NOT NULL,
  professional_user_id INT NULL,
  summary TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  deleted_at DATETIME NULL,
  FOREIGN KEY (patient_id) REFERENCES patients(id),
  FOREIGN KEY (professional_user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS transitions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  transition_date DATE NOT NULL,
  from_service VARCHAR(120) NULL,
  to_service VARCHAR(120) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'pendente',
  notes TEXT NULL,
  created_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  deleted_at DATETIME NULL,
  FOREIGN KEY (patient_id) REFERENCES patients(id),
  FOREIGN KEY (created_by_user_id) REFERENCES users(id)
);

INSERT IGNORE INTO permissions (name, description) VALUES
('encounters.view', 'Visualizar atendimentos'),
('encounters.create', 'Criar atendimentos'),
('encounters.update', 'Editar atendimentos'),
('encounters.delete', 'Apagar atendimentos (soft delete)'),
('encounters.restore', 'Restaurar atendimentos'),
('transitions.view', 'Visualizar encaminhamentos'),
('transitions.create', 'Criar encaminhamentos'),
('transitions.update', 'Editar encaminhamentos'),
('transitions.delete', 'Apagar encaminhamentos (soft delete)'),
('transitions.restore', 'Restaurar encaminhamentos');

-- admin: tudo
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.name='admin';

-- alimentador: view/create/update (sem delete/restore)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.name IN (
  'encounters.view','encounters.create','encounters.update',
  'transitions.view','transitions.create','transitions.update',
  'careplans.view','careplans.create','careplans.update'
)
WHERE r.name='alimentador';

-- visualizador: view
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.name IN ('encounters.view','transitions.view','careplans.view')
WHERE r.name='visualizador';
