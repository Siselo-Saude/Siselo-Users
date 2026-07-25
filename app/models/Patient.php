<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/Audit.php';

final class Patient {
  private const MIN_DATE = '1900-01-01';
  private const MAX_DATE = '2026-12-31';

  private const GENDER_OPTIONS = [
    'masculino' => 'Masculino',
    'feminino' => 'Feminino',
    'outro' => 'Outro',
  ];

  private const RACE_OPTIONS = [
    'branca' => 'Branca',
    'preta' => 'Preta',
    'parda' => 'Parda',
    'amarela' => 'Amarela',
    'indigena' => 'Indigena',
    'nao_informado' => 'Nao informado',
  ];

  private const STATUS_OPTIONS = [
    'ativo' => 'Ativo',
    'inativo' => 'Inativo',
  ];

  private const RISK_OPTIONS = [
    'alto_risco' => 'Alto Risco',
    'muito_alto_risco' => 'Muito Alto Risco',
  ];

  private const LEGACY_TEAM_OPTIONS = [
    'sem_equipe' => 'Sem equipe',
    'safira' => 'Safira',
    'ametista' => 'Ametista',
    'esmeralda' => 'Esmeralda',
    'diamante' => 'Diamante',
  ];

  private const UBS_TEAM_GROUPS = [
    [
      'ubs' => 'UBS 01 PARANOA',
      'teams' => [
        'ESF EQUIPE 08 - BRANCA',
        'ESF EQUIPE 09 - AMARELA',
        'ESF EQUIPE 10 - AZUL',
        'ESF EQUIPE 11 - VERDE',
        'ESF EQUIPE 12 - ROSA',
        'ESF EQUIPE 13 - LILAS',
        'ESF EQUIPE 14 - MARROM',
        'ESF EQUIPE 15 - DOURADA',
        'ESF EQUIPE 16 - LARANJA',
        'ESF EQUIPE 17 - PRATA',
        'EMULTI AROEIRA',
        'ECR MESTRE DAMIAO',
      ],
    ],
    [
      'ubs' => 'UBS 02 PARANOA - Quadra 18',
      'teams' => [
        'ESF EQUIPE 01 - QUADRA 18',
        'ESF EQUIPE 02 - QUADRA 18',
        'ESF EQUIPE 03 - QUADRA 18',
      ],
    ],
    [
      'ubs' => 'UBS 03 PARANOA - Paranoa Parque',
      'teams' => [
        'ESF EQUIPE 18 - BURITI',
        'ESF EQUIPE 20 - JACARANDA',
        'ESF EQUIPE 21 - IPE',
        'ESF EQUIPE 22 - JEQUITIBA',
        'ESF EQUIPE 23 - PEQUI',
      ],
    ],
    ['ubs' => 'UBS 04 PARANOA - Jardim II', 'teams' => ['ESF EQUIPE 02 - JARDIM II', 'ESB JARDIM II']],
    ['ubs' => 'UBS 05 PARANOA - Quebrada dos Neres', 'teams' => ['ESF EQUIPE QUEBRADA DOS NERES']],
    ['ubs' => 'UBS 06 PARANOA - Cariru', 'teams' => ['ESF EQUIPE 04 - CARIRU', 'ESB CARIRU']],
    ['ubs' => 'UBS 07 PARANOA - Cafe sem Troco', 'teams' => ['ESF EQUIPE CAFE SEM TROCO']],
    ['ubs' => 'UBS 08 PARANOA - PADDF', 'teams' => ['ESF EQUIPE PADDF']],
    [
      'ubs' => 'UBS 01 ITAPOA',
      'teams' => [
        'ESF EQUIPE 07 - ROSA',
        'ESF EQUIPE 08 - LARANJA',
        'ESF EQUIPE 09 - LILAS',
        'ESF EQUIPE 10 - CINZA',
        'ESF EQUIPE 12 - VERDE',
        'ESF EQUIPE 13 - VERMELHA',
      ],
    ],
    ['ubs' => 'UBS 02 ITAPOA', 'teams' => ['ESF EQUIPE 01', 'ESF EQUIPE 02', 'ESF EQUIPE ESMERALDA']],
    ['ubs' => 'UBS 03 ITAPOA', 'teams' => ['ESF EQUIPE 03', 'ESF EQUIPE 04', 'ESF EQUIPE 05']],
    ['ubs' => 'UBS 04 ITAPOA', 'teams' => ['ESF EQUIPE RUBI']],
    [
      'ubs' => 'UBS 01 SAO SEBASTIAO',
      'teams' => [
        'ESF EQUIPE 04 - LARANJA',
        'ESF EQUIPE 07 - LILAS',
        'ESF EQUIPE 08 - ROSA',
        'ESF EQUIPE 09 - VERDE',
        'ESF EQUIPE 10 - VERMELHA',
        'ESF DOURADA',
      ],
    ],
    ['ubs' => 'UBS 02 SAO SEBASTIAO - T.R.E.', 'teams' => ['ESF EQUIPE T.R.E.']],
    ['ubs' => 'UBS 03 SAO SEBASTIAO - Oeste', 'teams' => ['ESF EQUIPE 12 - OESTE 2', 'ESB OESTE SSB']],
    ['ubs' => 'UBS 04 SAO SEBASTIAO - Morro Azul', 'teams' => ['ESF EQUIPE MORRO AZUL']],
    ['ubs' => 'UBS 05 SAO SEBASTIAO - Nova Betania', 'teams' => ['ESF EQUIPE 01 - NOVA BETANIA', 'ESB NOVA BETANIA SSB']],
    ['ubs' => 'UBS 06 SAO SEBASTIAO - Sao Francisco', 'teams' => ['ESF EQUIPE 16 - SAO FRANCISCO']],
    ['ubs' => 'UBS 07 SAO SEBASTIAO - Morro da Cruz', 'teams' => ['ESF EQUIPE 02 - MORRO DA CRUZ']],
    ['ubs' => 'UBS 08 SAO SEBASTIAO - Cavas de Baixo', 'teams' => ['ESF EQUIPE 03 - CAVAS DE BAIXO']],
    [
      'ubs' => 'UBS 09 SAO SEBASTIAO - Bosque',
      'teams' => [
        'ESF EQ 21 - BOSQUE 1',
        'ESF EQUIPE 23 - VILA NOVA 2',
        'ESB BOSQUE 1 SSB',
        'EMULTI IPE AMARELO',
      ],
    ],
    ['ubs' => 'UBS 10 SAO SEBASTIAO - Joao Candido', 'teams' => ['ESF EQUIPE 22 - JOAO CANDIDO']],
    ['ubs' => 'UBS 11 SAO SEBASTIAO - Bosque 2', 'teams' => ['ESF EQUIPE 25 - BOSQUE 2']],
    ['ubs' => 'UBS 12 SAO SEBASTIAO - Sao Jose', 'teams' => ['ESF EQUIPE 17 - SAO JOSE']],
    [
      'ubs' => 'UBS 14 SAO SEBASTIAO - PDF IV',
      'teams' => [
        'EQ. AMPLIADA 27 TIPO III CDP',
        'EQ. PSICOSOCIAL 30H - 2238861',
        'EQ. AMPLIADA ROSIMEIRE RODRIGUES',
      ],
    ],
    [
      'ubs' => 'UBS 15 SAO SEBASTIAO - CIR',
      'teams' => [
        'EQ. PSICOSOCIAL 30H - 2238853',
        'EQ. AMPLIADA 30H - 2238942',
        'EQ. PSICOSOCIAL 30H - 2238969',
        'EQ. AMPLIADA CARLA MACHADO',
      ],
    ],
    [
      'ubs' => 'UBS 16 SAO SEBASTIAO - PDF 1',
      'teams' => [
        'EQ. PSICOSOCIAL 30H - 2238837',
        'EQ. AMPLIADA 20H - 2238896',
        'EQ. PSICOSOCIAL 20H - 2238918',
        'EQ. AMPLIADA EURICO JARDIM',
      ],
    ],
    [
      'ubs' => 'UBS 17 SAO SEBASTIAO - PDF 2',
      'teams' => [
        'EQ. PSICOSOCIAL 30H - 2238845',
        'EQ. AMPLIADA 20H - 2238926',
        'EQ. PSICOSOCIAL 20H - 2238934',
        'EQ. AMPLIADA ALFA',
      ],
    ],
    ['ubs' => 'UBS 19 SAO SEBASTIAO - Vila do Boa', 'teams' => ['ESF EQUIPE VILA DO BOA']],
    [
      'ubs' => 'UBS 20 SAO SEBASTIAO CDP',
      'teams' => [
        'EQ. PSICOSOCIAL 30H - 2238888',
        'EQUIPE AMPLIADA 20H - 2502216',
      ],
    ],
    [
      'ubs' => 'UBS 1 JARDINS MANGUEIRAL',
      'teams' => [
        'ESF DIAMANTE',
        'ESF AMETISTA',
        'ESF SAFIRA',
        'ESF ESMERALDA',
        'ESB 1 JARDINS MANGUEIRAL',
        'ESB 2 JARDINS MANGUEIRAL',
        'EMULTI RUBI',
      ],
    ],
    ['ubs' => 'UBS 03 JARDIM BOTANICO', 'teams' => ['ESF TORORO']],
  ];

