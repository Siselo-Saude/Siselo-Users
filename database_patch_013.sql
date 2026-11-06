-- database_patch_013.sql
-- Atualização das nomenclaturas oficiais das UBS 01 e 03 do Jardim Botânico
-- Conforme Relatório de Validação CADH/UBS e issues #67 e #68

USE intranet_cadh;

-- 1. Atualizar referências da UBS 01 Jardim Botânico - Jardins Mangueiral na tabela patients
UPDATE patients
SET ubs_ref = 'UBS 01 JARDIM BOTÂNICO – Jardins Mangueiral',
    updated_at = NOW()
WHERE ubs_ref IN (
    'UBS 1',
    'UBS 01',
    'UBS 1 JARDINS MANGUEIRAL',
    'UBS 01 JARDINS MANGUEIRAL',
    'UBS 1 – Jardins Mangueiral',
    'UBS 01 – Jardins Mangueiral',
    'UBS 1 Jardim Botânico – Jardins Mangueiral',
    'UBS 01 Jardim Botânico – Jardins Mangueiral'
) OR (
    UPPER(REPLACE(REPLACE(REPLACE(ubs_ref, '–', '-'), 'Á', 'A'), 'Ã', 'A')) LIKE '%UBS%1%MANGUEIRAL%'
    AND ubs_ref <> 'UBS 01 JARDIM BOTÂNICO – Jardins Mangueiral'
);

-- 2. Atualizar referências da UBS 03 Jardim Botânico - Tororó na tabela patients
UPDATE patients
SET ubs_ref = 'UBS 03 JARDIM BOTÂNICO – Tororó',
    updated_at = NOW()
WHERE ubs_ref IN (
    'UBS 3',
    'UBS 03',
    'UBS 3 JARDIM BOTANICO',
    'UBS 03 JARDIM BOTANICO',
    'UBS 3 – Jardim Botânico',
    'UBS 03 – Jardim Botânico',
    'UBS 3 – Tororó',
    'UBS 03 – Tororó',
    'UBS 03 Jardim Botânico – Tororó'
) OR (
    UPPER(REPLACE(REPLACE(REPLACE(ubs_ref, '–', '-'), 'Á', 'A'), 'Ã', 'A')) LIKE '%UBS%3%BOTANICO%'
    AND ubs_ref NOT LIKE '%PARANOA%'
    AND ubs_ref NOT LIKE '%ITAPOA%'
    AND ubs_ref NOT LIKE '%SAO SEBASTIAO%'
    AND ubs_ref <> 'UBS 03 JARDIM BOTÂNICO – Tororó'
);

-- 3. Atualizar histórico de transições/compartilhamentos (from_service e to_service)
UPDATE transitions
SET from_service = 'UBS 01 JARDIM BOTÂNICO – Jardins Mangueiral',
    updated_at = NOW()
WHERE from_service IN (
    'UBS 1',
    'UBS 01',
    'UBS 1 JARDINS MANGUEIRAL',
    'UBS 01 JARDINS MANGUEIRAL',
    'UBS 1 – Jardins Mangueiral'
);

UPDATE transitions
SET to_service = 'UBS 01 JARDIM BOTÂNICO – Jardins Mangueiral',
    updated_at = NOW()
WHERE to_service IN (
    'UBS 1',
    'UBS 01',
    'UBS 1 JARDINS MANGUEIRAL',
    'UBS 01 JARDINS MANGUEIRAL',
    'UBS 1 – Jardins Mangueiral'
);

UPDATE transitions
SET from_service = 'UBS 03 JARDIM BOTÂNICO – Tororó',
    updated_at = NOW()
WHERE from_service IN (
    'UBS 3',
    'UBS 03',
    'UBS 3 JARDIM BOTANICO',
    'UBS 03 JARDIM BOTANICO',
    'UBS 3 – Jardim Botânico'
);

UPDATE transitions
SET to_service = 'UBS 03 JARDIM BOTÂNICO – Tororó',
    updated_at = NOW()
WHERE to_service IN (
    'UBS 3',
    'UBS 03',
    'UBS 3 JARDIM BOTANICO',
    'UBS 03 JARDIM BOTANICO',
    'UBS 3 – Jardim Botânico'
);
