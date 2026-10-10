<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/UserAccessRequest.php';

final class AdminUserController {

  public static function list(PDO $pdo): never {
    api_require_permission($pdo, 'admin.manage');

    $query = trim((string)(api_query_param('q', '') ?? ''));

    api_success([
      'q' => $query,
      'rows' => User::listForAdmin($pdo, $query),
    ]);
  }

  public static function form(PDO $pdo): never {
    $currentUser = api_current_user($pdo);
    $currentUserId = (int)$currentUser['id'];

    $id = (int)(api_query_param('id', '0') ?? '0');
    $editing = $id > 0;

    $canManageUsers = can($pdo, 'admin.manage');

    if (!$canManageUsers && !$editing) {
      $id = $currentUserId;
      $editing = true;
    }

    $isSelfProfile = $editing && $id === $currentUserId;

    if (!$canManageUsers && !$isSelfProfile) {
      api_error('Sem permissao: admin.manage', 403);
    }

    if (api_request_method() === 'GET') {
      $context = User::formContext(
        $pdo,
        $editing ? $id : null
      );

      if ($context['error'] !== null) {
        api_error((string)$context['error'], 404);
      }

      $context['can_manage_users'] = $canManageUsers;
      $context['self_profile'] =
        !$canManageUsers && $isSelfProfile;

      // Somente administradores podem visualizar
      // solicitacoes de acesso de outros usuarios.
      //
      // Os dados solicitados nao representam
      // permissoes efetivas de acesso.
      $context['access_request'] = null;

      if ($canManageUsers && $editing) {
        $context['access_request'] =
          UserAccessRequest::latestForUser(
            $pdo,
            $id
          );
      }

      api_success($context);
    }

    api_verify_csrf();

    $input = api_request_input();

    // Usuarios comuns nao podem alterar sua unidade
    // ou especialidade pelo formulario de perfil.
    if (!$canManageUsers) {
      $currentType = strtoupper(
        trim((string)($currentUser['user_type'] ?? ''))
      );

      $currentSpecialty = trim(
        (string)($currentUser['specialty'] ?? '')
      );

      if (
        array_key_exists('user_type', $input) &&
        strtoupper(trim((string)$input['user_type']))
          !== $currentType
      ) {
        api_error(
          'A unidade de atuacao so pode ser alterada pelo administrador.',
          403
        );
      }

      if (
        array_key_exists('specialty', $input) &&
        trim((string)$input['specialty'])
          !== $currentSpecialty
      ) {
        api_error(
          'A especialidade so pode ser alterada pelo administrador.',
          403
        );
      }

      // Sempre utiliza os valores confiaveis do banco.
      $input['user_type'] = $currentType;
      $input['specialty'] = $currentSpecialty;
    }

    try {
      $context = $canManageUsers
        ? User::save(
            $pdo,
            $editing ? $id : null,
            $input,
            $currentUserId
          )
        : User::saveSelfProfile(
            $pdo,
            $currentUserId,
            $input
          );

      $context['can_manage_users'] = $canManageUsers;
      $context['self_profile'] = !$canManageUsers;

      api_success($context);

    } catch (Throwable $error) {
      api_error($error->getMessage(), 422);
    }
  }

  public static function toggleActive(PDO $pdo): never {
    api_require_permission($pdo, 'admin.manage');
    api_verify_csrf();

    $input = api_request_input();
    $id = (int)($input['id'] ?? 0);

    $user = User::toggleActive(
      $pdo,
      $id,
      api_require_user_id()
    );

    if ($user === null) {
      api_error('Usuario nao encontrado.', 404);
    }

    api_success(['user' => $user]);
  }

  public static function resetPassword(PDO $pdo): never {
    api_require_permission($pdo, 'admin.manage');
    api_verify_csrf();

    $input = api_request_input();
    $id = (int)($input['id'] ?? 0);

    $user = User::resetPassword(
      $pdo,
      $id,
      api_require_user_id()
    );

    if ($user === null) {
      api_error('Usuario nao encontrado.', 404);
    }

    api_success([
      'user' => $user,
      'temporary_password' => 'Temporaria@123',
    ]);
  }

  public static function approve(PDO $pdo): never {
    api_require_permission($pdo, 'admin.manage');
    api_verify_csrf();

    $input = api_request_input();
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
      api_error('Usuario invalido.', 422);
    }

    $user = User::findById($pdo, $id);

    if ($user === null) {
      api_error('Usuario nao encontrado.', 404);
    }

    $roles = Role::namesForUser($pdo, $id);

    if (count($roles) !== 1) {
      api_error(
        'Defina exatamente um perfil antes de aprovar.',
        422
      );
    }

    $role = $roles[0];

    if (!in_array(
      $role,
      ['admin', 'alimentador', 'visualizador'],
      true
    )) {
      api_error('Perfil de acesso invalido.', 422);
    }

    $userType = strtoupper(
      trim((string)($user['user_type'] ?? ''))
    );

    $specialty = trim(
      (string)($user['specialty'] ?? '')
    );

    if ($role !== 'admin') {

      if (!in_array(
        $userType,
        ['CADH', 'UBS'],
        true
      )) {
        api_error(
          'Defina a unidade CADH ou UBS antes de aprovar.',
          422
        );
      }

      if ($userType === 'CADH') {
        if (!in_array(
          $specialty,
          User::authSpecialties(),
          true
        )) {
          api_error(
            'Defina uma especialidade valida para o usuario CADH.',
            422
          );
        }
      }
    }

    if ((int)$user['is_active'] !== 1) {
      api_error(
        'Ative a conta antes de aprovar o usuario.',
        422
      );
    }

    try {
      $approvedUser = User::approve(
        $pdo,
        $id,
        api_require_user_id()
      );

    } catch (Throwable $error) {
      api_error($error->getMessage(), 422);
    }

    if ($approvedUser === null) {
      api_error(
        'Nao foi possivel aprovar o usuario.',
        422
      );
    }

    api_success([
      'user' => $approvedUser,
    ]);
  }

  public static function softDelete(PDO $pdo): never {
    api_require_permission($pdo, 'admin.manage');
    api_verify_csrf();

    $input = api_request_input();
    $id = (int)($input['id'] ?? 0);

    try {
      $result = User::softDelete(
        $pdo,
        $id,
        api_require_user_id()
      );

    } catch (Throwable $error) {
      api_error($error->getMessage(), 422);
    }

    if ($result === null) {
      api_error('Usuario nao encontrado.', 404);
    }

    api_success($result);
  }
}
