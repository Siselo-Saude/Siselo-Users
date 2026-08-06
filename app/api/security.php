<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/User.php';

function api_require_user_id(): int {
  $userId = current_user_id();
  if ($userId === null) {
    api_error('Nao autenticado.', 401);
  }

  return (int)$userId;
}

function api_current_user(PDO $pdo): array {
  $user = User::findById($pdo, api_require_user_id());
  if ($user === null) {
    api_error('Sessao invalida.', 401);
  }

  $accessBlockMessage = User::accessBlockMessage($user);
  if ($accessBlockMessage !== null) {
    api_error($accessBlockMessage, 403);
  }

  return $user;
}

function api_require_permission(PDO $pdo, string $permission): int {
  $userId = (int)api_current_user($pdo)['id'];
  if (!can($pdo, $permission)) {
    api_error('Sem permissao: ' . $permission, 403);
  }

  return $userId;
}

function api_require_user_type(PDO $pdo, array $allowedTypes): array {
  $user = api_current_user($pdo);
  if (can($pdo, 'admin.manage')) {
    return $user;
  }

  $allowed = array_map(static fn(string $type): string => strtoupper(trim($type)), $allowedTypes);
  $userType = strtoupper(trim((string)($user['user_type'] ?? '')));
  if (!in_array($userType, $allowed, true)) {
    api_error('Acao indisponivel para este perfil assistencial.', 403);
  }

  return $user;
}

function api_require_record_author(PDO $pdo, string $table, int $recordId, string $authorField = 'created_by_user_id'): void {
  if ($recordId <= 0 || can($pdo, 'admin.manage')) {
    return;
  }
  $allowedTables = ['care_plans', 'encounters', 'transitions'];
  $allowedFields = ['created_by_user_id', 'professional_user_id'];
  if (!in_array($table, $allowedTables, true) || !in_array($authorField, $allowedFields, true)) {
    api_error('Regra de autoria invalida.', 500);
  }

  $stmt = $pdo->prepare("SELECT {$authorField} FROM {$table} WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => $recordId]);
  $authorId = $stmt->fetchColumn();
  if ($authorId !== false && $authorId !== null && (int)$authorId !== api_require_user_id()) {
    api_error('Somente o autor ou um administrador pode alterar este registro.', 403);
  }
}

function api_verify_csrf(): void {
  $token = api_request_header('X-CSRF-Token');
  if ($token === null) {
    $input = api_request_input();
    $token = isset($input['csrf']) ? (string)$input['csrf'] : '';
  }

  if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    api_error('CSRF invalido.', 403);
  }
}
