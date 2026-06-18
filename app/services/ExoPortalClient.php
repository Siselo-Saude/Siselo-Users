<?php
declare(strict_types=1);

final class ExoPortalClient {
  private string $baseUrl;
  private string $username;
  private string $password;
  private int $timeoutSeconds;

  public function __construct(array $config) {
    $this->baseUrl = rtrim(trim((string)($config['api_base_url'] ?? '')), '/');
    $this->username = trim((string)($config['api_username'] ?? ''));
    $this->password = (string)($config['api_password'] ?? '');
    $this->timeoutSeconds = max(1, (int)($config['api_timeout_seconds'] ?? 10));
  }

  public function isConfigured(): bool {
    return $this->baseUrl !== '' && $this->username !== '' && $this->password !== '';
  }

  public function identityForUsername(string $username, string $requiredGroupId, string $provider): array {
    if (!$this->isConfigured()) {
      throw new RuntimeException('API Coopere/eXo nao configurada.');
    }

    $normalizedUsername = trim($username);
    if ($normalizedUsername === '') {
      throw new RuntimeException('Usuario Coopere obrigatorio.');
    }

    $user = $this->getUser($normalizedUsername);
    $memberships = $this->getUserMemberships($normalizedUsername);
    $matchedGroup = $this->matchedMembershipGroup($memberships, $requiredGroupId);
    if ($matchedGroup === null && $requiredGroupId !== '' && $this->userBelongsToGroup($normalizedUsername, $requiredGroupId)) {
      $matchedGroup = $requiredGroupId;
    }

    if ($requiredGroupId !== '' && $matchedGroup === null) {
      throw new RuntimeException('Usuario nao pertence ao espaco Coopere autorizado.');
    }

    $email = trim((string)($user['email'] ?? ''));
    $name = trim((string)($user['fullName'] ?? ''));
    if ($name === '') {
      $name = trim((string)(($user['firstName'] ?? '') . ' ' . ($user['lastName'] ?? '')));
    }

    if ($name === '') {
      $name = $normalizedUsername;
    }

    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
      throw new RuntimeException('Usuario Coopere sem email valido.');
    }

    return [
      'provider' => $provider !== '' ? $provider : 'coopere',
      'username' => (string)($user['userName'] ?? $normalizedUsername),
      'name' => $name,
      'email' => $email,
      'group' => $matchedGroup ?? $requiredGroupId,
    ];
  }

  private function getUser(string $username): array {
    $payload = $this->getJson('/v1/users/' . rawurlencode($username));
    if (!is_array($payload) || $payload === []) {
      throw new RuntimeException('Usuario Coopere nao encontrado.');
    }

    return $payload;
  }

  private function getUserMemberships(string $username): array {
    $payload = $this->getJson('/v1/users/' . rawurlencode($username) . '/memberships?limit=200&returnSize=true');

    if (isset($payload['entities']) && is_array($payload['entities'])) {
      return $payload['entities'];
    }

    if (array_is_list($payload)) {
      return $payload;
    }

    return [];
  }

  private function getGroupMemberships(string $groupId): array {
    $payload = $this->getJson('/v1/groups/memberships?groupId=' . rawurlencode($groupId) . '&limit=500&returnSize=true');

    if (isset($payload['entities']) && is_array($payload['entities'])) {
      return $payload['entities'];
    }

    if (array_is_list($payload)) {
      return $payload;
    }

    return [];
  }

  private function userBelongsToGroup(string $username, string $groupId): bool {
    try {
      $memberships = $this->getGroupMemberships($groupId);
    } catch (Throwable $error) {
      return false;
    }

    $normalizedUsername = strtolower(trim($username));
    foreach ($memberships as $membership) {
      if (!is_array($membership)) {
        continue;
      }

      $candidate = strtolower(trim((string)($membership['userName'] ?? $membership['username'] ?? $membership['user'] ?? '')));
      if ($candidate !== '' && $candidate === $normalizedUsername) {
        return true;
      }
    }

    return false;
  }

  private function matchedMembershipGroup(array $memberships, string $requiredGroupId): ?string {
    $required = $this->normalizeGroup($requiredGroupId);
    if ($required === '') {
      $first = $memberships[0] ?? null;
      return is_array($first) ? (string)($first['groupId'] ?? $first['group'] ?? '') : null;
    }

    foreach ($memberships as $membership) {
      if (!is_array($membership)) {
        continue;
      }

      foreach ($this->membershipGroupCandidates($membership) as $groupId) {
        if ($this->normalizeGroup($groupId) === $required) {
          return $groupId;
        }
      }
    }

    return null;
  }

  private function membershipGroupCandidates(array $membership): array {
    $candidates = [
      $membership['groupId'] ?? null,
      $membership['group'] ?? null,
      $membership['id'] ?? null,
      $membership['spaceId'] ?? null,
    ];

    if (isset($membership['space']) && is_array($membership['space'])) {
      $candidates[] = $membership['space']['id'] ?? null;
      $candidates[] = $membership['space']['groupId'] ?? null;
      $candidates[] = $membership['space']['displayName'] ?? null;
    }

    return array_values(array_filter(array_map(static fn($value): string => trim((string)$value), $candidates)));
  }

  private function normalizeGroup(string $value): string {
    $value = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    return trim($value, '/');
  }

  private function getJson(string $path): array {
    $response = $this->request('GET', $path);
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
      throw new RuntimeException('Resposta invalida da API Coopere/eXo.');
    }

    return $decoded;
  }

  private function request(string $method, string $path): string {
    $url = $this->baseUrl . '/' . ltrim($path, '/');
    $headers = [
      'Accept: application/json',
      'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password),
    ];

    $context = stream_context_create([
      'http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => $this->timeoutSeconds,
      ],
    ]);

    $response = file_get_contents($url, false, $context);
    $statusCode = $this->statusCode($http_response_header ?? []);

    if ($response === false || $statusCode < 200 || $statusCode >= 300) {
      $message = sprintf(
        'Falha ao consultar API Coopere/eXo (HTTP %d em %s).',
        $statusCode,
        $path
      );

      if (is_string($response) && trim($response) !== '') {
        $message .= ' ' . $this->summarizeErrorBody($response);
      }

      throw new RuntimeException($message);
    }

    return $response;
  }

  private function statusCode(array $headers): int {
    $statusLine = (string)($headers[0] ?? '');
    if (preg_match('/\s(\d{3})\s/', $statusLine, $matches) === 1) {
      return (int)$matches[1];
    }

    return 0;
  }

  private function summarizeErrorBody(string $response): string {
    $body = trim(strip_tags($response));
    $body = preg_replace('/\s+/', ' ', $body) ?? $body;
    if ($body === '') {
      return '';
    }

    return substr($body, 0, 180);
  }
}
