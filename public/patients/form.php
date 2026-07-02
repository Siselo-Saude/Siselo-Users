<?php
declare(strict_types=1);

require __DIR__ . '/../../app/core/bootstrap.php';
require __DIR__ . '/../../app/middleware/auth.php';
require __DIR__ . '/../../app/middleware/rbac.php';
require __DIR__ . '/../../app/services/Audit.php';

require_auth();

const PATIENT_FORM_MIN_DATE = '1900-01-01';
const PATIENT_FORM_MAX_DATE = '2026-12-31';

function patient_form_normalize_optional(?string $value): ?string {
  $value = trim((string)$value);
  return $value === '' ? null : $value;
}

function patient_form_normalize_single_line(string $value): string {
  return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
}

function patient_form_strlen(string $value): int {
  return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function patient_form_digits_only(?string $value): string {
  return preg_replace('/\D+/', '', (string)$value) ?? '';
}

function patient_form_is_valid_date(?string $value): bool {
  if ($value === null || $value === '') {
    return true;
  }

  $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
  return $date !== false && $date->format('Y-m-d') === $value;
}

function patient_form_is_date_in_range(?string $value, string $min, string $max): bool {
  if ($value === null || $value === '') {
    return true;
  }

  return $value >= $min && $value <= $max;
}

function patient_form_is_valid_cpf(string $digits): bool {
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

function patient_form_format_cpf(string $digits): string {
  return substr($digits, 0, 3) . '.'
    . substr($digits, 3, 3) . '.'
    . substr($digits, 6, 3) . '-'
    . substr($digits, 9, 2);
}

function patient_form_format_phone(string $digits): string {
  $length = strlen($digits);

  if ($length === 11) {
    return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 5) . '-' . substr($digits, 7, 4);
  }

  if ($length === 10) {
    return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 4) . '-' . substr($digits, 6, 4);
  }

  return $digits;
}

function patient_form_is_valid_phone(string $digits): bool {
  return in_array(strlen($digits), [3, 10, 11], true);
}

function patient_form_normalize_choice(string $value, array $allowed): string {
  foreach ($allowed as $option) {
    if (strcasecmp($value, $option) === 0) {
      return $option;
    }
  }

  return $value;
}

function patient_form_normalize_option_value(string $value, array $options): string {
  $normalizedValue = patient_form_normalize_option_comparable($value);
  $aliases = [
    'amarelo' => 'amarela',
  ];

  if (isset($aliases[$normalizedValue])) {
    return $aliases[$normalizedValue];
  }

  foreach ($options as $optionValue => $label) {
    if (
      $normalizedValue === patient_form_normalize_option_comparable((string)$optionValue) ||
      $normalizedValue === patient_form_normalize_option_comparable((string)$label)
    ) {
      return (string)$optionValue;
    }
  }

  return $value;
}