  private const REQUIRED_FIELDS = [
    'first_cadh_date',
    'full_name',
    'cpf',
    'birth_date',
    'sex',
    'race',
    'responsible_name',
    'phone',
    'address',
    'email',
    'emergency_contact',
    'allergies',
    'chronic_conditions',
    'risk_classification',
    'ubs_ref',
    'team_ref',
  ];

  private const FIELD_LABELS = [
    'first_cadh_date' => 'Data do compartilhamento com o CADH',
    'full_name' => 'Nome completo',
    'cpf' => 'CPF',
    'birth_date' => 'Data de nascimento',
    'sex' => 'Genero',
    'race' => 'Cor/Raca',
    'responsible_name' => 'Responsavel',
    'phone' => 'Telefone',
    'address' => 'Endereco',
    'email' => 'Email',
    'emergency_contact' => 'Contato de emergencia',
    'allergies' => 'Alergias',
    'chronic_conditions' => 'Condicoes cronicas',
    'status' => 'Status',
    'risk_classification' => 'Classificacao de risco',
    'ubs_ref' => 'UBS de referencia',
    'team_ref' => 'Equipe',
  ];

  private const FIELD_MAX_LENGTHS = [
    'full_name' => 180,
    'cpf' => 14,
    'sex' => 20,
    'race' => 40,
    'responsible_name' => 180,
    'phone' => 15,
    'address' => 255,
    'email' => 190,
    'emergency_contact' => 15,
    'status' => 20,
    'ubs_ref' => 120,
    'team_ref' => 120,
  ];

