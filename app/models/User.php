<?php
declare(strict_types=1);

require_once __DIR__ . '/Role.php';
require_once __DIR__ . '/../services/Audit.php';

final class User {
  private static function deletedEmailFor(int $id): string {
    return sprintf('deleted+user-%d-%d@local.invalid', $id, time());
  }

  private static function releaseDeletedEmailReservation(PDO $pdo, string $email, ?int $excludeId = null, ?int $actorUserId = null): void {
    $sql = 'SELECT * FROM users WHERE email = :email AND deleted_at IS NOT NULL';
    $params = [':email' => $email];

    if ($excludeId !== null) {
      $sql .= ' AND id <> :id';
      $params[':id'] = $excludeId;
    }

    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $deletedUser = $stmt->fetch();
    if ($deletedUser === false) {
      return;
    }

    $deletedUserId = (int)$deletedUser['id'];
    $replacementEmail = self::deletedEmailFor($deletedUserId);
    $update = $pdo->prepare('UPDATE users SET email = :email, updated_at = NOW() WHERE id = :id');
    $update->execute([
      ':email' => $replacementEmail,
      ':id' => $deletedUserId,
    ]);

    if ($actorUserId !== null) {
      Audit::log($pdo, $actorUserId, 'update', 'users', $deletedUserId, $deletedUser, [
        'email' => $replacementEmail,
        'deleted_email_released' => true,
      ]);
    }
  }

  public static function findByEmail(PDO $pdo, string $email): ?array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch();

