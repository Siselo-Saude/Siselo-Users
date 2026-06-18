<?php
declare(strict_types=1);

final class CoopereAuth {
  public static function verifyToken(string $token, array $config): array {
    $secret = trim((string)($config['token_secret'] ?? ''));
    if ($secret === '') {
      throw new RuntimeException('Integracao Coopere sem segredo configurado.');
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
      throw new RuntimeException('Token Coopere invalido.');
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $signedContent = $encodedHeader . '.' . $encodedPayload;
    $expectedSignature = self::base64UrlEncode(hash_hmac('sha256', $signedContent, $secret, true));

    if (!hash_equals($expectedSignature, $encodedSignature)) {
      throw new RuntimeException('Assinatura Coopere invalida.');
    }

    $header = self::decodeJsonPart($encodedHeader);
    $payload = self::decodeJsonPart($encodedPayload);

    if (($header['alg'] ?? '') !== 'HS256') {
      throw new RuntimeException('Algoritmo do token Coopere invalido.');
    }

    $expiresAt = (int)($payload['exp'] ?? 0);
    if ($expiresAt <= time()) {
      throw new RuntimeException('Token Coopere expirado.');
    }

    return self::normalizeIdentity($payload, $config);
  }

  public static function issueDevToken(array $payload, array $config): string {
    if (($config['dev_mode'] ?? false) !== true) {
      throw new RuntimeException('Geracao local de token Coopere desativada.');
    }

    $secret = trim((string)($config['token_secret'] ?? ''));
    if ($secret === '') {
      throw new RuntimeException('Integracao Coopere sem segredo configurado.');
    }

    $ttl = max(60, (int)($config['token_ttl_seconds'] ?? 900));
    $issuedPayload = [
      'username' => trim((string)($payload['username'] ?? 'usuario.teste')),
      'name' => trim((string)($payload['name'] ?? 'Usuario Teste Coopere')),
      'email' => trim((string)($payload['email'] ?? 'usuario.teste@unb.br')),
      'groups' => self::payloadGroups($payload, $config),
      'iat' => time(),
      'exp' => time() + $ttl,
    ];

    $header = [
      'alg' => 'HS256',
      'typ' => 'JWT',
    ];

    $encodedHeader = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $encodedPayload = self::base64UrlEncode(json_encode($issuedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $signature = self::base64UrlEncode(hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $secret, true));

    return $encodedHeader . '.' . $encodedPayload . '.' . $signature;
  }

  private static function normalizeIdentity(array $payload, array $config): array {
    $name = trim((string)($payload['name'] ?? $payload['fullName'] ?? ''));
    $email = trim((string)($payload['email'] ?? ''));
    $username = trim((string)($payload['username'] ?? $payload['userName'] ?? ''));
    $groups = self::payloadGroups($payload, $config);
    $allowedGroup = trim((string)($config['allowed_group'] ?? ''));
    $matchedGroup = self::matchedGroup($groups, $allowedGroup);
    $usesApiLookup = trim((string)($config['api_base_url'] ?? '')) !== '';

    if ($username === '' || (!$usesApiLookup && ($name === '' || $email === ''))) {
      throw new RuntimeException('Token Coopere sem nome, email ou usuario.');
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
      throw new RuntimeException('Email da Coopere invalido.');
    }

    if ($allowedGroup !== '' && $matchedGroup === null && !$usesApiLookup) {
      throw new RuntimeException('Usuario nao pertence ao espaco Coopere autorizado.');
    }

    return [
      'provider' => trim((string)($config['provider'] ?? 'coopere')) ?: 'coopere',
      'username' => $username,
      'name' => $name,
      'email' => $email,
      'group' => $matchedGroup ?? $allowedGroup,
    ];
  }

  private static function payloadGroups(array $payload, array $config): array {
    $rawGroups = $payload['groups'] ?? $payload['group'] ?? $payload['space'] ?? null;

    if ($rawGroups === null || $rawGroups === '') {
      if (($config['dev_mode'] ?? false) !== true) {
        return [];
      }

      $allowedGroup = trim((string)($config['allowed_group'] ?? ''));
      return $allowedGroup !== '' ? [$allowedGroup] : [];
    }

    if (is_array($rawGroups)) {
      return array_values(array_filter(array_map(static fn($group): string => trim((string)$group), $rawGroups)));
    }

    return array_values(array_filter(array_map('trim', explode(',', (string)$rawGroups))));
  }

  private static function matchedGroup(array $groups, string $allowedGroup): ?string {
    if ($allowedGroup === '') {
      return $groups[0] ?? null;
    }

    $normalizedAllowed = self::normalizeGroup($allowedGroup);
    foreach ($groups as $group) {
      if (self::normalizeGroup($group) === $normalizedAllowed) {
        return $group;
      }
    }

    return null;
  }

  private static function normalizeGroup(string $value): string {
    return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
  }

  private static function decodeJsonPart(string $value): array {
    $decoded = self::base64UrlDecode($value);
    $json = json_decode($decoded, true);
    if (!is_array($json)) {
      throw new RuntimeException('Token Coopere malformado.');
    }

    return $json;
  }

  private static function base64UrlEncode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
  }

  private static function base64UrlDecode(string $value): string {
    $padding = strlen($value) % 4;
    if ($padding > 0) {
      $value .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if ($decoded === false) {
      throw new RuntimeException('Token Coopere malformado.');
    }

    return $decoded;
  }
}
