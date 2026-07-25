USE intranet_cadh;

SET @has_encounter_record_type := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'encounters'
    AND COLUMN_NAME = 'record_type'
);

SET @sql_add_encounter_record_type := IF(
  @has_encounter_record_type = 0,
  'ALTER TABLE encounters ADD COLUMN record_type VARCHAR(30) NOT NULL DEFAULT ''consulta'' AFTER specialty',
  'SELECT 1'
);

PREPARE stmt_add_encounter_record_type FROM @sql_add_encounter_record_type;
EXECUTE stmt_add_encounter_record_type;
DEALLOCATE PREPARE stmt_add_encounter_record_type;

UPDATE encounters
SET record_type = 'consulta'
WHERE record_type IS NULL OR record_type = '';

UPDATE permissions
SET description = CASE name
  WHEN 'transitions.view' THEN 'Visualizar encaminhamentos'
  WHEN 'transitions.create' THEN 'Criar encaminhamentos'
  WHEN 'transitions.update' THEN 'Editar encaminhamentos'
  WHEN 'transitions.delete' THEN 'Apagar encaminhamentos (soft delete)'
  WHEN 'transitions.restore' THEN 'Restaurar encaminhamentos'
  ELSE description
END
WHERE name IN (
  'transitions.view',
  'transitions.create',
  'transitions.update',
  'transitions.delete',
  'transitions.restore'
);
