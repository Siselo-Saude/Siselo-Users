<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Encounter.php';

final class EncounterController {
  public static function list(PDO $pdo): never {
    api_require_permission($pdo, 'encounters.view');

    $query = trim((string)(api_query_param('q', '') ?? ''));
    $patientId = (int)(api_query_param('patient_id', '0') ?? '0');
    $patientId = $patientId > 0 ? $patientId : null;
    api_success([
      'q' => $query,
      'patient_id' => $patientId,
      'rows' => Encounter::list($pdo, $query, $patientId, false),
    ]);
  }

  public static function trash(PDO $pdo): never {
    api_require_permission($pdo, 'encounters.restore');

    $query = trim((string)(api_query_param('q', '') ?? ''));
    $patientId = (int)(api_query_param('patient_id', '0') ?? '0');
    $patientId = $patientId > 0 ? $patientId : null;
    api_success([
      'q' => $query,
      'patient_id' => $patientId,
      'rows' => Encounter::list($pdo, $query, $patientId, true),
    ]);
  }

  public static function form(PDO $pdo): never {
    $id = (int)(api_query_param('id', '0') ?? '0');
    $patientId = (int)(api_query_param('patient_id', '0') ?? '0');
    $editing = $id > 0;

    if (api_request_method() === 'GET') {
      api_require_permission($pdo, $editing ? 'encounters.update' : 'encounters.create');
      $context = Encounter::formContext($pdo, $editing ? $id : null, $patientId);
      if ($context['error'] !== null) {
        api_error((string)$context['error'], 404);
      }

      api_success($context);
    }

    api_require_permission($pdo, $editing ? 'encounters.update' : 'encounters.create');
    api_verify_csrf();

    $validation = Encounter::validate(api_request_input());
    if ($validation['errors'] !== []) {
      api_error('Revise os dados do registro clinico.', 422, $validation['errors']);
    }

    try {
      $context = Encounter::save($pdo, $editing ? $id : null, $validation['data'], api_require_user_id());
      api_success($context);
    } catch (Throwable $error) {
      api_error('Erro ao salvar: ' . $error->getMessage(), 500);
    }
  }

  public static function softDelete(PDO $pdo): never {
    api_require_permission($pdo, 'encounters.delete');
    api_verify_csrf();

    $id = (int)(api_request_input()['id'] ?? 0);
    $row = Encounter::softDelete($pdo, $id, api_require_user_id());
    if ($row === null) {
      api_error('Atendimento nao encontrado.', 404);
    }

    api_success(['encounter' => $row]);
  }

  public static function restore(PDO $pdo): never {
    api_require_permission($pdo, 'encounters.restore');
    api_verify_csrf();

    $id = (int)(api_request_input()['id'] ?? 0);
    $row = Encounter::restore($pdo, $id, api_require_user_id());
    if ($row === null) {
      api_error('Atendimento nao encontrado.', 404);
    }

    api_success(['encounter' => $row]);
  }

  public static function destroy(PDO $pdo): never {
    api_require_permission($pdo, 'encounters.delete');
    api_verify_csrf();

    $id = (int)(api_request_input()['id'] ?? 0);
    $row = Encounter::destroy($pdo, $id, api_require_user_id());
    if ($row === null) {
      api_error('Atendimento nao encontrado na lixeira.', 404);
    }

    api_success(['encounter' => $row]);
  }
}
