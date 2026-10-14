<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/UserAccessRequest.php';
require_once __DIR__ . '/Audit.php';

/**
 * Registro de solicitacoes de acesso autenticadas pelo Google.
 * A identidade vem da sessao validada pelo callback OIDC, nunca do navegador.
 * Nao cria sessao autenticada nem concede perfis/permissoes.
 */
final class GoogleOnboarding
{
    public static function context(PDO $pdo, array $identity): array
    {
        $stmt = $pdo->prepare("
            SELECT u.id, u.is_approved, u.is_active, u.deleted_at
            FROM user_external_identities e
            JOIN users u ON u.id = e.user_id
            WHERE e.provider = 'google'
              AND e.issuer = :issuer
              AND e.subject = :subject
            LIMIT 1
        ");
        $stmt->execute([
            ':issuer' => $identity['issuer'],
            ':subject' => $identity['subject'],
        ]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user !== false) {
            if ($user['deleted_at'] !== null || (int)$user['is_active'] !== 1) {
                return ['status' => 'blocked'];
            }
            return [
                'status' => (int)$user['is_approved'] === 1
                    ? 'approved'
                    : 'pending',
            ];
        }

        $user = User::findByEmail($pdo, (string)$identity['email']);
        if ($user !== null) {
            if ((int)$user['is_active'] !== 1) {
                return ['status' => 'blocked'];
            }
            // Nao associar contas existentes pelo email automaticamente.
            return [
                'status' => (int)$user['is_approved'] === 1
                    ? 'needs_link'
                    : 'existing_pending',
            ];
        }

        return ['status' => 'new'];
    }

    public static function register(PDO $pdo, array $identity, array $payload): void
    {
        $name = trim((string)($payload['name'] ?? $identity['name'] ?? ''));
        $email = trim((string)$identity['email']);

        if ($name === '' || strlen($name) > 120 ||
            filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
            strlen($email) > 190) {
            throw new InvalidArgumentException('Confira o nome informado.');
        }

        $pdo->beginTransaction();
        try {
            // Verificacao repetida dentro da transacao. Indices unicos
            // impedem dois registros concorrentes da mesma identidade.
            if (self::context($pdo, $identity)['status'] !== 'new') {
                throw new DomainException('Ja existe uma conta ou solicitacao para esta identidade.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO users (
                    name, email, password_hash, is_active, is_approved,
                    approved_at, approved_by_user_id, must_change_password,
                    user_type, specialty
                ) VALUES (
                    :name, :email, :password_hash, 1, 0,
                    NULL, NULL, 0, NULL, NULL
                )
            ");
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                // Nao existe senha utilizavel pelo usuario neste cadastro SSO.
                ':password_hash' => password_hash(
                    bin2hex(random_bytes(48)),
                    PASSWORD_DEFAULT
                ),
            ]);
            $userId = (int)$pdo->lastInsertId();

            $requestId = UserAccessRequest::createPending($pdo, $userId, $payload);

            $stmt = $pdo->prepare("
                INSERT INTO user_external_identities (
                    user_id, provider, issuer, subject, linked_at, last_login_at
                ) VALUES (
                    :user_id, 'google', :issuer, :subject, NOW(), NULL
                )
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':issuer' => $identity['issuer'],
                ':subject' => $identity['subject'],
            ]);

            Audit::log(
                $pdo,
                $userId,
                'create',
                'users',
                $userId,
                null,
                [
                    'self_registered_google' => true,
                    'access_request_id' => $requestId,
                    'is_approved' => 0,
                    'role_ids' => [],
                ]
            );
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
