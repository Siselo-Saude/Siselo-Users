<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/Audit.php';
require_once __DIR__ . '/../services/CoopereAuth.php';
require_once __DIR__ . '/../services/ExoPortalClient.php';

final class CoopereAuthController {
  public static function complete(PDO $pdo, array $config): never {
    $input = api_request_input();
    $token = trim((string)($input['token'] ?? ''));

    if ($token === '') {
      api_error('Token Coopere obrigatorio.', 422);
    }

    try {
      $identity = CoopereAuth::verifyToken($token, $config['coopere'] ?? []);
      $identity = self::hydrateIdentityFromExo($identity, $config['coopere'] ?? []);
      $user = User::syncFromCoopere($pdo, $identity);
    } catch (Throwable $error) {
      api_error($error->getMessage(), 401);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];

    Audit::log($pdo, (int)$user['id'], 'login', 'users', (int)$user['id'], null, [
      'email' => (string)$user['email'],
      'external_provider' => (string)($user['external_provider'] ?? ''),
    ]);

    $payload = User::apiPayload($pdo, $user);
    api_success([
      'user' => $payload,
      'csrf' => csrf_token(),
      'redirect' => (int)($payload['profile_completed'] ?? 1) === 1 ? '/index.html' : '/welcome.html',
    ]);
  }

  public static function completeProfile(PDO $pdo): never {
    $user = api_current_user($pdo);
    api_verify_csrf();

    try {
      $updatedUser = User::completeExternalProfile($pdo, (int)$user['id'], api_request_input());
    } catch (Throwable $error) {
      api_error($error->getMessage(), 422);
    }

    api_success([
      'user' => User::apiPayload($pdo, $updatedUser),
      'csrf' => csrf_token(),
      'redirect' => '/index.html',
    ]);
  }

  public static function devToken(array $config): never {
    $coopereConfig = $config['coopere'] ?? [];
    if (($coopereConfig['dev_mode'] ?? false) !== true) {
      api_error('Recurso nao encontrado.', 404);
    }

    try {
      $token = CoopereAuth::issueDevToken(api_request_input(), $coopereConfig);
    } catch (Throwable $error) {
      api_error($error->getMessage(), 422);
    }

    api_success([
      'token' => $token,
      'login_path' => '/coopere-login.html?token=' . rawurlencode($token),
    ]);
  }

  private static function hydrateIdentityFromExo(array $identity, array $config): array {
    $client = new ExoPortalClient($config);
    if (!$client->isConfigured()) {
      return $identity;
    }

    return $client->identityForUsername(
      (string)($identity['username'] ?? ''),
      trim((string)($config['group_id'] ?? $config['allowed_group'] ?? '')),
      trim((string)($config['provider'] ?? 'coopere'))
    );
  }
}
