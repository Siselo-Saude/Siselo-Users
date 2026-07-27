USE intranet_cadh;

SET @has_referral_received_at := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'transitions'
    AND COLUMN_NAME = 'received_at'
);

SET @sql_add_referral_received_at := IF(
  @has_referral_received_at = 0,
  'ALTER TABLE transitions ADD COLUMN received_at DATETIME NULL AFTER status',
  'SELECT 1'
);

PREPARE stmt_add_referral_received_at FROM @sql_add_referral_received_at;
EXECUTE stmt_add_referral_received_at;
DEALLOCATE PREPARE stmt_add_referral_received_at;

UPDATE transitions
SET received_at = COALESCE(updated_at, created_at)
WHERE received_at IS NULL
  AND deleted_at IS NULL
  AND UPPER(TRIM(to_service)) LIKE 'CADH%'
  AND status IN ('em_andamento', 'concluida');