    return $row ?: null;
  }

  public static function findById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
  }

  public static function findByExternalIdentity(PDO $pdo, string $provider, string $username): ?array {
    $stmt = $pdo->prepare('
      SELECT *
      FROM users
      WHERE external_provider = :provider
        AND external_username = :username
        AND deleted_at IS NULL
      LIMIT 1
    ');
    $stmt->execute([
      ':provider' => $provider,
      ':username' => $username,
    ]);
    $row = $stmt->fetch();

    return $row ?: null;
  }

  public static function apiPayload(PDO $pdo, array $user): array {
    $userId = (int)$user['id'];

    return [
      'id' => $userId,
      'name' => (string)$user['name'],
      'email' => (string)$user['email'],
      'is_active' => (int)$user['is_active'],
      'is_approved' => (int)($user['is_approved'] ?? 1),
      'approved_at' => isset($user['approved_at']) ? (string)$user['approved_at'] : null,
      'approved_by_user_id' => isset($user['approved_by_user_id']) ? (int)$user['approved_by_user_id'] : null,
      'must_change_password' => (int)$user['must_change_password'],
      'user_type' => isset($user['user_type']) ? (string)$user['user_type'] : null,
      'specialty' => isset($user['specialty']) ? (string)$user['specialty'] : null,
      'external_provider' => isset($user['external_provider']) ? (string)$user['external_provider'] : null,
      'profile_completed' => (int)($user['profile_completed'] ?? 1),
      'roles' => Role::namesForUser($pdo, $userId),
      'permissions' => array_values(array_keys(user_permissions($pdo, $userId))),
    ];
  }

  public static function authSpecialties(): array {
    return [
      'Endocrinologia',
      'Cardiologia',
      'Psicologia',
      'Enfermagem',
      'Nutrição',
      'Fisioterapia',
      'Farmácia Clínica',
      'Serviço Social',
      'Oftalmologia',
      'Nefrologia',
      'Técnico de Enfermagem',
      'Gestão do Cuidado',
    ];
  }

  public static function accessBlockMessage(?array $user): ?string {
    if ($user === null) {
      return null;
    }

    if ((int)($user['is_approved'] ?? 1) !== 1) {
      return 'Sua conta ainda esta aguardando aprovacao do administrador.';
    }

    if ((int)($user['is_active'] ?? 0) !== 1) {
      return 'Sua conta esta inativa. Fale com o administrador.';
    }

    return null;
  }

  public static function hasRequiredProfile(array $user): bool {
    $userType = strtoupper(trim((string)($user['user_type'] ?? '')));
    $specialty = trim((string)($user['specialty'] ?? ''));

    if (!in_array($userType, ['CADH', 'UBS'], true)) {
      return false;
    }

    return $userType !== 'CADH' || in_array($specialty, self::authSpecialties(), true);
  }

  public static function syncFromCoopere(PDO $pdo, array $identity): array {
    $provider = trim((string)($identity['provider'] ?? 'coopere'));
    $username = trim((string)($identity['username'] ?? ''));
    $name = trim((string)($identity['name'] ?? ''));
    $email = trim((string)($identity['email'] ?? ''));
    $group = trim((string)($identity['group'] ?? ''));

    if ($provider === '' || $username === '' || $name === '' || $email === '') {
      throw new RuntimeException('Identidade Coopere incompleta.');
    }

    if (strlen($provider) > 40 || strlen($username) > 120 || strlen($name) > 120 || strlen($group) > 190) {
      throw new RuntimeException('Identidade Coopere excede o tamanho permitido.');
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
      throw new RuntimeException('Email da Coopere invalido.');
    }

    $roleId = Role::idByName($pdo, 'alimentador');
    if ($roleId === null) {
      throw new RuntimeException('Perfil alimentador nao encontrado.');
    }

    $existing = self::findByExternalIdentity($pdo, $provider, $username);
    if ($existing === null) {
      $existing = self::findByEmail($pdo, $email);
    }

    $emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1');
    $emailStmt->execute([':email' => $email]);
    $emailUserId = $emailStmt->fetchColumn();
    if ($emailUserId !== false && ($existing === null || (int)$emailUserId !== (int)$existing['id'])) {
      throw new RuntimeException('Ja existe uma conta com este email.');
    }

    $pdo->beginTransaction();

    try {
      self::releaseDeletedEmailReservation($pdo, $email, $existing !== null ? (int)$existing['id'] : null);

      if ($existing !== null) {
        $userId = (int)$existing['id'];
        $profileCompleted = self::hasRequiredProfile($existing) ? 1 : 0;
        $stmt = $pdo->prepare('
          UPDATE users
          SET
            name = :name,
            email = :email,
            is_active = 1,
            is_approved = 1,
            approved_at = COALESCE(approved_at, NOW()),
            must_change_password = 0,
            external_provider = :provider,
            external_username = :username,
            external_group = :external_group,
            profile_completed = :profile_completed,
            last_external_login_at = NOW(),
            updated_at = NOW()
          WHERE id = :id
        ');
        $stmt->execute([
          ':name' => $name,
          ':email' => $email,
          ':provider' => $provider,
          ':username' => $username,
          ':external_group' => $group !== '' ? $group : null,
          ':profile_completed' => $profileCompleted,
          ':id' => $userId,
        ]);

        if (Role::idsForUser($pdo, $userId) === []) {
          Role::syncUserRoles($pdo, $userId, [$roleId]);
        }

        Audit::log($pdo, $userId, 'update', 'users', $userId, $existing, [
          'name' => $name,
          'email' => $email,
          'is_active' => 1,
          'is_approved' => 1,
          'external_provider' => $provider,
          'external_username' => $username,
          'external_group' => $group !== '' ? $group : null,
          'profile_completed' => $profileCompleted,
          'external_login' => true,
        ]);
      } else {
        $stmt = $pdo->prepare('
          INSERT INTO users (
            name,
            email,
            password_hash,
            is_active,
            is_approved,
            approved_at,
            approved_by_user_id,
            must_change_password,
            user_type,
            specialty,
            external_provider,
            external_username,
            external_group,
            profile_completed,
            last_external_login_at
          )
          VALUES (
            :name,
            :email,
            :password_hash,
            1,
            1,
            NOW(),
            NULL,
            0,
            NULL,
            NULL,
            :provider,
            :username,
            :external_group,
            0,
            NOW()
          )
        ');
        $stmt->execute([
          ':name' => $name,
          ':email' => $email,
          ':password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
          ':provider' => $provider,
          ':username' => $username,
          ':external_group' => $group !== '' ? $group : null,
        ]);

        $userId = (int)$pdo->lastInsertId();
        Role::syncUserRoles($pdo, $userId, [$roleId]);

        Audit::log($pdo, $userId, 'create', 'users', $userId, null, [
          'name' => $name,
          'email' => $email,
          'is_active' => 1,
          'is_approved' => 1,
          'external_provider' => $provider,
          'external_username' => $username,
          'external_group' => $group !== '' ? $group : null,
          'profile_completed' => 0,
          'role' => 'alimentador',
          'external_login' => true,
        ]);
      }

      $pdo->commit();

      $user = self::findById($pdo, $userId);
      if ($user === null) {
        throw new RuntimeException('Usuario criado, mas nao foi possivel carregar a conta.');
      }

      return $user;
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      if ($error instanceof PDOException && str_contains($error->getMessage(), 'users.email')) {
        throw new RuntimeException('Ja existe uma conta com este email.');
      }

      throw $error;
    }
  }

  public static function completeExternalProfile(PDO $pdo, int $id, array $payload): array {
    $before = self::findById($pdo, $id);
    if ($before === null) {
      throw new RuntimeException('Usuario nao encontrado.');
    }

    $userType = strtoupper(trim((string)($payload['user_type'] ?? '')));
    $specialty = trim((string)($payload['specialty'] ?? ''));

    if (!in_array($userType, ['CADH', 'UBS'], true)) {
      throw new RuntimeException('Tipo de usuario invalido.');
    }

    if ($userType === 'CADH' && !in_array($specialty, self::authSpecialties(), true)) {
      throw new RuntimeException('Especialidade obrigatoria para usuarios CADH.');
    }

    if ($userType === 'UBS') {
      $specialty = '';
    }

    $stmt = $pdo->prepare('
      UPDATE users
      SET
        user_type = :user_type,
        specialty = :specialty,
        profile_completed = 1,
        updated_at = NOW()
      WHERE id = :id
    ');
    $stmt->execute([
      ':user_type' => $userType,
      ':specialty' => $specialty !== '' ? $specialty : null,
      ':id' => $id,
    ]);

    Audit::log($pdo, $id, 'update', 'users', $id, $before, [
      'user_type' => $userType,
      'specialty' => $specialty !== '' ? $specialty : null,
      'profile_completed' => 1,
      'external_profile_completed' => true,
    ]);

    $user = self::findById($pdo, $id);
    if ($user === null) {
      throw new RuntimeException('Perfil atualizado, mas nao foi possivel carregar a conta.');
    }

    return $user;
  }

  public static function registerPublic(PDO $pdo, array $payload): array {
    $name = trim((string)($payload['name'] ?? ''));
    $email = trim((string)($payload['email'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    $userType = strtoupper(trim((string)($payload['user_type'] ?? '')));
    $specialty = trim((string)($payload['specialty'] ?? ''));

    if ($name === '' || $email === '' || $password === '' || $userType === '') {
      throw new RuntimeException('Nome, email, senha e tipo de usuario sao obrigatorios.');
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
      throw new RuntimeException('Email invalido.');
    }

    if (strlen($name) > 120) {
      throw new RuntimeException('Nome deve ter no maximo 120 caracteres.');
    }

    if (strlen($password) < 8) {
      throw new RuntimeException('A senha deve ter pelo menos 8 caracteres.');
    }

    if (!in_array($userType, ['CADH', 'UBS'], true)) {
      throw new RuntimeException('Tipo de usuario invalido.');
    }

    if ($userType === 'CADH' && !in_array($specialty, self::authSpecialties(), true)) {
      throw new RuntimeException('Especialidade obrigatoria para usuarios CADH.');
    }

    if ($userType === 'UBS') {
      $specialty = '';
    }

    $emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1');
    $emailStmt->execute([':email' => $email]);
    if ($emailStmt->fetchColumn() !== false) {
      throw new RuntimeException('Ja existe uma conta com este email.');
    }

    $roleId = Role::idByName($pdo, 'alimentador');
    if ($roleId === null) {
      throw new RuntimeException('Perfil alimentador nao encontrado.');
    }

    $pdo->beginTransaction();

    try {
      self::releaseDeletedEmailReservation($pdo, $email);

      $stmt = $pdo->prepare('
        INSERT INTO users (name, email, password_hash, is_active, is_approved, approved_at, approved_by_user_id, must_change_password, user_type, specialty)
        VALUES (:name, :email, :password_hash, 1, 0, NULL, NULL, 0, :user_type, :specialty)
      ');
      $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':user_type' => $userType,
        ':specialty' => $specialty !== '' ? $specialty : null,
      ]);

      $userId = (int)$pdo->lastInsertId();
      Role::syncUserRoles($pdo, $userId, [$roleId]);

      Audit::log($pdo, $userId, 'create', 'users', $userId, null, [
        'name' => $name,
        'email' => $email,
        'is_active' => 1,
        'is_approved' => 0,
        'must_change_password' => 0,
        'user_type' => $userType,
        'specialty' => $specialty !== '' ? $specialty : null,
        'role' => 'alimentador',
        'self_registered' => true,
      ]);

      $pdo->commit();

      $user = self::findById($pdo, $userId);
      if ($user === null) {
        throw new RuntimeException('Usuario criado, mas nao foi possivel carregar a conta.');
      }

      return $user;
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      if ($error instanceof PDOException && str_contains($error->getMessage(), 'users.email')) {
        throw new RuntimeException('Ja existe uma conta com este email.');
      }

      throw $error;
    }
  }

  public static function listForAdmin(PDO $pdo, string $query = ''): array {
    $sql = 'SELECT u.* FROM users u WHERE u.deleted_at IS NULL';
    $params = [];

    if ($query !== '') {
      $sql .= ' AND (u.name LIKE :q_name OR u.email LIKE :q_email)';
      $params[':q_name'] = '%' . $query . '%';
      $params[':q_email'] = '%' . $query . '%';
    }

    $sql .= ' ORDER BY u.is_approved ASC, u.id DESC LIMIT 300';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return array_map(
      static function (array $row) use ($pdo): array {
        $row['id'] = (int)$row['id'];
        $row['is_active'] = (int)$row['is_active'];
        $row['is_approved'] = (int)($row['is_approved'] ?? 1);
        $row['approved_by_user_id'] = isset($row['approved_by_user_id']) ? (int)$row['approved_by_user_id'] : null;
        $row['approved_at'] = isset($row['approved_at']) ? (string)$row['approved_at'] : null;
        $row['must_change_password'] = (int)$row['must_change_password'];
        $row['user_type'] = isset($row['user_type']) ? (string)$row['user_type'] : '';
        $row['specialty'] = isset($row['specialty']) ? (string)$row['specialty'] : '';
        $row['roles'] = Role::namesForUser($pdo, (int)$row['id']);
        $row['role_ids'] = Role::idsForUser($pdo, (int)$row['id']);
        return $row;
      },
      $rows
    );
  }

  public static function formContext(PDO $pdo, ?int $id = null): array {
    $user = [
      'name' => '',
      'email' => '',
      'is_active' => 1,
      'is_approved' => 1,
      'user_type' => '',
      'specialty' => '',
      'role_ids' => [],
    ];
    $editing = $id !== null;

    if ($editing) {
      $row = self::findById($pdo, $id);
      if ($row === null) {
        return [
          'editing' => true,
          'user' => null,
          'roles' => Role::all($pdo),
          'error' => 'Usuario nao encontrado.',
        ];
      }

      $user = [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'email' => (string)$row['email'],
        'is_active' => (int)$row['is_active'],
        'is_approved' => (int)($row['is_approved'] ?? 1),
        'approved_at' => isset($row['approved_at']) ? (string)$row['approved_at'] : null,
        'user_type' => isset($row['user_type']) ? (string)$row['user_type'] : '',
        'specialty' => isset($row['specialty']) ? (string)$row['specialty'] : '',
        'role_ids' => Role::idsForUser($pdo, (int)$row['id']),
      ];
    }

    return [
      'editing' => $editing,
      'user' => $user,
      'roles' => Role::all($pdo),
      'error' => null,
    ];
  }

  public static function save(PDO $pdo, ?int $id, array $payload, int $actorUserId): array {
    $editing = $id !== null;
    $name = trim((string)($payload['name'] ?? ''));
    $email = trim((string)($payload['email'] ?? ''));
    $isActive = !empty($payload['is_active']) ? 1 : 0;
    $userType = strtoupper(trim((string)($payload['user_type'] ?? '')));
    $specialty = trim((string)($payload['specialty'] ?? ''));
    $roleIds = array_values(array_map('intval', (array)($payload['role_ids'] ?? [])));

    if ($name === '' || $email === '') {
      throw new RuntimeException('Nome e email sao obrigatorios.');
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
      throw new RuntimeException('Email invalido.');
    }

    if ($userType !== '' && !in_array($userType, ['CADH', 'UBS'], true)) {
      throw new RuntimeException('Tipo de usuario invalido.');
    }

    if ($userType === 'CADH' && !in_array($specialty, self::authSpecialties(), true)) {
      throw new RuntimeException('Especialidade obrigatoria para usuarios CADH.');
    }

    if ($userType !== 'CADH') {
      $specialty = '';
    }

    $emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND deleted_at IS NULL AND id <> :id LIMIT 1');
    $emailStmt->execute([
      ':email' => $email,
      ':id' => $editing ? $id : 0,
    ]);
    if ($emailStmt->fetchColumn() !== false) {
      throw new RuntimeException('Ja existe uma conta com este email.');
    }

    $pdo->beginTransaction();

    try {
      self::releaseDeletedEmailReservation($pdo, $email, $editing ? $id : null, $actorUserId);

      if ($editing) {
        $before = self::findById($pdo, $id);
        if ($before === null) {
          throw new RuntimeException('Usuario nao encontrado.');
        }

        $stmt = $pdo->prepare('
          UPDATE users
          SET
            name = :name,
            email = :email,
            is_active = :is_active,
            user_type = :user_type,
            specialty = :specialty,
            updated_at = NOW()
          WHERE id = :id
        ');
        $stmt->execute([
          ':name' => $name,
          ':email' => $email,
          ':is_active' => $isActive,
          ':user_type' => $userType !== '' ? $userType : null,
          ':specialty' => $specialty !== '' ? $specialty : null,
          ':id' => $id,
        ]);

        Role::syncUserRoles($pdo, $id, $roleIds);

        Audit::log($pdo, $actorUserId, 'update', 'users', $id, $before, [
          'name' => $name,
          'email' => $email,
          'is_active' => $isActive,
          'user_type' => $userType !== '' ? $userType : null,
          'specialty' => $specialty !== '' ? $specialty : null,
          'role_ids' => $roleIds,
        ]);

        $userId = $id;
      } else {
        $tempPassword = (string)($payload['temp_password'] ?? '');
        if ($tempPassword === '') {
          throw new RuntimeException('Senha temporaria obrigatoria.');
        }

        $stmt = $pdo->prepare('
          INSERT INTO users (name, email, password_hash, is_active, is_approved, approved_at, approved_by_user_id, must_change_password, user_type, specialty)
          VALUES (:name, :email, :password_hash, :is_active, 1, NOW(), :approved_by_user_id, 0, :user_type, :specialty)
        ');
        $stmt->execute([
          ':name' => $name,
          ':email' => $email,
          ':password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT),
          ':is_active' => $isActive,
          ':approved_by_user_id' => $actorUserId,
          ':user_type' => $userType !== '' ? $userType : null,
          ':specialty' => $specialty !== '' ? $specialty : null,
        ]);

        $userId = (int)$pdo->lastInsertId();
        Role::syncUserRoles($pdo, $userId, $roleIds);

        Audit::log($pdo, $actorUserId, 'create', 'users', $userId, null, [
          'name' => $name,
          'email' => $email,
          'is_active' => $isActive,
          'is_approved' => 1,
          'user_type' => $userType !== '' ? $userType : null,
          'specialty' => $specialty !== '' ? $specialty : null,
          'role_ids' => $roleIds,
        ]);
      }

      $pdo->commit();
      return self::formContext($pdo, $userId);
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      if ($error instanceof PDOException && str_contains($error->getMessage(), 'users.email')) {
        throw new RuntimeException('Ja existe uma conta com este email.');
      }

      throw $error;
    }
  }

  public static function saveSelfProfile(PDO $pdo, int $id, array $payload): array {
    $before = self::findById($pdo, $id);
    if ($before === null) {
      throw new RuntimeException('Usuario nao encontrado.');
    }

    $name = trim((string)($payload['name'] ?? ''));
    $email = trim((string)($payload['email'] ?? ''));
    $userType = strtoupper(trim((string)($payload['user_type'] ?? '')));
    $specialty = trim((string)($payload['specialty'] ?? ''));

    if ($name === '' || $email === '') {
      throw new RuntimeException('Nome e email sao obrigatorios.');
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
      throw new RuntimeException('Email invalido.');
    }

    if ($userType !== '' && !in_array($userType, ['CADH', 'UBS'], true)) {
      throw new RuntimeException('Tipo de usuario invalido.');
    }

    if ($userType === 'CADH' && !in_array($specialty, self::authSpecialties(), true)) {
      throw new RuntimeException('Especialidade obrigatoria para usuarios CADH.');
    }

    if ($userType !== 'CADH') {
      $specialty = '';
    }

    $emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND deleted_at IS NULL AND id <> :id LIMIT 1');
    $emailStmt->execute([
      ':email' => $email,
      ':id' => $id,
    ]);
    if ($emailStmt->fetchColumn() !== false) {
      throw new RuntimeException('Ja existe uma conta com este email.');
    }

    $pdo->beginTransaction();

    try {
      self::releaseDeletedEmailReservation($pdo, $email, $id, $id);

      $stmt = $pdo->prepare('
        UPDATE users
        SET
          name = :name,
          email = :email,
          user_type = :user_type,
          specialty = :specialty,
          updated_at = NOW()
        WHERE id = :id
      ');
      $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':user_type' => $userType !== '' ? $userType : null,
        ':specialty' => $specialty !== '' ? $specialty : null,
        ':id' => $id,
      ]);

      Audit::log($pdo, $id, 'update', 'users', $id, $before, [
        'name' => $name,
        'email' => $email,
        'user_type' => $userType !== '' ? $userType : null,
        'specialty' => $specialty !== '' ? $specialty : null,
        'self_profile' => true,
      ]);

      $pdo->commit();
      return self::formContext($pdo, $id);
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      if ($error instanceof PDOException && str_contains($error->getMessage(), 'users.email')) {
        throw new RuntimeException('Ja existe uma conta com este email.');
      }

      throw $error;
    }
  }

  public static function toggleActive(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::findById($pdo, $id);
    if ($before === null) {
      return null;
    }

    $newValue = ((int)$before['is_active'] === 1) ? 0 : 1;
    $stmt = $pdo->prepare('UPDATE users SET is_active = :is_active, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
      ':is_active' => $newValue,
      ':id' => $id,
    ]);

    Audit::log($pdo, $actorUserId, 'update', 'users', $id, $before, ['is_active' => $newValue]);

    $after = self::findById($pdo, $id);
    return $after !== null ? self::apiPayload($pdo, $after) : null;
  }

  public static function approve(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::findById($pdo, $id);
    if ($before === null) {
      return null;
    }

    $stmt = $pdo->prepare('
      UPDATE users
      SET
        is_approved = 1,
        approved_at = COALESCE(approved_at, NOW()),
        approved_by_user_id = :approved_by_user_id,
        updated_at = NOW()
      WHERE id = :id
    ');
    $stmt->execute([
      ':approved_by_user_id' => $actorUserId,
      ':id' => $id,
    ]);

    Audit::log($pdo, $actorUserId, 'approve', 'users', $id, $before, [
      'is_approved' => 1,
      'approved_by_user_id' => $actorUserId,
    ]);

    $after = self::findById($pdo, $id);
    return $after !== null ? self::apiPayload($pdo, $after) : null;
  }

  public static function softDelete(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::findById($pdo, $id);
    if ($before === null) {
      return null;
    }

    if ($id === $actorUserId) {
      throw new RuntimeException('Nao e permitido excluir o proprio usuario.');
    }

    $deletedEmail = self::deletedEmailFor($id);

    $stmt = $pdo->prepare('
      UPDATE users
      SET email = :deleted_email, deleted_at = NOW(), updated_at = NOW()
      WHERE id = :id
    ');
    $stmt->execute([
      ':deleted_email' => $deletedEmail,
      ':id' => $id,
    ]);

    Audit::log($pdo, $actorUserId, 'delete', 'users', $id, $before, [
      'email' => $deletedEmail,
      'deleted_at' => date('c'),
    ]);

    return [
      'id' => $id,
      'deleted' => true,
    ];
  }

  public static function resetPassword(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::findById($pdo, $id);
    if ($before === null) {
      return null;
    }

    $stmt = $pdo->prepare('
      UPDATE users
      SET password_hash = :password_hash, must_change_password = 0, updated_at = NOW()
      WHERE id = :id
    ');
    $stmt->execute([
      ':password_hash' => password_hash('Temporaria@123', PASSWORD_DEFAULT),
      ':id' => $id,
    ]);

    Audit::log($pdo, $actorUserId, 'update', 'users', $id, $before, ['password_reset' => true]);

    $after = self::findById($pdo, $id);
    return $after !== null ? self::apiPayload($pdo, $after) : null;
  }
}