  private static function ubsOptions(): array {
    $options = [];
    foreach (self::UBS_TEAM_GROUPS as $group) {
      $ubs = (string)$group['ubs'];
      $options[$ubs] = $ubs;
    }

    return $options;
  }

  private static function teamOptions(): array {
    $options = self::LEGACY_TEAM_OPTIONS;
    foreach (self::UBS_TEAM_GROUPS as $group) {
      foreach ($group['teams'] as $team) {
        $team = (string)$team;
        $options[$team] = $team;
      }
    }

    return $options;
  }

  public static function options(): array {
    return [
      'min_date' => self::MIN_DATE,
      'max_date' => self::MAX_DATE,
      'gender_options' => self::GENDER_OPTIONS,
      'race_options' => self::RACE_OPTIONS,
      'status_options' => self::STATUS_OPTIONS,
      'risk_options' => self::RISK_OPTIONS,
      'ubs_options' => self::ubsOptions(),
      'team_options' => self::teamOptions(),
    ];
  }

  public static function listActive(PDO $pdo, string $query = ''): array {
    return self::listByDeletedState($pdo, $query, false);
  }

  public static function listTrash(PDO $pdo, string $query = ''): array {
    return self::listByDeletedState($pdo, $query, true);
  }

  public static function find(PDO $pdo, int $id, bool $allowDeleted = false): ?array {
    $sql = 'SELECT * FROM patients WHERE id = :id';
    if (!$allowDeleted) {
      $sql .= ' AND deleted_at IS NULL';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ? self::serializeRow($row) : null;
  }

  public static function formContext(PDO $pdo, ?int $id = null): array {
    $editing = $id !== null;
    $row = [
      'first_cadh_date' => self::defaultAttendanceDate(),
      'full_name' => '',
      'ses' => '',
      'cpf' => '',
      'birth_date' => '',
      'sex' => '',
      'race' => '',
      'responsible_name' => '',
      'phone' => '',
      'address' => '',
      'email' => '',
      'emergency_contact' => '',
      'allergies' => '',
      'chronic_conditions' => '',
      'status' => 'ativo',
      'risk_classification' => 'alto_risco',
      'care_status' => 'recebido',
      'ubs_ref' => '',
      'team_ref' => '',
    ];

    if ($editing) {
      $stored = self::find($pdo, $id);
      if ($stored === null) {
        return [
          'editing' => true,
          'row' => null,
          'options' => self::options(),
          'error' => 'Paciente nao encontrado.',
        ];
      }

      $row = array_merge($row, $stored);
    }

    return [
      'editing' => $editing,
      'row' => $row,
      'options' => self::options(),
      'error' => null,
    ];
  }

  public static function validate(array $payload): array {
    $teamRef = self::normalizeOptionValue((string)($payload['team_reference'] ?? $payload['team_ref'] ?? ''), self::teamOptions());
    if ($teamRef === '') {
      $teamRef = 'sem_equipe';
    }

    $data = [
      'first_cadh_date' => self::normalizeOptional($payload['attendance_date'] ?? $payload['first_cadh_date'] ?? null),
      'full_name' => self::normalizeSingleLine((string)($payload['full_name'] ?? '')),
      'ses' => trim((string)($payload['ses'] ?? '')),
      'cpf' => self::normalizeOptional($payload['cpf'] ?? null),
      'birth_date' => self::normalizeOptional($payload['birth_date'] ?? null),
      'sex' => self::normalizeOptionValue((string)($payload['gender'] ?? $payload['sex'] ?? ''), self::GENDER_OPTIONS),
      'race' => self::normalizeOptionValue((string)($payload['race'] ?? ''), self::RACE_OPTIONS),
      'responsible_name' => self::normalizeSingleLine((string)($payload['responsible'] ?? $payload['responsible_name'] ?? '')),
      'phone' => trim((string)($payload['phone'] ?? '')),
      'address' => self::normalizeSingleLine((string)($payload['address'] ?? '')),
      'email' => trim((string)($payload['email'] ?? $payload['contact_email'] ?? '')),
      'emergency_contact' => trim((string)($payload['emergency_contact'] ?? '')),
      'health_insurance' => '',
      'blood_type' => '',
      'allergies' => trim((string)($payload['allergies'] ?? '')),
      'chronic_conditions' => trim((string)($payload['chronic_conditions'] ?? '')),
      'status' => self::normalizeOptionValue((string)($payload['status'] ?? 'ativo'), self::STATUS_OPTIONS),
      'risk_classification' => self::normalizeOptionValue((string)($payload['risk_classification'] ?? 'alto_risco'), self::RISK_OPTIONS),
      'care_status' => self::normalizeSingleLine((string)($payload['care_status'] ?? 'recebido')),
      'ubs_ref' => self::normalizeSingleLine((string)($payload['uds_reference'] ?? $payload['ubs_ref'] ?? '')),
      'team_ref' => $teamRef,
    ];

    $errors = [];

    foreach (self::REQUIRED_FIELDS as $field) {
      if (($data[$field] ?? null) === null || ($data[$field] ?? '') === '') {
        self::setError($errors, $field, 'Campo vazio. Preencha este campo.');
      }
    }

    if ($data['first_cadh_date'] !== null && !self::isValidDate($data['first_cadh_date'])) {
      self::setError($errors, 'first_cadh_date', 'Informe uma data valida.');
    } elseif ($data['first_cadh_date'] !== null && !self::isDateInRange($data['first_cadh_date'])) {
      self::setError($errors, 'first_cadh_date', 'Informe uma data entre 1900 e 2026.');
    }

    if ($data['birth_date'] !== null && !self::isValidDate($data['birth_date'])) {
      self::setError($errors, 'birth_date', 'Informe uma data valida.');
    } elseif ($data['birth_date'] !== null && !self::isDateInRange($data['birth_date'])) {
      self::setError($errors, 'birth_date', 'Informe uma data entre 1900 e 2026.');
    }

    if ($data['cpf'] !== null) {
      $cpfDigits = self::digitsOnly($data['cpf']);
      if (!self::isValidCpf($cpfDigits)) {
        self::setError($errors, 'cpf', 'Informe um CPF valido.');
      } else {
        $data['cpf'] = self::formatCpf($cpfDigits);
      }
    }

    if ($data['sex'] !== '' && !array_key_exists($data['sex'], self::GENDER_OPTIONS)) {
      self::setError($errors, 'sex', 'Selecione uma opcao valida para genero.');
    }

    if ($data['race'] !== '' && !array_key_exists($data['race'], self::RACE_OPTIONS)) {
      self::setError($errors, 'race', 'Selecione uma opcao valida para cor/raca.');
    }

    if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
      self::setError($errors, 'email', 'Informe um email valido.');
    }

    if ($data['status'] !== '' && !array_key_exists($data['status'], self::STATUS_OPTIONS)) {
      self::setError($errors, 'status', 'Selecione um status valido.');
    }

    if ($data['risk_classification'] !== '' && !array_key_exists($data['risk_classification'], self::RISK_OPTIONS)) {
      self::setError($errors, 'risk_classification', 'Selecione uma classificacao de risco valida.');
    }

    if ($data['team_ref'] !== '' && !array_key_exists($data['team_ref'], self::teamOptions())) {
      self::setError($errors, 'team_ref', 'Selecione uma equipe valida.');
    }

    $phoneDigits = self::digitsOnly($data['phone']);
    if ($data['phone'] !== '' && !self::isValidPhone($phoneDigits)) {
      self::setError($errors, 'phone', 'Telefone deve ter 10, 11 ou 3 digitos.');
    } elseif ($data['phone'] !== '') {
      $data['phone'] = self::formatPhone($phoneDigits);
    }

    $emergencyDigits = self::digitsOnly($data['emergency_contact']);
    if ($data['emergency_contact'] !== '' && !self::isValidPhone($emergencyDigits)) {
      self::setError($errors, 'emergency_contact', 'Contato de emergencia deve ter 10, 11 ou 3 digitos.');
    } elseif ($data['emergency_contact'] !== '') {
      $data['emergency_contact'] = self::formatPhone($emergencyDigits);
    }

    foreach (self::FIELD_MAX_LENGTHS as $field => $maxLength) {
      $value = (string)($data[$field] ?? '');
      if ($value !== '' && self::stringLength($value) > $maxLength) {
        self::setError($errors, $field, self::FIELD_LABELS[$field] . ' deve ter no maximo ' . $maxLength . ' caracteres.');
      }
    }

    return [
      'data' => $data,
      'errors' => $errors,
    ];
  }

