
<?php
declare(strict_types=1);

require __DIR__ . '/../../../../app/api/bootstrap.php';
require_once __DIR__ . '/../../../../app/models/User.php';
require_once __DIR__ . '/../../../../app/services/Audit.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';

use Google\Auth\AccessToken;

/*
 * SIS ELO - Callback de autenticacao Google (OpenID Connect).
 * A identidade e localizada por issuer + subject, nunca pelo email.
 */
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function googleFinish(string $result, bool $success = false): never
{
    $origin = rtrim(
        (string) (getenv('FRONTEND_ORIGIN') ?: 'http://localhost:3001'),
        '/'
    );

    $destination = $success
        ? '/index.html?google_status=' . rawurlencode($result)
        : '/login.html?google_error=' . rawurlencode($result);

    header('Location: ' . $origin . $destination, true, 303);
    exit;
}

/*
 * Diagnostico: registrar SOMENTE tipos e categorias de erro.
 * Nao gravar mensagens brutas de excecoes:
 * elas podem conter dados sensiveis.
 */
function googleLogVerificationError(Throwable $error): void
{
    $message = strtolower($error->getMessage());
    $category = 'nao_classificado';

    $patterns = [
        'certificado_tls' =>
            '/ssl|tls|certificate verify|peer certificate|ca bundle/i',

        'conexao_http' =>
            '/timed?\s*out|connection|resolve host|http request|http response|network|could not fetch/i',

        'certificado_chave' =>
            '/certificate|certs|public key|jwks|jwk|key id|\bkid\b|no matching key/i',

        'assinatura_algoritmo' =>
            '/signature|algorithm|\balg\b|signed/i',

        'token_expirado' =>
            '/expired|expiration|\bexp\b/i',

        'emissor_audiencia' =>
            '/issuer|audience|\biss\b|\baud\b/i',

        'formato_jwt' =>
            '/jwt|token format|segments|decode|malformed/i',
    ];

    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $message) === 1) {
            $category = $label;
            break;
        }
    }

    error_log(sprintf(
        '[SISELO GOOGLE OAUTH] etapa=verify_id_token classe=%s categoria=%s codigo=%d',
        get_class($error),
        $category,
        (int) $error->getCode()
    ));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    exit('Metodo nao permitido.');
}

// A tentativa pode ser utilizada somente uma vez.
$pending = $_SESSION['google_oidc_pending'] ?? null;
unset($_SESSION['google_oidc_pending']);

if (
    !is_array($pending)
    || !isset(
        $pending['state'],
        $pending['nonce'],
        $pending['code_verifier'],
        $pending['created_at'],
        $pending['mode']
    )
    || time() - (int) $pending['created_at'] > 600
    || (int) $pending['created_at'] > time() + 30
    || !is_string($_GET['state'] ?? null)
    || !hash_equals((string) $pending['state'], $_GET['state'])
) {
    googleFinish('sessao_expirada');
}

if (isset($_GET['error'])) {
    googleFinish('autorizacao_cancelada');
}

$code = $_GET['code'] ?? null;

if (!is_string($code) || $code === '') {
    googleFinish('codigo_ausente');
}

$clientId = (string) (getenv('GOOGLE_CLIENT_ID') ?: '');
$clientSecret = (string) (getenv('GOOGLE_CLIENT_SECRET') ?: '');
$redirectUri = (string) (getenv('GOOGLE_REDIRECT_URI') ?: '');

if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
    googleFinish('configuracao_incompleta');
}

// Troca do codigo de autorizacao por tokens (com PKCE).
$curl = curl_init('https://oauth2.googleapis.com/token');

if ($curl === false) {
    googleFinish('falha_comunicacao_google');
}

curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded'
    ],
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'code_verifier' => (string) $pending['code_verifier'],
    ], '', '&', PHP_QUERY_RFC3986),
]);

$response = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if (!is_string($response) || $status !== 200) {
    googleFinish('falha_troca_token');
}

$tokens = json_decode($response, true);
$idToken = is_array($tokens) ? ($tokens['id_token'] ?? null) : null;

if (!is_string($idToken) || $idToken === '') {
    googleFinish('token_ausente');
}

try {
    $claims = (new AccessToken())->verify($idToken, [
        'audience' => $clientId,
        'throwException' => true,
    ]);
} catch (Throwable $error) {
    googleLogVerificationError($error);
    googleFinish('token_invalido');
}

