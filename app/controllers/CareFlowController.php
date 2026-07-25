<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/CareFlow.php';

final class CareFlowController {
  public static function list(PDO $pdo): never {
    api_require_permission($pdo, 'careflow.view');
    api_success([
      'rows' => CareFlow::list($pdo, [
        'q' => api_query_param('q', ''),
        'risk' => api_query_param('risk', ''),
        'care_status' => api_query_param('care_status', 'active'),
      ]),
      'options' => CareFlow::options(),
    ]);
  }

  public static function action(PDO $pdo): never {
    api_verify_csrf();
    $input = api_request_input();
    $action = trim((string)($input['action'] ?? ''));
    $actorUserId = api_require_user_id();

    try {
      if ($action === 'refer') {
        api_require_permission($pdo, 'careflow.update');
        api_success(CareFlow::refer($pdo, $input, $actorUserId), 201);
      }
      if ($action === 'schedule') {
        api_require_permission($pdo, 'careflow.schedule');
        api_success(['appointment' => CareFlow::schedule($pdo, $input, $actorUserId)], 201);
      }
      if ($action === 'move') {
        api_require_permission($pdo, 'careflow.update');
        api_success(['appointment' => CareFlow::move($pdo, $input, $actorUserId)]);
      }
      if ($action === 'finalize') {
        api_require_permission($pdo, 'careflow.finalize');
        api_success(['patient' => CareFlow::finalize($pdo, $input, $actorUserId)]);
      }
      if ($action === 'reopen') {
        api_require_permission($pdo, 'careflow.finalize');
        api_success(['patient' => CareFlow::reopen($pdo, (int)($input['patient_id'] ?? 0), $actorUserId)]);
      }
      api_error('Ação inválida.', 422);
    } catch (InvalidArgumentException $error) {
      api_error($error->getMessage(), 422);
    } catch (RuntimeException $error) {
      api_error($error->getMessage(), 404);
    } catch (Throwable $error) {
      api_error('Não foi possível atualizar o fluxo assistencial.', 500);
    }
  }
}