  public static function save(PDO $pdo, ?int $id, array $data, int $actorUserId): array {
    $editing = $id !== null;

    $pdo->beginTransaction();

    try {
      if ($editing) {
        $before = self::rawFind($pdo, $id, false);
        if ($before === null) {
          throw new RuntimeException('Paciente nao encontrado.');
        }
        $data['care_status'] = (string)($before['care_status'] ?? 'recebido');

        $stmt = $pdo->prepare(
          'UPDATE patients SET
            first_cadh_date = :first_cadh_date,
            full_name = :full_name,
            ses = :ses,
            cpf = :cpf,
            birth_date = :birth_date,
            sex = :sex,
            race = :race,
            responsible_name = :responsible_name,
            phone = :phone,
            address = :address,
            email = :email,
            emergency_contact = :emergency_contact,
            health_insurance = :health_insurance,
            blood_type = :blood_type,
            allergies = :allergies,
            chronic_conditions = :chronic_conditions,
            status = :status,
            risk_classification = :risk_classification,
            care_status = :care_status,
            ubs_ref = :ubs_ref,
            team_ref = :team_ref,
            updated_at = NOW()
          WHERE id = :id'
        );
        $stmt->execute($data + ['id' => $id]);

        Audit::log($pdo, $actorUserId, 'update', 'patients', $id, $before, $data);
        $patientId = $id;
      } else {
        $data['care_status'] = 'recebido';
        $stmt = $pdo->prepare(
          'INSERT INTO patients
          (first_cadh_date, full_name, ses, cpf, birth_date, sex, race, responsible_name, phone, address, email, emergency_contact, health_insurance, blood_type, allergies, chronic_conditions, status, risk_classification, care_status, ubs_ref, team_ref)
          VALUES
          (:first_cadh_date, :full_name, :ses, :cpf, :birth_date, :sex, :race, :responsible_name, :phone, :address, :email, :emergency_contact, :health_insurance, :blood_type, :allergies, :chronic_conditions, :status, :risk_classification, :care_status, :ubs_ref, :team_ref)'
        );
        $stmt->execute($data);
        $patientId = (int)$pdo->lastInsertId();

        Audit::log($pdo, $actorUserId, 'create', 'patients', $patientId, null, $data);
      }

      $pdo->commit();
      return self::formContext($pdo, $patientId);
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      throw $error;
    }
  }

