USE intranet_cadh;

SET @has_external_provider := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'external_provider'
);

SET @sql_add_external_provider := IF(
  @has_external_provider = 0,
  'ALTER TABLE users ADD COLUMN external_provider VARCHAR(40) NULL AFTER specialty',
  'SELECT 1'
);

PREPARE stmt_add_external_provider FROM @sql_add_external_provider;
EXECUTE stmt_add_external_provider;
DEALLOCATE PREPARE stmt_add_external_provider;

SET @has_external_username := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'external_username'
);

SET @sql_add_external_username := IF(
  @has_external_username = 0,
  'ALTER TABLE users ADD COLUMN external_username VARCHAR(120) NULL AFTER external_provider',
  'SELECT 1'
);

PREPARE stmt_add_external_username FROM @sql_add_external_username;
EXECUTE stmt_add_external_username;
DEALLOCATE PREPARE stmt_add_external_username;

SET @has_external_group := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'external_group'
);

SET @sql_add_external_group := IF(
  @has_external_group = 0,
  'ALTER TABLE users ADD COLUMN external_group VARCHAR(190) NULL AFTER external_username',
  'SELECT 1'
);

PREPARE stmt_add_external_group FROM @sql_add_external_group;
EXECUTE stmt_add_external_group;
DEALLOCATE PREPARE stmt_add_external_group;

SET @has_profile_completed := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'profile_completed'
);

SET @sql_add_profile_completed := IF(
  @has_profile_completed = 0,
  'ALTER TABLE users ADD COLUMN profile_completed TINYINT(1) NOT NULL DEFAULT 1 AFTER external_group',
  'SELECT 1'
);

PREPARE stmt_add_profile_completed FROM @sql_add_profile_completed;
EXECUTE stmt_add_profile_completed;
DEALLOCATE PREPARE stmt_add_profile_completed;

SET @has_last_external_login_at := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'last_external_login_at'
);

SET @sql_add_last_external_login_at := IF(
  @has_last_external_login_at = 0,
  'ALTER TABLE users ADD COLUMN last_external_login_at DATETIME NULL AFTER profile_completed',
  'SELECT 1'
);

PREPARE stmt_add_last_external_login_at FROM @sql_add_last_external_login_at;
EXECUTE stmt_add_last_external_login_at;
DEALLOCATE PREPARE stmt_add_last_external_login_at;

SET @has_external_identity_index := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'idx_users_external_identity'
);

SET @sql_add_external_identity_index := IF(
  @has_external_identity_index = 0,
  'ALTER TABLE users ADD INDEX idx_users_external_identity (external_provider, external_username)',
  'SELECT 1'
);

PREPARE stmt_add_external_identity_index FROM @sql_add_external_identity_index;
EXECUTE stmt_add_external_identity_index;
DEALLOCATE PREPARE stmt_add_external_identity_index;
