<?php
declare(strict_types=1);

require __DIR__ . '/../../../../app/api/bootstrap.php';
require_once __DIR__ . '/../../../../app/models/User.php';

/*
 * SIS ELO - Google OpenID Connect
 *
 * GET:
 * Inicia o login de uma identidade Google
 * previamente vinculada ao SIS ELO.
 *
 * POST:
 * Inicia a vinculação de uma identidade Google
 * a uma conta SIS ELO já autenticada.
 *
 * O callback fará a validação da identidade.
 */

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

$method = api_request_method();

if (!in_array($method, ['GET', 'POST'], true)) {
    api_error('Método não permitido.', 405);
}

$clientId = trim(
    (string)(getenv('GOOGLE_CLIENT_ID') ?: '')
);

$redirectUri = trim(
    (string)(getenv('GOOGLE_REDIRECT_URI') ?: '')
);

if ($clientId === '' || $redirectUri === '') {
    api_error(
        'Autenticação Google não configurada.',
        503
    );
}

/*
 * GET = Login com identidade já vinculada.
 * POST = Vinculação explícita pelo usuário.
 */

$mode = $method === 'POST'
    ? 'link'
    : 'login';

$linkUserId = null;

if ($mode === 'link') {
    api_verify_csrf();

    $linkUserId = api_require_user_id();

    $user = User::findById(
        $pdo,
        $linkUserId
    );

    if ($user === null) {
        api_error(
            'Usuário não encontrado.',
            401
        );
    }

    $blockMessage = User::accessBlockMessage($user);

    if ($blockMessage !== null) {
        api_error($blockMessage, 403);
    }
}

/*
 * Gerar valores criptograficamente aleatórios.
 */

try {
    $state = bin2hex(random_bytes(32));

    $nonce = bin2hex(random_bytes(32));

    $verifier = rtrim(
        strtr(
            base64_encode(random_bytes(48)),
            '+/',
            '-_'
        ),
        '='
    );
} catch (Throwable $error) {
    api_error(
        'Não foi possível iniciar a autenticação.',
        500
    );
}

/*
 * PKCE com SHA-256.
 */

$challenge = rtrim(
    strtr(
        base64_encode(
            hash('sha256', $verifier, true)
        ),
        '+/',
        '-_'
    ),
    '='
);

/*
 * Guardar os dados na sessão PHP.
 *
 * Nunca enviar o code_verifier ao navegador
 * ou ao provedor durante a autorização.
 */

$_SESSION['google_oidc_pending'] = [
    'state' => $state,
    'nonce' => $nonce,
    'code_verifier' => $verifier,
    'created_at' => time(),
    'mode' => $mode,
    'user_id' => $linkUserId,
];

/*
 * Parâmetros OpenID Connect do Google.
 */

$params = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'nonce' => $nonce,
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
    'prompt' => 'select_account',
];

$authorizationUrl =
    'https://accounts.google.com/o/oauth2/v2/auth?' .
    http_build_query(
        $params,
        '',
        '&',
        PHP_QUERY_RFC3986
    );

/*
 * Retorna somente o endereço de autorização.
 * O frontend fará o redirecionamento.
 *
 * Nenhuma conta é vinculada nesta etapa.
 */

api_success([
    'provider' => 'google',
    'mode' => $mode,
    'authorization_url' => $authorizationUrl,
]);