  public static function softDelete(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::rawFind($pdo, $id, false);
    if ($before === null) {
      return null;
    }

    $stmt = $pdo->prepare('UPDATE patients SET deleted_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $id]);

    Audit::log($pdo, $actorUserId, 'delete', 'patients', $id, $before, ['deleted_at' => date('c')]);

    return self::find($pdo, $id, true);
  }

  public static function restore(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::rawFind($pdo, $id, true);
    if ($before === null || $before['deleted_at'] === null) {
      return null;
    }

    $stmt = $pdo->prepare('UPDATE patients SET deleted_at = NULL, updated_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $id]);

    Audit::log($pdo, $actorUserId, 'restore', 'patients', $id, $before, ['deleted_at' => null]);

    return self::find($pdo, $id, false);
  }

  public static function destroy(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::rawFind($pdo, $id, true);
    if ($before === null || $before['deleted_at'] === null) {
      return null;
    }

    $pdo->beginTransaction();
    try {
      $pdo->prepare('
        DELETE FROM care_plan_items
        WHERE care_plan_id IN (
          SELECT id
          FROM care_plans
          WHERE patient_id = :patient_id
        )
      ')->execute([':patient_id' => $id]);

      $pdo->prepare('DELETE FROM care_plans WHERE patient_id = :patient_id')
        ->execute([':patient_id' => $id]);
      $pdo->prepare('DELETE FROM encounters WHERE patient_id = :patient_id')
        ->execute([':patient_id' => $id]);
      $pdo->prepare('DELETE FROM transitions WHERE patient_id = :patient_id')
        ->execute([':patient_id' => $id]);
      $pdo->prepare('DELETE FROM patients WHERE id = :id')
        ->execute([':id' => $id]);

      Audit::log($pdo, $actorUserId, 'destroy', 'patients', $id, $before, null);
      $pdo->commit();
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      throw $error;
    }

    return self::serializeRow($before);
  }

  public static function mapPersistenceError(Throwable $error): array {
    $field = null;
    $message = $error->getMessage();

    if (preg_match("/column '([^']+)'/i", $message, $matches) === 1) {
      $candidate = $matches[1];
      if (isset(self::FIELD_LABELS[$candidate])) {
        $field = $candidate;
      }
    }

    $driverCode = 0;
    if ($error instanceof PDOException) {
      $driverCode = (int)($error->errorInfo[1] ?? 0);
    }

    if ($driverCode === 1062) {
      return ['field' => 'cpf', 'message' => 'CPF ja cadastrado para outro paciente.'];
    }

    if ($driverCode === 1406 && $field !== null) {
      return ['field' => $field, 'message' => self::FIELD_LABELS[$field] . ' ultrapassa o limite permitido.'];
    }

    if ($driverCode === 1292 && $field !== null) {
      return ['field' => $field, 'message' => 'Valor invalido em ' . self::FIELD_LABELS[$field] . '.'];
    }

    if ($field !== null) {
      return ['field' => $field, 'message' => 'Valor invalido em ' . self::FIELD_LABELS[$field] . '.'];
    }

    return ['field' => null, 'message' => 'Nao foi possivel salvar o cadastro. Revise os campos informados.'];
  }

  public static function carePlansFor(PDO $pdo, int $patientId): array {
    $stmt = $pdo->prepare('SELECT * FROM care_plans WHERE patient_id = :patient_id AND deleted_at IS NULL ORDER BY id DESC');
    $stmt->execute([':patient_id' => $patientId]);
    return $stmt->fetchAll();
  }

  public static function encountersFor(PDO $pdo, int $patientId): array {
    $stmt = $pdo->prepare('SELECT * FROM encounters WHERE patient_id = :patient_id AND deleted_at IS NULL ORDER BY encounter_date DESC, id DESC');
    $stmt->execute([':patient_id' => $patientId]);
    return $stmt->fetchAll();
  }

  public static function transitionsFor(PDO $pdo, int $patientId): array {
    $stmt = $pdo->prepare('SELECT * FROM transitions WHERE patient_id = :patient_id AND deleted_at IS NULL ORDER BY transition_date DESC, id DESC');
    $stmt->execute([':patient_id' => $patientId]);
    return $stmt->fetchAll();
  }

  private static function listByDeletedState(PDO $pdo, string $query, bool $deleted): array {
    $sql = 'SELECT * FROM patients WHERE deleted_at IS ' . ($deleted ? 'NOT NULL' : 'NULL');
    $params = [];

    if (!$deleted) {
      $sql .= " AND COALESCE(care_status, 'recebido') <> 'finalizado'";
    }

    if ($query !== '') {
      if ($deleted) {
        $sql .= ' AND (full_name LIKE :q_full_name OR cpf LIKE :q_cpf OR team_ref LIKE :q_team_ref)';
        $params[':q_full_name'] = '%' . $query . '%';
        $params[':q_cpf'] = '%' . $query . '%';
        $params[':q_team_ref'] = '%' . $query . '%';
      } else {
        $sql .= ' AND (full_name LIKE :q_name OR cpf LIKE :q_cpf OR team_ref LIKE :q_team_ref OR phone LIKE :q_phone OR email LIKE :q_email)';
        $params[':q_name'] = '%' . $query . '%';
        $params[':q_cpf'] = '%' . $query . '%';
        $params[':q_team_ref'] = '%' . $query . '%';
        $params[':q_phone'] = '%' . $query . '%';
        $params[':q_email'] = '%' . $query . '%';
      }
    }

    $sql .= $deleted ? ' ORDER BY deleted_at DESC LIMIT 300' : ' ORDER BY full_name ASC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return array_map([self::class, 'serializeRow'], $rows);
  }

  private static function rawFind(PDO $pdo, int $id, bool $allowDeleted): ?array {
    $sql = 'SELECT * FROM patients WHERE id = :id';
    if (!$allowDeleted) {
      $sql .= ' AND deleted_at IS NULL';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
  }

  private static function serializeRow(array $row): array {
    $ageLabel = self::ageLabel($row['birth_date'] ?? null);
    $genderKey = strtolower((string)($row['sex'] ?? ''));
    $statusKey = strtolower((string)($row['status'] ?? 'ativo'));
    $riskKey = strtolower((string)($row['risk_classification'] ?? 'alto_risco'));

    $row['id'] = (int)$row['id'];
    $row['age_label'] = $ageLabel;
    $row['gender_label'] = self::GENDER_OPTIONS[$genderKey] ?? '';
    $row['status_label'] = self::STATUS_OPTIONS[$statusKey] ?? 'Ativo';
    $row['risk_label'] = self::RISK_OPTIONS[$riskKey] ?? 'Alto Risco';
    unset($row['health_insurance'], $row['blood_type']);

    return $row;
  }

  private static function ageLabel(?string $birthDate): string {
    if ($birthDate === null || $birthDate === '') {
      return '';
    }

    try {
      $birth = new DateTimeImmutable($birthDate);
      return $birth->diff(new DateTimeImmutable('today'))->y . ' anos';
    } catch (Throwable $error) {
      return '';
    }
  }

  private static function defaultAttendanceDate(): string {
    $date = date('Y-m-d');
    if ($date < self::MIN_DATE) {
      return self::MIN_DATE;
    }

    if ($date > self::MAX_DATE) {
      return self::MAX_DATE;
    }

    return $date;
  }

  private static function normalizeOptional($value): ?string {
    $value = trim((string)$value);
    return $value === '' ? null : $value;
  }

  private static function normalizeSingleLine(string $value): string {
    return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
  }

  private static function normalizeOptionValue(string $value, array $options): string {
    $normalizedValue = self::normalizeOptionComparable($value);
    $aliases = [
      'amarelo' => 'amarela',
    ];

    if (isset($aliases[$normalizedValue])) {
      return $aliases[$normalizedValue];
    }

    foreach ($options as $optionValue => $label) {
      if (
        $normalizedValue === self::normalizeOptionComparable((string)$optionValue) ||
        $normalizedValue === self::normalizeOptionComparable((string)$label)
      ) {
        return (string)$optionValue;
      }
    }

    return trim($value);
  }

  private static function normalizeOptionComparable(string $value): string {
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/^equipe\s*:\s*/i', '', $normalized) ?? $normalized;
    $normalized = strtr($normalized, [
      'á' => 'a',
      'à' => 'a',
      'ã' => 'a',
      'â' => 'a',
      'é' => 'e',
      'ê' => 'e',
      'í' => 'i',
      'ó' => 'o',
      'ô' => 'o',
      'õ' => 'o',
      'ú' => 'u',
      'ç' => 'c',
      'Á' => 'a',
      'À' => 'a',
      'Ã' => 'a',
      'Â' => 'a',
      'É' => 'e',
      'Ê' => 'e',
      'Í' => 'i',
      'Ó' => 'o',
      'Ô' => 'o',
      'Õ' => 'o',
      'Ú' => 'u',
      'Ç' => 'c',
    ]);
    $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
    return trim($normalized);
  }

  private static function digitsOnly(?string $value): string {
    return preg_replace('/\D+/', '', (string)$value) ?? '';
  }

  private static function isValidDate(?string $value): bool {
    if ($value === null || $value === '') {
      return true;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
  }

  private static function isDateInRange(?string $value): bool {
    if ($value === null || $value === '') {
      return true;
    }

    return $value >= self::MIN_DATE && $value <= self::MAX_DATE;
  }

  private static function isValidCpf(string $digits): bool {
    if (!preg_match('/^\d{11}$/', $digits)) {
      return false;
    }

    if (count(array_unique(str_split($digits))) === 1) {
      return false;
    }

    for ($target = 9; $target < 11; $target++) {
      $sum = 0;
      for ($index = 0; $index < $target; $index++) {
        $sum += (int)$digits[$index] * (($target + 1) - $index);
      }

      $digit = ((10 * $sum) % 11) % 10;
      if ((int)$digits[$target] !== $digit) {
        return false;
      }
    }

    return true;
  }

  private static function formatCpf(string $digits): string {
    return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
  }

  private static function isValidPhone(string $digits): bool {
    return in_array(strlen($digits), [3, 10, 11], true);
  }

  private static function formatPhone(string $digits): string {
    $length = strlen($digits);
    if ($length === 11) {
      return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 5) . '-' . substr($digits, 7, 4);
    }

    if ($length === 10) {
      return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 4) . '-' . substr($digits, 6, 4);
    }

    return $digits;
  }

  private static function stringLength(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
  }

  private static function setError(array &$errors, string $field, string $message): void {
    if (!isset($errors[$field])) {
      $errors[$field] = $message;
    }
  }
}
