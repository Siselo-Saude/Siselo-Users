<?php
declare(strict_types=1);

require_once __DIR__ . '/Role.php';
require_once __DIR__ . '/UserAccessRequest.php';
require_once __DIR__ . '/../services/Audit.php';

final class User {

  private static function deletedEmailFor(int $id): string {
    return sprintf('deleted+user-%d-%d@local.invalid', $id, time());
  }

  private static function releaseDeletedEmailReservation(
    PDO $pdo,
    string $email,
    ?int $excludeId = null,
    ?int $actorUserId = null
  ): void {
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

    $update = $pdo->prepare(
      'UPDATE users SET email = :email, updated_at = NOW() WHERE id = :id'
    );

    $update->execute([
      ':email' => $replacementEmail,
      ':id' => $deletedUserId,
    ]);

    if ($actorUserId !== null) {
      Audit::log(
        $pdo,
        $actorUserId,
        'update',
        'users',
        $deletedUserId,
        $deletedUser,
        [
          'email' => $replacementEmail,
          'deleted_email_released' => true,
        ]
      );
    }
  }

  public static function findByEmail(PDO $pdo, string $email): ?array {
    $stmt = $pdo->prepare(
      'SELECT * FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch();

    return $row ?: null;
  }

  public static function findById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
      'SELECT * FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
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
      'approved_at' => isset($user['approved_at'])
        ? (string)$user['approved_at']
        : null,
      'approved_by_user_id' => isset($user['approved_by_user_id'])
        ? (int)$user['approved_by_user_id']
        : null,
      'must_change_password' => (int)$user['must_change_password'],
      'user_type' => isset($user['user_type'])
        ? (string)$user['user_type']
        : null,
      'specialty' => isset($user['specialty'])
        ? (string)$user['specialty']
        : null,
      'roles' => Role::namesForUser($pdo, $userId),
      'permissions' => array_values(
        array_keys(user_permissions($pdo, $userId))
      ),
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

  /**
   * Valida a configuracao de acesso de um usuario.
   *
   * Usuarios pendentes podem ficar sem perfil.
   * A aprovacao exige um perfil valido e unidade quando obrigatoria.
   */
  private static function assertAccessConfiguration(
    PDO $pdo,
    array $roleIds,
    string $userType,
    string $specialty
  ): void {
    $roleIds = array_values(
      array_unique(array_map('intval', $roleIds))
    );

    if (count($roleIds) !== 1) {
      throw new RuntimeException(
        'Defina exatamente um perfil de acesso antes de aprovar.'
      );
    }

    $rolesById = [];

    foreach (Role::all($pdo) as $role) {
      $rolesById[(int)$role['id']] = (string)$role['name'];
    }

    $roleName = $rolesById[$roleIds[0]] ?? '';

    if (!in_array(
      $roleName,
      ['admin', 'alimentador', 'visualizador'],
      true
    )) {
      throw new RuntimeException('Perfil de acesso invalido.');
    }

    if (
      $roleName !== 'admin' &&
      !in_array($userType, ['CADH', 'UBS'], true)
    ) {
      throw new RuntimeException(
        'Defina a unidade CADH ou UBS antes de aprovar.'
      );
    }

    if (
      $userType !== '' &&
      !in_array($userType, ['CADH', 'UBS'], true)
    ) {
      throw new RuntimeException('Unidade de atuacao invalida.');
    }

    if (
      $userType === 'CADH' &&
      !in_array($specialty, self::authSpecialties(), true)
    ) {
      throw new RuntimeException(
        'Defina uma especialidade valida para a unidade CADH.'
      );
    }
  }

  public static function registerPublic(PDO $pdo, array $payload): array {
    $name = trim((string)($payload['name'] ?? ''));
    $email = trim((string)($payload['email'] ?? ''));
    $password = (string)($payload['password'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
      throw new RuntimeException(
        'Nome, email e senha sao obrigatorios.'
      );
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
      throw new RuntimeException('Email invalido.');
    }

    if (strlen($name) > 120 || strlen($email) > 190) {
      throw new RuntimeException(
        'Nome ou email excedem o tamanho permitido.'
      );
    }

    if (strlen($password) < 8) {
      throw new RuntimeException(
        'A senha deve ter pelo menos 8 caracteres.'
      );
    }

    // O perfil, a unidade e a especialidade informados
    // serao validados pelo modelo UserAccessRequest.
    // Eles nao representam permissoes autorizadas.

    $emailStmt = $pdo->prepare(
      'SELECT id FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1'
    );

    $emailStmt->execute([':email' => $email]);

    if ($emailStmt->fetchColumn() !== false) {
      throw new RuntimeException(
        'Ja existe uma conta com este email.'
      );
    }

    $pdo->beginTransaction();

    try {
      self::releaseDeletedEmailReservation($pdo, $email);

      // Cria uma conta pendente, sem unidade efetiva
      // e sem especialidade efetiva.
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
          specialty
        )
        VALUES (
          :name,
          :email,
          :password_hash,
          1,
          0,
          NULL,
          NULL,
          0,
          NULL,
          NULL
        )
      ');

      $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':password_hash' => password_hash(
          $password,
          PASSWORD_DEFAULT
        ),
      ]);

      $userId = (int)$pdo->lastInsertId();

      // Salva as preferencias do usuario separadamente.
      // A funcao valida perfil, unidade e especialidade.
      $requestId = UserAccessRequest::createPending(
        $pdo,
        $userId,
        $payload
      );

      // Nenhum perfil e atribuido em user_roles.
      // Somente o administrador podera fazer isso.

      Audit::log(
        $pdo,
        $userId,
        'create',
        'users',
        $userId,
        null,
        [
          'name' => $name,
          'email' => $email,
          'is_active' => 1,
          'is_approved' => 0,
          'user_type' => null,
          'specialty' => null,
          'role_ids' => [],
          'access_request_id' => $requestId,
          'self_registered' => true,
        ]
      );

      $pdo->commit();

      $user = self::findById($pdo, $userId);

      if ($user === null) {
        throw new RuntimeException(
          'Usuario criado, mas nao foi possivel carregar a conta.'
        );
      }

      return $user;

    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      if (
        $error instanceof PDOException &&
        str_contains($error->getMessage(), 'users.email')
      ) {
        throw new RuntimeException(
          'Ja existe uma conta com este email.'
        );
      }

      throw $error;
    }
  }
  public static function listForAdmin(
    PDO $pdo,
    string $query = ''
  ): array {
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
        $row['approved_by_user_id'] =
          isset($row['approved_by_user_id'])
            ? (int)$row['approved_by_user_id']
            : null;
        $row['approved_at'] =
          isset($row['approved_at'])
            ? (string)$row['approved_at']
            : null;
        $row['must_change_password'] =
          (int)$row['must_change_password'];
        $row['user_type'] =
          isset($row['user_type'])
            ? (string)$row['user_type']
            : '';
        $row['specialty'] =
          isset($row['specialty'])
            ? (string)$row['specialty']
            : '';
        $row['roles'] = Role::namesForUser(
          $pdo,
          (int)$row['id']
        );
        $row['role_ids'] = Role::idsForUser(
          $pdo,
          (int)$row['id']
        );

        return $row;
      },
      $rows
    );
  }

  public static function formContext(
    PDO $pdo,
    ?int $id = null
  ): array {
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
        'approved_at' => isset($row['approved_at'])
          ? (string)$row['approved_at']
          : null,
        'user_type' => isset($row['user_type'])
          ? (string)$row['user_type']
          : '',
        'specialty' => isset($row['specialty'])
          ? (string)$row['specialty']
          : '',
        'role_ids' => Role::idsForUser(
          $pdo,
          (int)$row['id']
        ),
      ];
    }

    return [
      'editing' => $editing,
      'user' => $user,
      'roles' => Role::all($pdo),
      'error' => null,
    ];
  }

  public static function save(
    PDO $pdo,
    ?int $id,
    array $payload,
    int $actorUserId
  ): array {
    $editing = $id !== null;

    $name = trim((string)($payload['name'] ?? ''));
    $email = trim((string)($payload['email'] ?? ''));
    $isActive = !empty($payload['is_active']) ? 1 : 0;

    $userType = strtoupper(
      trim((string)($payload['user_type'] ?? ''))
    );

    $specialty = trim((string)($payload['specialty'] ?? ''));

    $roleIds = array_values(
      array_map('intval', (array)($payload['role_ids'] ?? []))
    );

    if ($name === '' || $email === '') {
      throw new RuntimeException(
        'Nome e email sao obrigatorios.'
      );
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
      throw new RuntimeException('Email invalido.');
    }

    if (
      $userType !== '' &&
      !in_array($userType, ['CADH', 'UBS'], true)
    ) {
      throw new RuntimeException('Tipo de usuario invalido.');
    }

    if (
      $userType === 'CADH' &&
      !in_array($specialty, self::authSpecialties(), true)
    ) {
      throw new RuntimeException(
        'Especialidade obrigatoria para usuarios CADH.'
      );
    }

    if ($userType !== 'CADH') {
      $specialty = '';
    }

    $emailStmt = $pdo->prepare(
      'SELECT id FROM users WHERE email = :email AND deleted_at IS NULL AND id <> :id LIMIT 1'
    );

    $emailStmt->execute([
      ':email' => $email,
      ':id' => $editing ? $id : 0,
    ]);

    if ($emailStmt->fetchColumn() !== false) {
      throw new RuntimeException(
        'Ja existe uma conta com este email.'
      );
    }

    $pdo->beginTransaction();

    try {
      self::releaseDeletedEmailReservation(
        $pdo,
        $email,
        $editing ? $id : null,
        $actorUserId
      );

      if ($editing) {
        $before = self::findById($pdo, $id);

        if ($before === null) {
          throw new RuntimeException(
            'Usuario nao encontrado.'
          );
        }

        // Impede configuracoes incompletas em contas aprovadas.
        if ((int)($before['is_approved'] ?? 0) === 1) {
          self::assertAccessConfiguration(
            $pdo,
            $roleIds,
            $userType,
            $specialty
          );
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
          ':user_type' => $userType !== ''
            ? $userType
            : null,
          ':specialty' => $specialty !== ''
            ? $specialty
            : null,
          ':id' => $id,
        ]);

        Role::syncUserRoles($pdo, $id, $roleIds);

        Audit::log(
          $pdo,
          $actorUserId,
          'update',
          'users',
          $id,
          $before,
          [
            'name' => $name,
            'email' => $email,
            'is_active' => $isActive,
            'user_type' => $userType !== ''
              ? $userType
              : null,
            'specialty' => $specialty !== ''
              ? $specialty
              : null,
            'role_ids' => $roleIds,
          ]
        );

        $userId = $id;

      } else {
        $tempPassword = (string)(
          $payload['temp_password'] ?? ''
        );

        if ($tempPassword === '') {
          throw new RuntimeException(
            'Senha temporaria obrigatoria.'
          );
        }

        // Cadastro direto pelo administrador ja nasce aprovado.
        self::assertAccessConfiguration(
          $pdo,
          $roleIds,
          $userType,
          $specialty
        );

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
            specialty
          )
          VALUES (
            :name,
            :email,
            :password_hash,
            :is_active,
            1,
            NOW(),
            :approved_by_user_id,
            0,
            :user_type,
            :specialty
          )
        ');

        $stmt->execute([
          ':name' => $name,
          ':email' => $email,
          ':password_hash' => password_hash(
            $tempPassword,
            PASSWORD_DEFAULT
          ),
          ':is_active' => $isActive,
          ':approved_by_user_id' => $actorUserId,
          ':user_type' => $userType !== ''
            ? $userType
            : null,
          ':specialty' => $specialty !== ''
            ? $specialty
            : null,
        ]);

        $userId = (int)$pdo->lastInsertId();

        Role::syncUserRoles($pdo, $userId, $roleIds);

        Audit::log(
          $pdo,
          $actorUserId,
          'create',
          'users',
          $userId,
          null,
          [
            'name' => $name,
            'email' => $email,
            'is_active' => $isActive,
            'is_approved' => 1,
            'user_type' => $userType !== ''
              ? $userType
              : null,
            'specialty' => $specialty !== ''
              ? $specialty
              : null,
            'role_ids' => $roleIds,
          ]
        );
      }

      $pdo->commit();

      return self::formContext($pdo, $userId);

    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      if (
        $error instanceof PDOException &&
        str_contains($error->getMessage(), 'users.email')
      ) {
        throw new RuntimeException(
          'Ja existe uma conta com este email.'
        );
      }

      throw $error;
    }
  }

  public static function saveSelfProfile(
    PDO $pdo,
    int $id,
    array $payload
  ): array {
    $before = self::findById($pdo, $id);

    if ($before === null) {
      throw new RuntimeException(
        'Usuario nao encontrado.'
      );
    }

    $name = trim((string)($payload['name'] ?? ''));
    $email = trim((string)($payload['email'] ?? ''));

    if ($name === '' || $email === '') {
      throw new RuntimeException(
        'Nome e email sao obrigatorios.'
      );
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
      throw new RuntimeException('Email invalido.');
    }

    if (strlen($name) > 120 || strlen($email) > 190) {
      throw new RuntimeException(
        'Nome ou email excedem o tamanho permitido.'
      );
    }

    $emailStmt = $pdo->prepare(
      'SELECT id FROM users WHERE email = :email AND deleted_at IS NULL AND id <> :id LIMIT 1'
    );

    $emailStmt->execute([
      ':email' => $email,
      ':id' => $id,
    ]);

    if ($emailStmt->fetchColumn() !== false) {
      throw new RuntimeException(
        'Ja existe uma conta com este email.'
      );
    }

    $pdo->beginTransaction();

    try {
      self::releaseDeletedEmailReservation(
        $pdo,
        $email,
        $id,
        $id
      );

      // Unidade, especialidade e perfis nao sao
      // alterados pelo proprio usuario.
      $stmt = $pdo->prepare('
        UPDATE users
        SET
          name = :name,
          email = :email,
          updated_at = NOW()
        WHERE id = :id
      ');

      $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':id' => $id,
      ]);

      Audit::log(
        $pdo,
        $id,
        'update',
        'users',
        $id,
        [
          'name' => (string)$before['name'],
          'email' => (string)$before['email'],
        ],
        [
          'name' => $name,
          'email' => $email,
          'self_profile' => true,
        ]
      );

      $pdo->commit();

      return self::formContext($pdo, $id);

    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      if (
        $error instanceof PDOException &&
        str_contains($error->getMessage(), 'users.email')
      ) {
        throw new RuntimeException(
          'Ja existe uma conta com este email.'
        );
      }

      throw $error;
    }
  }

  public static function toggleActive(
    PDO $pdo,
    int $id,
    int $actorUserId
  ): ?array {
    $before = self::findById($pdo, $id);

    if ($before === null) {
      return null;
    }

    $newValue = ((int)$before['is_active'] === 1)
      ? 0
      : 1;

    $stmt = $pdo->prepare(
      'UPDATE users SET is_active = :is_active, updated_at = NOW() WHERE id = :id'
    );

    $stmt->execute([
      ':is_active' => $newValue,
      ':id' => $id,
    ]);

    Audit::log(
      $pdo,
      $actorUserId,
      'update',
      'users',
      $id,
      $before,
      ['is_active' => $newValue]
    );

    $after = self::findById($pdo, $id);

    return $after !== null
      ? self::apiPayload($pdo, $after)
      : null;
  }

  /**
   * Aprova o usuario somente se as permissoes
   * e a unidade estiverem configuradas corretamente.
   */
  public static function approve(
    PDO $pdo,
    int $id,
    int $actorUserId
  ): ?array {
    $pdo->beginTransaction();

    try {
      $stmt = $pdo->prepare('
        SELECT *
        FROM users
        WHERE id = :id
          AND deleted_at IS NULL
        FOR UPDATE
      ');

      $stmt->execute([':id' => $id]);
      $before = $stmt->fetch();

      if ($before === false) {
        $pdo->rollBack();
        return null;
      }

      if ((int)$before['is_active'] !== 1) {
        throw new RuntimeException(
          'Ative a conta antes de aprovar o usuario.'
        );
      }

      $roleIds = Role::idsForUser($pdo, $id);

      self::assertAccessConfiguration(
        $pdo,
        $roleIds,
        strtoupper(
          trim((string)($before['user_type'] ?? ''))
        ),
        trim((string)($before['specialty'] ?? ''))
      );

      if ((int)($before['is_approved'] ?? 0) !== 1) {
        $update = $pdo->prepare('
          UPDATE users
          SET
            is_approved = 1,
            approved_at = NOW(),
            approved_by_user_id = :approved_by_user_id,
            updated_at = NOW()
          WHERE id = :id
        ');

        $update->execute([
          ':approved_by_user_id' => $actorUserId,
          ':id' => $id,
        ]);

        Audit::log(
          $pdo,
          $actorUserId,
          'approve',
          'users',
          $id,
          [
            'is_approved' => 0,
          ],
          [
            'is_approved' => 1,
            'approved_by_user_id' => $actorUserId,
            'role_ids' => $roleIds,
            'user_type' => $before['user_type'] ?? null,
          ]
        );
      }

      // Registra a decisao administrativa na mesma transacao.
      // Cadastros antigos podem nao possuir solicitacao.
      if ((int)($before['is_approved'] ?? 0) !== 1) {
        $requestStmt = $pdo->prepare("
          SELECT id
          FROM user_access_requests
          WHERE user_id = :user_id
            AND status = 'pending'
          ORDER BY id DESC
          LIMIT 1
          FOR UPDATE
        ");

        $requestStmt->execute([
          ':user_id' => $id,
        ]);

        $requestId = $requestStmt->fetchColumn();

        if ($requestId !== false) {
          $approvedRoles = Role::namesForUser($pdo, $id);

          $reviewNotes = sprintf(
            'Perfil autorizado: %s; unidade: %s; especialidade: %s.',
            implode(', ', $approvedRoles),
            (string)($before['user_type'] ?? '-'),
            (string)($before['specialty'] ?? '-')
          );

          $reviewStmt = $pdo->prepare("
            UPDATE user_access_requests
            SET
              status = 'approved',
              reviewed_at = NOW(),
              reviewed_by_user_id = :reviewer_id,
              review_notes = :review_notes
            WHERE id = :request_id
              AND status = 'pending'
          ");

          $reviewStmt->execute([
            ':reviewer_id' => $actorUserId,
            ':review_notes' => $reviewNotes,
            ':request_id' => (int)$requestId,
          ]);
        }
      }
      $pdo->commit();

      $after = self::findById($pdo, $id);

      return $after !== null
        ? self::apiPayload($pdo, $after)
        : null;

    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      throw $error;
    }
  }

  public static function softDelete(
    PDO $pdo,
    int $id,
    int $actorUserId
  ): ?array {
    $before = self::findById($pdo, $id);

    if ($before === null) {
      return null;
    }

    if ($id === $actorUserId) {
      throw new RuntimeException(
        'Nao e permitido excluir o proprio usuario.'
      );
    }

    $deletedEmail = self::deletedEmailFor($id);

    $stmt = $pdo->prepare('
      UPDATE users
      SET
        email = :deleted_email,
        deleted_at = NOW(),
        updated_at = NOW()
      WHERE id = :id
    ');

    $stmt->execute([
      ':deleted_email' => $deletedEmail,
      ':id' => $id,
    ]);

    Audit::log(
      $pdo,
      $actorUserId,
      'delete',
      'users',
      $id,
      $before,
      [
        'email' => $deletedEmail,
        'deleted_at' => date('c'),
      ]
    );

    return [
      'id' => $id,
      'deleted' => true,
    ];
  }

  public static function resetPassword(
    PDO $pdo,
    int $id,
    int $actorUserId
  ): ?array {
    $before = self::findById($pdo, $id);

    if ($before === null) {
      return null;
    }

    $stmt = $pdo->prepare('
      UPDATE users
      SET
        password_hash = :password_hash,
        must_change_password = 0,
        updated_at = NOW()
      WHERE id = :id
    ');

    $stmt->execute([
      ':password_hash' => password_hash(
        'Temporaria@123',
        PASSWORD_DEFAULT
      ),
      ':id' => $id,
    ]);

    Audit::log(
      $pdo,
      $actorUserId,
      'update',
      'users',
      $id,
      $before,
      ['password_reset' => true]
    );

    $after = self::findById($pdo, $id);

    return $after !== null
      ? self::apiPayload($pdo, $after)
      : null;
  }
}