// Validacoes adicionais de identidade OIDC.
if (
    !is_array($claims)
    || !in_array($claims['iss'] ?? '', [
        'https://accounts.google.com',
        'accounts.google.com',
    ], true)
    || !is_string($claims['aud'] ?? null)
    || !hash_equals($clientId, $claims['aud'])
    || !is_string($claims['nonce'] ?? null)
    || !hash_equals((string) $pending['nonce'], $claims['nonce'])
    || !is_string($claims['sub'] ?? null)
    || $claims['sub'] === ''
    || (isset($claims['azp']) && $claims['azp'] !== $clientId)
) {
    googleFinish('identidade_invalida');
}

$issuer = 'https://accounts.google.com';
$subject = $claims['sub'];
$mode = (string) $pending['mode'];

// VINCULACAO: exige sessao autenticada e conta aprovada.
if ($mode === 'link') {
    $userId = isset($pending['user_id'])
        ? (int) $pending['user_id']
        : 0;

    if ($userId <= 0 || current_user_id() !== $userId) {
        googleFinish('sessao_vinculacao_invalida');
    }

    $user = User::findById($pdo, $userId);

    if (
        $user === null
        || User::accessBlockMessage($user) !== null
    ) {
        googleFinish('usuario_nao_autorizado');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT id, user_id
            FROM user_external_identities
            WHERE issuer = :issuer
              AND subject = :subject
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            ':issuer' => $issuer,
            ':subject' => $subject,
        ]);

        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            $existing
            && (int) $existing['user_id'] !== $userId
        ) {
            $pdo->rollBack();
            googleFinish('google_ja_vinculado');
        }

        $stmt = $pdo->prepare("
            SELECT id
            FROM user_external_identities
            WHERE user_id = :user_id
              AND provider = 'google'
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([':user_id' => $userId]);
        $another = $stmt->fetchColumn();

        if (!$existing && $another !== false) {
            $pdo->rollBack();
            googleFinish('conta_ja_tem_google');
        }

        if (!$existing) {
            $stmt = $pdo->prepare("
                INSERT INTO user_external_identities (
                    user_id,
                    provider,
                    issuer,
                    subject,
                    linked_at,
                    last_login_at
                )
                VALUES (
                    :user_id,
                    'google',
                    :issuer,
                    :subject,
                    NOW(),
                    NOW()
                )
            ");

            $stmt->execute([
                ':user_id' => $userId,
                ':issuer' => $issuer,
                ':subject' => $subject,
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE user_external_identities
                SET last_login_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute([
                ':id' => (int) $existing['id'],
            ]);
        }

        Audit::log(
            $pdo,
            $userId,
            'link_google',
            'users',
            $userId,
            null,
            ['provider' => 'google']
        );

        $pdo->commit();

    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        googleFinish('falha_vinculacao');
    }

    googleFinish('vinculado', true);
}

// LOGIN: apenas identidades previamente vinculadas.
if ($mode !== 'login') {
    googleFinish('modo_invalido');
}

$stmt = $pdo->prepare("
    SELECT user_id, id
    FROM user_external_identities
    WHERE provider = 'google'
      AND issuer = :issuer
      AND subject = :subject
    LIMIT 1
");

$stmt->execute([
    ':issuer' => $issuer,
    ':subject' => $subject,
]);

$identity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$identity) {
    googleFinish('conta_nao_vinculada');
}

$user = User::findById(
    $pdo,
    (int) $identity['user_id']
);

if (
    $user === null
    || User::accessBlockMessage($user) !== null
) {
    googleFinish('usuario_nao_autorizado');
}

try {
    $stmt = $pdo->prepare("
        UPDATE user_external_identities
        SET last_login_at = NOW()
        WHERE id = :id
    ");

    $stmt->execute([
        ':id' => (int) $identity['id'],
    ]);

    Audit::log(
        $pdo,
        (int) $user['id'],
        'login_google',
        'users',
        (int) $user['id'],
        null,
        ['provider' => 'google']
    );

} catch (Throwable $error) {
    googleFinish('falha_registro_login');
}

// Criar sessao autenticada do SIS ELO.
session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];

googleFinish('autenticado', true);