function patient_form_normalize_option_comparable(string $value): string {
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

function patient_form_set_error(array &$errors, string $field, string $message): void {
  if (!isset($errors[$field])) {
    $errors[$field] = $message;
  }
}

function patient_form_field_error(array $errors, string $field): string {
  return $errors[$field] ?? '';
}

function patient_form_field_style(array $errors, string $field, string $base = ''): string {
  $style = trim($base);

  if (!isset($errors[$field])) {
    return $style;
  }

  return trim($style . '; border:1px solid #c62828;');
}

function patient_form_map_save_error(Throwable $e): array {
  $labels = [
    'first_cadh_date' => 'Data do primeiro atendimento CADH',
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
    'ubs_ref' => 'UBS de referencia',
    'team_ref' => 'Equipe',
  ];

  $field = null;
  $message = $e->getMessage();

  if (preg_match("/column '([^']+)'/i", $message, $matches) === 1) {
    $candidate = $matches[1];
    if (isset($labels[$candidate])) {
      $field = $candidate;
    }
  }

  $driverCode = 0;
  if ($e instanceof PDOException) {
    $driverCode = (int)($e->errorInfo[1] ?? 0);
  }

  if ($driverCode === 1062) {
    return [
      'field' => 'cpf',
      'message' => 'CPF ja cadastrado para outro paciente.',
    ];
  }

  if ($driverCode === 1406 && $field !== null) {
    return [
      'field' => $field,
      'message' => $labels[$field] . ' ultrapassa o limite permitido.',
    ];
  }

  if ($driverCode === 1292 && $field !== null) {
    return [
      'field' => $field,
      'message' => 'Valor invalido em ' . $labels[$field] . '.',
    ];
  }

  if ($field !== null) {
    return [
      'field' => $field,
      'message' => 'Valor invalido em ' . $labels[$field] . '.',
    ];
  }

  return [
    'field' => null,
    'message' => 'Nao foi possivel salvar o cadastro. Revise os campos informados.',
  ];
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$editing = $id !== null;
$error = '';
$errors = [];

$fieldMaxLengths = [
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

$fieldLabels = [
  'first_cadh_date' => 'Data do primeiro atendimento CADH',
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
  'ubs_ref' => 'UBS de referencia',
  'team_ref' => 'Equipe',
];

$genderOptions = [
  'masculino' => 'Masculino',
  'feminino' => 'Feminino',
  'outro' => 'Outro',
];

$raceOptions = [
  'branca' => 'Branca',
  'preta' => 'Preta',
  'parda' => 'Parda',
  'amarela' => 'Amarela',
  'indigena' => 'Indigena',
  'nao_informado' => 'Nao informado',
];

$statusOptions = [
  'ativo' => 'Ativo',
  'inativo' => 'Inativo',
];

$teamOptions = [
  'sem_equipe' => 'Sem equipe',
  'safira' => 'Safira',
  'ametista' => 'Ametista',
  'esmeralda' => 'Esmeralda',
  'diamante' => 'Diamante',
  'ESF EQUIPE 08 - BRANCA' => 'ESF EQUIPE 08 - BRANCA',
  'ESF EQUIPE 09 - AMARELA' => 'ESF EQUIPE 09 - AMARELA',
  'ESF EQUIPE 10 - AZUL' => 'ESF EQUIPE 10 - AZUL',
  'ESF EQUIPE 11 - VERDE' => 'ESF EQUIPE 11 - VERDE',
  'ESF EQUIPE 12 - ROSA' => 'ESF EQUIPE 12 - ROSA',
  'ESF EQUIPE 13 - LILAS' => 'ESF EQUIPE 13 - LILAS',
  'ESF EQUIPE 14 - MARROM' => 'ESF EQUIPE 14 - MARROM',
  'ESF EQUIPE 15 - DOURADA' => 'ESF EQUIPE 15 - DOURADA',
  'ESF EQUIPE 16 - LARANJA' => 'ESF EQUIPE 16 - LARANJA',
  'ESF EQUIPE 17 - PRATA' => 'ESF EQUIPE 17 - PRATA',
  'EMULTI AROEIRA' => 'EMULTI AROEIRA',
  'ECR MESTRE DAMIAO' => 'ECR MESTRE DAMIAO',
  'ESF EQUIPE 01 - QUADRA 18' => 'ESF EQUIPE 01 - QUADRA 18',
  'ESF EQUIPE 02 - QUADRA 18' => 'ESF EQUIPE 02 - QUADRA 18',
  'ESF EQUIPE 03 - QUADRA 18' => 'ESF EQUIPE 03 - QUADRA 18',
  'ESF EQUIPE 18 - BURITI' => 'ESF EQUIPE 18 - BURITI',
  'ESF EQUIPE 20 - JACARANDA' => 'ESF EQUIPE 20 - JACARANDA',
  'ESF EQUIPE 21 - IPE' => 'ESF EQUIPE 21 - IPE',
  'ESF EQUIPE 22 - JEQUITIBA' => 'ESF EQUIPE 22 - JEQUITIBA',
  'ESF EQUIPE 23 - PEQUI' => 'ESF EQUIPE 23 - PEQUI',
  'ESF EQUIPE 02 - JARDIM II' => 'ESF EQUIPE 02 - JARDIM II',
  'ESB JARDIM II' => 'ESB JARDIM II',
  'ESF EQUIPE QUEBRADA DOS NERES' => 'ESF EQUIPE QUEBRADA DOS NERES',
  'ESF EQUIPE 04 - CARIRU' => 'ESF EQUIPE 04 - CARIRU',
  'ESB CARIRU' => 'ESB CARIRU',
  'ESF EQUIPE CAFE SEM TROCO' => 'ESF EQUIPE CAFE SEM TROCO',
  'ESF EQUIPE PADDF' => 'ESF EQUIPE PADDF',
  'ESF EQUIPE 07 - ROSA' => 'ESF EQUIPE 07 - ROSA',
  'ESF EQUIPE 08 - LARANJA' => 'ESF EQUIPE 08 - LARANJA',
  'ESF EQUIPE 09 - LILAS' => 'ESF EQUIPE 09 - LILAS',
  'ESF EQUIPE 10 - CINZA' => 'ESF EQUIPE 10 - CINZA',
  'ESF EQUIPE 12 - VERDE' => 'ESF EQUIPE 12 - VERDE',
  'ESF EQUIPE 13 - VERMELHA' => 'ESF EQUIPE 13 - VERMELHA',
  'ESF EQUIPE 01' => 'ESF EQUIPE 01',
  'ESF EQUIPE 02' => 'ESF EQUIPE 02',
  'ESF EQUIPE ESMERALDA' => 'ESF EQUIPE ESMERALDA',
  'ESF EQUIPE 03' => 'ESF EQUIPE 03',
  'ESF EQUIPE 04' => 'ESF EQUIPE 04',
  'ESF EQUIPE 05' => 'ESF EQUIPE 05',
  'ESF EQUIPE RUBI' => 'ESF EQUIPE RUBI',
  'ESF EQUIPE 04 - LARANJA' => 'ESF EQUIPE 04 - LARANJA',
  'ESF EQUIPE 07 - LILAS' => 'ESF EQUIPE 07 - LILAS',
  'ESF EQUIPE 08 - ROSA' => 'ESF EQUIPE 08 - ROSA',
  'ESF EQUIPE 09 - VERDE' => 'ESF EQUIPE 09 - VERDE',
  'ESF EQUIPE 10 - VERMELHA' => 'ESF EQUIPE 10 - VERMELHA',
  'ESF DOURADA' => 'ESF DOURADA',
  'ESF EQUIPE T.R.E.' => 'ESF EQUIPE T.R.E.',
  'ESF EQUIPE 12 - OESTE 2' => 'ESF EQUIPE 12 - OESTE 2',
  'ESB OESTE SSB' => 'ESB OESTE SSB',
  'ESF EQUIPE MORRO AZUL' => 'ESF EQUIPE MORRO AZUL',
  'ESF EQUIPE 01 - NOVA BETANIA' => 'ESF EQUIPE 01 - NOVA BETANIA',
  'ESB NOVA BETANIA SSB' => 'ESB NOVA BETANIA SSB',
  'ESF EQUIPE 16 - SAO FRANCISCO' => 'ESF EQUIPE 16 - SAO FRANCISCO',
  'ESF EQUIPE 02 - MORRO DA CRUZ' => 'ESF EQUIPE 02 - MORRO DA CRUZ',
  'ESF EQUIPE 03 - CAVAS DE BAIXO' => 'ESF EQUIPE 03 - CAVAS DE BAIXO',
  'ESF EQ 21 - BOSQUE 1' => 'ESF EQ 21 - BOSQUE 1',
  'ESF EQUIPE 23 - VILA NOVA 2' => 'ESF EQUIPE 23 - VILA NOVA 2',
  'ESB BOSQUE 1 SSB' => 'ESB BOSQUE 1 SSB',
  'EMULTI IPE AMARELO' => 'EMULTI IPE AMARELO',
  'ESF EQUIPE 22 - JOAO CANDIDO' => 'ESF EQUIPE 22 - JOAO CANDIDO',
  'ESF EQUIPE 25 - BOSQUE 2' => 'ESF EQUIPE 25 - BOSQUE 2',
  'ESF EQUIPE 17 - SAO JOSE' => 'ESF EQUIPE 17 - SAO JOSE',
  'EQ. AMPLIADA 27 TIPO III CDP' => 'EQ. AMPLIADA 27 TIPO III CDP',
  'EQ. PSICOSOCIAL 30H - 2238861' => 'EQ. PSICOSOCIAL 30H - 2238861',
  'EQ. AMPLIADA ROSIMEIRE RODRIGUES' => 'EQ. AMPLIADA ROSIMEIRE RODRIGUES',
  'EQ. PSICOSOCIAL 30H - 2238853' => 'EQ. PSICOSOCIAL 30H - 2238853',
  'EQ. AMPLIADA 30H - 2238942' => 'EQ. AMPLIADA 30H - 2238942',
  'EQ. PSICOSOCIAL 30H - 2238969' => 'EQ. PSICOSOCIAL 30H - 2238969',
  'EQ. AMPLIADA CARLA MACHADO' => 'EQ. AMPLIADA CARLA MACHADO',
  'EQ. PSICOSOCIAL 30H - 2238837' => 'EQ. PSICOSOCIAL 30H - 2238837',
  'EQ. AMPLIADA 20H - 2238896' => 'EQ. AMPLIADA 20H - 2238896',
  'EQ. PSICOSOCIAL 20H - 2238918' => 'EQ. PSICOSOCIAL 20H - 2238918',
  'EQ. AMPLIADA EURICO JARDIM' => 'EQ. AMPLIADA EURICO JARDIM',
  'EQ. PSICOSOCIAL 30H - 2238845' => 'EQ. PSICOSOCIAL 30H - 2238845',
  'EQ. AMPLIADA 20H - 2238926' => 'EQ. AMPLIADA 20H - 2238926',
  'EQ. PSICOSOCIAL 20H - 2238934' => 'EQ. PSICOSOCIAL 20H - 2238934',
  'EQ. AMPLIADA ALFA' => 'EQ. AMPLIADA ALFA',
  'ESF EQUIPE VILA DO BOA' => 'ESF EQUIPE VILA DO BOA',
  'EQ. PSICOSOCIAL 30H - 2238888' => 'EQ. PSICOSOCIAL 30H - 2238888',
  'EQUIPE AMPLIADA 20H - 2502216' => 'EQUIPE AMPLIADA 20H - 2502216',
  'ESF DIAMANTE' => 'ESF DIAMANTE',
  'ESF AMETISTA' => 'ESF AMETISTA',
  'ESF SAFIRA' => 'ESF SAFIRA',
  'ESF ESMERALDA' => 'ESF ESMERALDA',
  'ESB 1 JARDINS MANGUEIRAL' => 'ESB 1 JARDINS MANGUEIRAL',
  'ESB 2 JARDINS MANGUEIRAL' => 'ESB 2 JARDINS MANGUEIRAL',
  'EMULTI RUBI' => 'EMULTI RUBI',
  'ESF TORORO' => 'ESF TORORO',
];

$requiredFields = [
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
  'ubs_ref',
  'team_ref',
];

$defaultAttendanceDate = date('Y-m-d');
if ($defaultAttendanceDate < PATIENT_FORM_MIN_DATE) {
  $defaultAttendanceDate = PATIENT_FORM_MIN_DATE;
} elseif ($defaultAttendanceDate > PATIENT_FORM_MAX_DATE) {
  $defaultAttendanceDate = PATIENT_FORM_MAX_DATE;
}

if ($editing) {
  require_permission($pdo, 'patients.update');
  $stmt = $pdo->prepare('SELECT * FROM patients WHERE id=:id AND deleted_at IS NULL');
  $stmt->execute(['id' => $id]);
  $row = $stmt->fetch();
  if (!$row) {
    echo 'Paciente nao encontrado.';
    exit;
  }
  $originalRow = $row;
} else {
  require_permission($pdo, 'patients.create');
  $row = [
    'first_cadh_date' => $defaultAttendanceDate,
    'full_name' => '',
    'ses' => '',
    'cpf' => null,
    'birth_date' => null,
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
    'ubs_ref' => '',
    'team_ref' => '',
  ];
  $originalRow = null;
}

$patientExtendedDefaults = [
  'email' => '',
  'emergency_contact' => '',
  'allergies' => '',
  'chronic_conditions' => '',
  'status' => 'ativo',
];

$row += $patientExtendedDefaults;
if (is_array($originalRow)) {
  $originalRow += $patientExtendedDefaults;
}

$row['sex'] = patient_form_normalize_option_value((string)($row['sex'] ?? ''), $genderOptions);
$row['race'] = patient_form_normalize_option_value((string)($row['race'] ?? ''), $raceOptions);
$row['status'] = patient_form_normalize_option_value((string)($row['status'] ?? 'ativo'), $statusOptions);
$row['team_ref'] = patient_form_normalize_option_value((string)($row['team_ref'] ?? ''), $teamOptions);
if ($row['team_ref'] === '') {
  $row['team_ref'] = 'sem_equipe';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $teamRef = patient_form_normalize_option_value(trim((string)($_POST['team_reference'] ?? $_POST['team_ref'] ?? '')), $teamOptions);
  if ($teamRef === '') {
    $teamRef = 'sem_equipe';
  }

  $data = [
    'first_cadh_date' => patient_form_normalize_optional($_POST['attendance_date'] ?? $_POST['first_cadh_date'] ?? null),
    'full_name' => patient_form_normalize_single_line((string)($_POST['full_name'] ?? '')),
    'ses' => '',
    'cpf' => patient_form_normalize_optional($_POST['cpf'] ?? null),
    'birth_date' => patient_form_normalize_optional($_POST['birth_date'] ?? null),
    'sex' => patient_form_normalize_option_value(trim((string)($_POST['gender'] ?? $_POST['sex'] ?? '')), $genderOptions),
    'race' => patient_form_normalize_option_value(trim((string)($_POST['race'] ?? '')), $raceOptions),
    'responsible_name' => patient_form_normalize_single_line((string)($_POST['responsible'] ?? $_POST['responsible_name'] ?? '')),
    'phone' => trim((string)($_POST['phone'] ?? '')),
    'address' => patient_form_normalize_single_line((string)($_POST['address'] ?? '')),
    'email' => trim((string)($_POST['email'] ?? $_POST['contact_email'] ?? '')),
    'emergency_contact' => trim((string)($_POST['emergency_contact'] ?? '')),
    'health_insurance' => '',
    'blood_type' => '',
    'allergies' => trim((string)($_POST['allergies'] ?? '')),
    'chronic_conditions' => trim((string)($_POST['chronic_conditions'] ?? '')),
    'status' => patient_form_normalize_option_value(trim((string)($_POST['status'] ?? 'ativo')), $statusOptions),
    'ubs_ref' => patient_form_normalize_single_line((string)($_POST['uds_reference'] ?? $_POST['ubs_ref'] ?? '')),
    'team_ref' => $teamRef,
  ];

  foreach ($requiredFields as $field) {
    $value = $data[$field] ?? null;
    if ($value === null || $value === '') {
      patient_form_set_error($errors, $field, 'Campo vazio. Preencha este campo.');
    }
  }

  if ($data['first_cadh_date'] !== null && !patient_form_is_valid_date($data['first_cadh_date'])) {
    patient_form_set_error($errors, 'first_cadh_date', 'Informe uma data valida.');
  }

  if ($data['first_cadh_date'] !== null && !patient_form_is_date_in_range($data['first_cadh_date'], PATIENT_FORM_MIN_DATE, PATIENT_FORM_MAX_DATE)) {
    patient_form_set_error($errors, 'first_cadh_date', 'Informe uma data entre 1900 e 2026.');
  }

  if ($data['birth_date'] !== null && !patient_form_is_valid_date($data['birth_date'])) {
    patient_form_set_error($errors, 'birth_date', 'Informe uma data valida.');
  }

  if ($data['birth_date'] !== null && !patient_form_is_date_in_range($data['birth_date'], PATIENT_FORM_MIN_DATE, PATIENT_FORM_MAX_DATE)) {
    patient_form_set_error($errors, 'birth_date', 'Informe uma data entre 1900 e 2026.');
  }

  if ($data['cpf'] !== null) {
    $cpfDigits = patient_form_digits_only($data['cpf']);

    if (!patient_form_is_valid_cpf($cpfDigits)) {
      patient_form_set_error($errors, 'cpf', 'Informe um CPF valido.');
    } else {
      $data['cpf'] = patient_form_format_cpf($cpfDigits);
    }
  }

  if ($data['sex'] !== '' && !array_key_exists($data['sex'], $genderOptions)) {
    patient_form_set_error($errors, 'sex', 'Selecione uma opcao valida para genero.');
  }

  if ($data['race'] !== '' && !array_key_exists($data['race'], $raceOptions)) {
    patient_form_set_error($errors, 'race', 'Selecione uma opcao valida para cor/raca.');
  }

  if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
    patient_form_set_error($errors, 'email', 'Informe um email valido.');
  }

  if ($data['status'] !== '' && !array_key_exists($data['status'], $statusOptions)) {
    patient_form_set_error($errors, 'status', 'Selecione um status valido.');
  }

  if ($data['team_ref'] !== '' && !array_key_exists($data['team_ref'], $teamOptions)) {
    patient_form_set_error($errors, 'team_ref', 'Selecione uma equipe valida.');
  }

  $phoneDigits = patient_form_digits_only($data['phone']);
  if ($data['phone'] !== '' && !patient_form_is_valid_phone($phoneDigits)) {
    patient_form_set_error($errors, 'phone', 'Telefone deve ter 10, 11 ou 3 digitos.');
  } elseif ($data['phone'] !== '') {
    $data['phone'] = patient_form_format_phone($phoneDigits);
  }

  $emergencyContactDigits = patient_form_digits_only($data['emergency_contact']);
  if ($data['emergency_contact'] !== '' && !patient_form_is_valid_phone($emergencyContactDigits)) {
    patient_form_set_error($errors, 'emergency_contact', 'Contato de emergencia deve ter 10, 11 ou 3 digitos.');
  } elseif ($data['emergency_contact'] !== '') {
    $data['emergency_contact'] = patient_form_format_phone($emergencyContactDigits);
  }

  foreach ($fieldMaxLengths as $field => $maxLength) {
    $value = (string)($data[$field] ?? '');

    if ($value !== '' && patient_form_strlen($value) > $maxLength) {
      patient_form_set_error(
        $errors,
        $field,
        $fieldLabels[$field] . ' deve ter no maximo ' . $maxLength . ' caracteres.'
      );
    }
  }

  $row = $data;

  if ($errors === []) {
    $pdo->beginTransaction();

    try {
      if ($editing) {
        $before = $originalRow;

        $stmt = $pdo->prepare(
          'UPDATE patients SET
            first_cadh_date=:first_cadh_date,
            full_name=:full_name,
            ses=:ses,
            cpf=:cpf,
            birth_date=:birth_date,
            sex=:sex,
            race=:race,
            responsible_name=:responsible_name,
            phone=:phone,
            address=:address,
            email=:email,
            emergency_contact=:emergency_contact,
            health_insurance=:health_insurance,
            blood_type=:blood_type,
            allergies=:allergies,
            chronic_conditions=:chronic_conditions,
            status=:status,
            ubs_ref=:ubs_ref,
            team_ref=:team_ref,
            updated_at=NOW()
          WHERE id=:id'
        );
        $stmt->execute($data + ['id' => $id]);

        Audit::log($pdo, current_user_id(), 'update', 'patients', $id, $before, $data);
      } else {
        $stmt = $pdo->prepare(
          'INSERT INTO patients
          (first_cadh_date, full_name, ses, cpf, birth_date, sex, race, responsible_name, phone, address, email, emergency_contact, health_insurance, blood_type, allergies, chronic_conditions, status, ubs_ref, team_ref)
          VALUES
          (:first_cadh_date, :full_name, :ses, :cpf, :birth_date, :sex, :race, :responsible_name, :phone, :address, :email, :emergency_contact, :health_insurance, :blood_type, :allergies, :chronic_conditions, :status, :ubs_ref, :team_ref)'
        );
        $stmt->execute($data);
        $newId = (int)$pdo->lastInsertId();

        Audit::log($pdo, current_user_id(), 'create', 'patients', $newId, null, $data);
      }

      $pdo->commit();
      redirect('/public/patients/list.php');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      $mappedError = patient_form_map_save_error($e);
      if ($mappedError['field'] !== null) {
        patient_form_set_error($errors, $mappedError['field'], $mappedError['message']);
        $error = 'Revise o campo destacado e tente novamente.';
      } else {
        $error = $mappedError['message'];
      }
    }
  }

  if ($errors !== [] && $error === '') {
    $error = 'Revise os campos destacados e tente novamente.';
  }
}
?>
<?php
$pageTitle = 'Novo Paciente';
require __DIR__ . '/../../app/views/layout/header.php';
?>
<style>
  .page-back-link {
    align-items: center;
    color: #183b6b;
    display: inline-flex;
    font-size: 1.1rem;
    font-weight: 600;
    gap: 10px;
    margin-bottom: 8px;
    text-decoration: none;
  }

  .page-subtitle {
    color: #4d6b95;
    font-size: 1.05rem;
    margin: 0 0 24px;
  }

  .patient-form {
    display: grid;
    gap: 28px;
  }

  .form-section {
    background: #ffffff;
    border: 1px solid #e6edf6;
    border-radius: 24px;
    box-shadow: 0 10px 24px rgba(15, 37, 65, 0.06);
    padding: 28px;
  }

  .form-section-title {
    color: #183b6b;
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    margin: 0 0 18px;
    text-transform: uppercase;
  }

  .form-grid {
    display: grid;
    gap: 22px 20px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .form-field {
    display: grid;
    gap: 8px;
  }

  .form-field-full {
    grid-column: 1 / -1;
  }

  .form-label {
    color: #274c7c;
    font-size: 0.98rem;
    font-weight: 600;
  }

  .required-marker {
    color: #d84b5f;
  }

  .form-control {
    background: #fff;
    border: 1px solid #d8e1ee;
    box-sizing: border-box;
    border-radius: 12px;
    color: #1e3e68;
    font-size: 1rem;
    min-height: 52px;
    padding: 0 15px;
    width: 100%;
  }

  .form-control::placeholder {
    color: #7c8da8;
  }

  .patient-form select,
  .patient-form textarea,
  .patient-form input {
    box-sizing: border-box;
  }

  .patient-form textarea.form-control {
    min-height: 128px;
    padding: 14px 15px;
    resize: vertical;
  }

  .field-error {
    color: #c62828;
    font-size: 0.9rem;
    margin: 4px 0 0;
  }

  .form-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
  }

  .btn-secondary,
  .btn-primary {
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 700;
    min-height: 48px;
    padding: 0 22px;
    text-decoration: none;
  }

  .btn-secondary {
    align-items: center;
    background: #fff;
    border: 1px solid #d8e1ee;
    color: #274c7c;
    display: inline-flex;
    justify-content: center;
  }

  .btn-primary {
    background: #183b6b;
    border: 1px solid #183b6b;
    color: #fff;
    cursor: pointer;
  }

  @media (max-width: 860px) {
    .form-grid {
      grid-template-columns: 1fr;
    }

    .form-actions {
      flex-direction: column-reverse;
    }

    .btn-secondary,
    .btn-primary {
      width: 100%;
    }
  }
</style>

<a class="page-back-link" href="/public/patients/list.php">&larr; Voltar</a>
<p class="page-subtitle">Preencha os dados para cadastrar o paciente</p>
<?php if ($error !== ''): ?>
  <p style="color:#c62828; margin-bottom:16px;"><?= h($error) ?></p>
<?php endif; ?>

<form method="post" class="patient-form">
  <?= csrf_field() ?>
  <input type="hidden" name="status" value="<?= h($row['status'] ?: 'ativo') ?>">

  <section class="form-section">
    <h2 class="form-section-title">Identificacao</h2>
    <div class="form-grid">
      <div class="form-field">
        <label class="form-label" for="attendance_date">Data de Atendimento <span class="required-marker">*</span></label>
        <input
          class="form-control"
          id="attendance_date"
          type="date"
          name="attendance_date"
          value="<?= h($row['first_cadh_date']) ?>"
          required
          min="<?= h(PATIENT_FORM_MIN_DATE) ?>"
          max="<?= h(PATIENT_FORM_MAX_DATE) ?>"
          style="<?= h(patient_form_field_style($errors, 'first_cadh_date')) ?>"
        >
        <?php if (patient_form_field_error($errors, 'first_cadh_date') !== ''): ?>
          <div class="field-error"><?= h(patient_form_field_error($errors, 'first_cadh_date')) ?></div>
        <?php endif; ?>
      </div>

      <div class="form-field">
        <label class="form-label" for="full_name">Nome Completo <span class="required-marker">*</span></label>
        <input
          class="form-control"
          id="full_name"
          name="full_name"
          value="<?= h($row['full_name']) ?>"
          required
          maxlength="180"
          placeholder="Nome completo do paciente"
          style="<?= h(patient_form_field_style($errors, 'full_name')) ?>"
        >
        <?php if (patient_form_field_error($errors, 'full_name') !== ''): ?>
          <div class="field-error"><?= h(patient_form_field_error($errors, 'full_name')) ?></div>
        <?php endif; ?>
      </div>

      <div class="form-field">
        <label class="form-label" for="cpf">CPF <span class="required-marker">*</span></label>
        <input
          class="form-control"
          id="cpf"
          name="cpf"
          value="<?= h($row['cpf']) ?>"
          required
          maxlength="14"
          inputmode="numeric"
          placeholder="000.000.000-00"
          style="<?= h(patient_form_field_style($errors, 'cpf')) ?>"
        >
        <?php if (patient_form_field_error($errors, 'cpf') !== ''): ?>
          <div class="field-error"><?= h(patient_form_field_error($errors, 'cpf')) ?></div>
        <?php endif; ?>
      </div>

      <div class="form-field">
        <label class="form-label" for="birth_date">Data de Nascimento <span class="required-marker">*</span></label>
        <input
          class="form-control"
          id="birth_date"
          type="date"
          name="birth_date"
          value="<?= h($row['birth_date']) ?>"
          required
          min="<?= h(PATIENT_FORM_MIN_DATE) ?>"
          max="<?= h(PATIENT_FORM_MAX_DATE) ?>"
          style="<?= h(patient_form_field_style($errors, 'birth_date')) ?>"
        >
        <?php if (patient_form_field_error($errors, 'birth_date') !== ''): ?>
          <div class="field-error"><?= h(patient_form_field_error($errors, 'birth_date')) ?></div>
        <?php endif; ?>
      </div>

      <div class="form-field">
        <label class="form-label" for="gender">Sexo <span class="required-marker">*</span></label>
        <select
          class="form-control"
          id="gender"
          name="gender"
          required
          style="<?= h(patient_form_field_style($errors, 'sex')) ?>"
        >
          <option value="">Selecione</option>
          <?php foreach ($genderOptions as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= ($row['sex'] === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (patient_form_field_error($errors, 'sex') !== ''): ?>
          <div class="field-error"><?= h(patient_form_field_error($errors, 'sex')) ?></div>
        <?php endif; ?>
      </div>

      <div class="form-field">
        <label class="form-label" for="race">Cor/Raca <span class="required-marker">*</span></label>
        <select
          class="form-control"
          id="race"
          name="race"
          required
          style="<?= h(patient_form_field_style($errors, 'race')) ?>"
        >
          <option value="">Selecione</option>
          <?php foreach ($raceOptions as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= ($row['race'] === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (patient_form_field_error($errors, 'race') !== ''): ?>
          <div class="field-error"><?= h(patient_form_field_error($errors, 'race')) ?></div>
        <?php endif; ?>
      </div>

      <div class="form-field">
        <label class="form-label" for="responsible">Responsavel <span class="required-marker">*</span></label>
        <input
          class="form-control"
          id="responsible"
          name="responsible"
          value="<?= h($row['responsible_name']) ?>"
          required
          maxlength="180"
          placeholder="Nome do responsavel"
          style="<?= h(patient_form_field_style($errors, 'responsible_name')) ?>"
        >
        <?php if (patient_form_field_error($errors, 'responsible_name') !== ''): ?>
          <div class="field-error"><?= h(patient_form_field_error($errors, 'responsible_name')) ?></div>
        <?php endif; ?>
      </div>

      <div class="form-field">
        <label class="form-label" for="team_reference">Equipe <span class="required-marker">*</span></label>
        <select
          class="form-control"
          id="team_reference"
          name="team_ref"
          required
          style="<?= h(patient_form_field_style($errors, 'team_ref')) ?>"
        >
          <option value="">Selecione</option>
          <?php foreach ($teamOptions as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= ($row['team_ref'] === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (patient_form_field_error($errors, 'team_ref') !== ''): ?>
          <div class="field-error"><?= h(patient_form_field_error($errors, 'team_ref')) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="form-section">
    <h2 class="form-section-title">Contato</h2>
    <div class="form-grid">
      <div class="form-field">
        <label class="form-label" for="phone">Telefone <span class="required-marker">*</span></label>
        <input
          class="form-control"
          id="phone"
          name="phone"
          value="<?= h($row['phone']) ?>"
          required
          maxlength="15"
          inputmode="numeric"
          placeholder="(00) 00000-0000"
          style="<?= h(patient_form_field_style($errors, 'phone')) ?>"
        >
        <?php if (patient_form_field_error($errors, 'phone') !== ''): ?>
          <div class="field-error"><?= h(patient_form_field_error($errors, 'phone')) ?></div>
        <?php endif; ?>
      </div>

      <div class="form-field">
        <label class="form-label" for="email">Email <span class="required-marker">*</span></label>
        <input
          class="form-control"
          id="email"
          name="email"
          type="email"
          value="<?= h($row['email']) ?>"
          required
          maxlength="190"
          placeholder="email@exemplo.com"
          style="<?= h(patient_form_field_style($errors, 'email')) ?>"
        >
        <?php if (patient_form_field_error($errors, 'email') !== ''): ?>
          <div class="field-error"><?= h(patient_form_field_error($errors, 'email')) ?></div>
        <?php endif; ?>
      </div>

      <div class="form-field">
        <label class="form-label" for="address">Endereco <span class="required-marker">*</span></label>
        <input
          class="form-control"
          id="address"
          name="address"
          value="<?= h($row['address']) ?>"
          required
          maxlength="255"
          placeholder="Rua, numero, bairro, cidade"
          style="<?= h(patient_form_field_style($errors, 'address')) ?>"
        >
        <?php if (patient_form_field_error($errors, 'address') !== ''): ?>
          <div class="field-error"><?= h(patient_form_field_error($errors, 'address')) ?></div>
        <?php endif; ?>
      </div>

      <div class="form-field">
        <label class="form-label" for="emergency_contact">Contato de Emergencia <span class="required-marker">*</span></label>
        <input
          class="form-control"
          id="emergency_contact"
          name="emergency_contact"
          value="<?= h($row['emergency_contact']) ?>"
          required
          maxlength="15"
          inputmode="numeric"
          placeholder="(00) 00000-0000"
          style="<?= h(patient_form_field_style($errors, 'emergency_contact')) ?>"
        >
        <?php if (patient_form_field_error($errors, 'emergency_contact') !== ''): ?>
          <div class="field-error"><?= h(patient_form_field_error($errors, 'emergency_contact')) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="form-section">
    <h2 class="form-section-title">Referencia</h2>
    <div class="form-grid">
      <div class="form-field">
        <label class="form-label" for="uds_reference">UDS Referencia <span class="required-marker">*</span></label>
        <input
          class="form-control"
          id="uds_reference"
          name="uds_reference"
          value="<?= h($row['ubs_ref']) ?>"
          required
          maxlength="120"
          placeholder="Unidade de saude de referencia"
          style="<?= h(patient_form_field_style($errors, 'ubs_ref')) ?>"
        >
        <?php if (patient_form_field_error($errors, 'ubs_ref') !== ''): ?>
          <div class="field-error"><?= h(patient_form_field_error($errors, 'ubs_ref')) ?></div>
        <?php endif; ?>
      </div>

    </div>
  </section>

  <section class="form-section">
    <h2 class="form-section-title">Informacoes Clinicas</h2>
    <div class="form-grid">
      <div class="form-field">
        <label class="form-label" for="allergies">Alergias <span class="required-marker">*</span></label>
        <textarea
          class="form-control"
          id="allergies"
          name="allergies"
          required
          style="<?= h(patient_form_field_style($errors, 'allergies')) ?>"
          placeholder="Liste as alergias conhecidas"
        ><?= h($row['allergies']) ?></textarea>
        <?php if (patient_form_field_error($errors, 'allergies') !== ''): ?>
          <div class="field-error"><?= h(patient_form_field_error($errors, 'allergies')) ?></div>
        <?php endif; ?>
      </div>

      <div class="form-field">
        <label class="form-label" for="chronic_conditions">Condicoes Cronicas <span class="required-marker">*</span></label>
        <textarea
          class="form-control"
          id="chronic_conditions"
          name="chronic_conditions"
          required
          style="<?= h(patient_form_field_style($errors, 'chronic_conditions')) ?>"
          placeholder="Liste as condicoes cronicas"
        ><?= h($row['chronic_conditions']) ?></textarea>
        <?php if (patient_form_field_error($errors, 'chronic_conditions') !== ''): ?>
          <div class="field-error"><?= h(patient_form_field_error($errors, 'chronic_conditions')) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <div class="form-actions">
    <a class="btn-secondary" href="/public/patients/list.php">Cancelar</a>
    <button class="btn-primary" type="submit">Salvar paciente</button>
  </div>
</form>

<script>
  const cpfInput = document.querySelector('input[name="cpf"]');
  const phoneInput = document.querySelector('input[name="phone"]');
  const emergencyContactInput = document.querySelector('input[name="emergency_contact"]');

  function digitsOnly(value) {
    return value.replace(/\D+/g, '');
  }

  function formatCpf(value) {
    const digits = digitsOnly(value).slice(0, 11);

    if (digits.length <= 3) return digits;
    if (digits.length <= 6) return digits.slice(0, 3) + '.' + digits.slice(3);
    if (digits.length <= 9) return digits.slice(0, 3) + '.' + digits.slice(3, 6) + '.' + digits.slice(6);
    return digits.slice(0, 3) + '.' + digits.slice(3, 6) + '.' + digits.slice(6, 9) + '-' + digits.slice(9);
  }

  function formatPhone(value) {
    const digits = digitsOnly(value).slice(0, 11);

    if (digits.length <= 3) return digits;
    if (digits.length <= 6) return '(' + digits.slice(0, 2) + ') ' + digits.slice(2);
    if (digits.length <= 10) return '(' + digits.slice(0, 2) + ') ' + digits.slice(2, 6) + '-' + digits.slice(6);
    return '(' + digits.slice(0, 2) + ') ' + digits.slice(2, 7) + '-' + digits.slice(7);
  }

  if (cpfInput) {
    cpfInput.addEventListener('input', () => {
      cpfInput.value = formatCpf(cpfInput.value);
    });
    cpfInput.value = formatCpf(cpfInput.value);
  }

  if (phoneInput) {
    phoneInput.addEventListener('input', () => {
      phoneInput.value = formatPhone(phoneInput.value);
    });
    phoneInput.value = formatPhone(phoneInput.value);
  }

  if (emergencyContactInput) {
    emergencyContactInput.addEventListener('input', () => {
      emergencyContactInput.value = formatPhone(emergencyContactInput.value);
    });
    emergencyContactInput.value = formatPhone(emergencyContactInput.value);
  }
</script>

<?php require __DIR__ . '/../../app/views/layout/footer.php'; ?>
