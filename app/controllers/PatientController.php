<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Patient.php';

final class PatientController {
  public static function list(PDO $pdo): never {
    api_require_permission($pdo, 'patients.view');

    $query = trim((string)(api_query_param('q', '') ?? ''));
    api_success([
      'q' => $query,
      'rows' => Patient::listActive($pdo, $query),
    ]);
  }

  public static function trash(PDO $pdo): never {
    api_require_permission($pdo, 'patients.restore');

    $query = trim((string)(api_query_param('q', '') ?? ''));
    api_success([
      'q' => $query,
      'rows' => Patient::listTrash($pdo, $query),
    ]);
  }

  public static function show(PDO $pdo): never {
    api_require_permission($pdo, 'patients.view');

    $id = (int)(api_query_param('id', '0') ?? '0');
    if ($id <= 0) {
      api_error('Paciente invalido.', 422);
    }

    $patient = Patient::find($pdo, $id);
    if ($patient === null) {
      api_error('Paciente nao encontrado.', 404);
    }

    api_success([
      'patient' => $patient,
      'care_plans' => can($pdo, 'careplans.view') ? Patient::carePlansFor($pdo, $id) : [],
      'encounters' => can($pdo, 'encounters.view') ? Patient::encountersFor($pdo, $id) : [],
      'transitions' => can($pdo, 'transitions.view') ? Patient::transitionsFor($pdo, $id) : [],
      'appointments' => can($pdo, 'careflow.view') ? Patient::appointmentsFor($pdo, $id) : [],
      'options' => Patient::options(),
    ]);
  }

  public static function form(PDO $pdo): never {
    $id = (int)(api_query_param('id', '0') ?? '0');
    $editing = $id > 0;

    if (!$editing) {
      $user = api_current_user($pdo);
      $isUbs = strtoupper(trim((string)($user['user_type'] ?? ''))) === 'UBS';
      if (!$isUbs && !can($pdo, 'admin.manage')) {
        api_error('O cadastro de pacientes e exclusivo da UBS.', 403);
      }
    }

    if (api_request_method() === 'GET') {
      api_require_permission($pdo, $editing ? 'patients.update' : 'patients.create');

      $context = Patient::formContext($pdo, $editing ? $id : null);
      if ($context['error'] !== null) {
        api_error((string)$context['error'], 404);
      }

      api_success($context);
    }

    api_require_permission($pdo, $editing ? 'patients.update' : 'patients.create');
    api_verify_csrf();

    $existing = $editing ? Patient::find($pdo, $id) : null;
    $validation = Patient::validate(api_request_input(), $editing, $existing);
    if ($validation['errors'] !== []) {
      api_error('Revise os campos destacados e tente novamente.', 422, $validation['errors']);
    }

    try {
      $context = Patient::save($pdo, $editing ? $id : null, $validation['data'], api_require_user_id());
      api_success($context);
    } catch (Throwable $error) {
      $mapped = Patient::mapPersistenceError($error);
      if ($mapped['field'] !== null) {
        api_error('Revise o campo destacado e tente novamente.', 422, [
          $mapped['field'] => $mapped['message'],
        ]);
      }

      api_error($mapped['message'], 500);
    }
  }

  public static function softDelete(PDO $pdo): never {
    api_require_permission($pdo, 'patients.delete');
    api_verify_csrf();

    $input = api_request_input();
    $id = (int)($input['id'] ?? 0);
    $patient = Patient::softDelete($pdo, $id, api_require_user_id());

    if ($patient === null) {
      api_error('Paciente nao encontrado.', 404);
    }

    api_success(['patient' => $patient]);
  }

  public static function restore(PDO $pdo): never {
    api_require_permission($pdo, 'patients.restore');
    api_verify_csrf();

    $input = api_request_input();
    $id = (int)($input['id'] ?? 0);
    $patient = Patient::restore($pdo, $id, api_require_user_id());

    if ($patient === null) {
      api_error('Paciente nao encontrado.', 404);
    }

    api_success(['patient' => $patient]);
  }

  public static function destroy(PDO $pdo): never {
    api_require_permission($pdo, 'patients.delete');
    api_verify_csrf();

    $input = api_request_input();
    $id = (int)($input['id'] ?? 0);
    $patient = Patient::destroy($pdo, $id, api_require_user_id());

    if ($patient === null) {
      api_error('Paciente nao encontrado na lixeira.', 404);
    }

    api_success(['patient' => $patient]);
  }
}
