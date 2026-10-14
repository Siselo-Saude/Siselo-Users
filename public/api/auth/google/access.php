<?php
declare(strict_types=1);

require __DIR__ . '/../../../../app/api/bootstrap.php';
require_once __DIR__ . '/../../../../app/services/GoogleOnboarding.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$method = api_request_method();
if (!in_array($method, ['GET', 'POST'], true)) {
    api_error('Metodo nao permitido.', 405);
}

// Contexto assinado/validado pelo Google e salvo exclusivamente no servidor.
$identity = $_SESSION['google_onboarding'] ?? null;
if (!is_array($identity) ||
    !isset($identity['issuer'], $identity['subject'], $identity['email'],
            $identity['name'], $identity['created_at']) ||
    (int)$identity['created_at'] > time() + 30 ||
    time() - (int)$identity['created_at'] > 900) {
    unset($_SESSION['google_onboarding']);
    api_error('A verificacao Google expirou. Entre novamente pela Coopere.', 401);
}

try {
    $context = GoogleOnboarding::context($pdo, $identity);

    if ($method === 'GET') {
        api_success([
            'status' => $context['status'],
            'name' => (string)$identity['name'],
            'email' => (string)$identity['email'],
            'csrf' => csrf_token(),
        ]);
    }

    api_verify_csrf();

    if ($context['status'] !== 'new') {
        api_error('Esta identidade ja possui uma conta ou solicitacao.', 409);
    }

    GoogleOnboarding::register($pdo, $identity, api_request_input());

    api_success([
        'status' => 'pending',
        'message' => 'Solicitacao enviada. Aguarde a aprovacao do administrador do SIS ELO.',
    ], 201);
} catch (InvalidArgumentException $error) {
    api_error($error->getMessage(), 422);
} catch (DomainException $error) {
    api_error('Ja existe uma conta ou solicitacao para este email.', 409);
} catch (PDOException $error) {
    // Concorrencia ou duplicidade de cadastro, sem revelar detalhes do banco.
    error_log('[SISELO GOOGLE ONBOARDING] Falha de registro no banco.');
    api_error('Nao foi possivel concluir a solicitacao. Verifique se ela ja existe.', 409);
} catch (Throwable $error) {
    error_log('[SISELO GOOGLE ONBOARDING] Falha inesperada de cadastro.');
    api_error('Nao foi possivel concluir a solicitacao.', 500);
}
