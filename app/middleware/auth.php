<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/User.php';

/**
 * SIS ELO
 * Middleware de autenticacao.
 *
 * - APIs: retornam JSON com codigo HTTP adequado.
 * - Paginas web: redirecionam para o frontend.
 * - Usuarios pendentes, inativos ou excluidos nao acessam.
 * - Falhas na verificacao do banco impedem o acesso.
 */
function require_auth(): void
{
    global $pdo;

    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $requestPath = parse_url($requestUri, PHP_URL_PATH);
    $requestPath = is_string($requestPath) ? $requestPath : '';

    $isApi = preg_match('#^/api(?:/|$)#', $requestPath) === 1;

    $deny = static function (
        int $status,
        string $message
    ) use ($isApi): never {

        if ($isApi) {
            http_response_code($status);

            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');

            echo json_encode(
                [
                    'success' => false,
                    'message' => $message
                ],
                JSON_UNESCAPED_UNICODE
            );

            exit;
        }

        if ($status === 503) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');

            echo 'Servico temporariamente indisponivel.';
            exit;
        }

        $frontendOrigin = getenv('FRONTEND_ORIGIN');

        if (!is_string($frontendOrigin) || $frontendOrigin === '') {
            $frontendOrigin = 'http://localhost:3000';
        }

        $loginUrl = rtrim($frontendOrigin, '/') . '/login.html';

        header('Cache-Control: no-store');
        header('Location: ' . $loginUrl, true, 302);
        exit;
    };

    // 1. Verifica se existe uma sessao autenticada.
    $userId = current_user_id();

    if (!$userId) {
        $deny(401, 'Autenticacao necessaria.');
    }

    // 2. Nao permite acesso sem conexao valida com o banco.
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        error_log('SIS ELO: PDO indisponivel na autenticacao.');

        $deny(503, 'Servico temporariamente indisponivel.');
    }

    // 3. Consulta a situacao atual do usuario.
    try {
        $user = User::findById($pdo, (int)$userId);
    } catch (Throwable $error) {
        error_log(
            'SIS ELO: falha na consulta de autenticacao: '
            . $error->getMessage()
        );

        $deny(503, 'Servico temporariamente indisponivel.');
    }

    // 4. Sessao sem usuario valido.
    if ($user === null) {
        $_SESSION = [];

        $deny(401, 'Sessao invalida. Faca login novamente.');
    }

    // 5. Bloqueia usuarios pendentes, inativos ou sem acesso.
    $blockMessage = User::accessBlockMessage($user);

    if ($blockMessage !== null) {
        $_SESSION = [];

        $deny(403, 'Acesso nao autorizado. Contate o administrador.');
    }

    // Usuario autenticado, existente e autorizado.
}
