<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/Audit.php';

final class CarePlan {
  public static function list(PDO $pdo, string $query = '', ?int $patientId = null, bool $trash = false): array {
    $sql = '
      SELECT cp.*, p.full_name, p.cpf, p.team_ref
      FROM care_plans cp
      JOIN patients p ON p.id = cp.patient_id
      WHERE cp.deleted_at IS ' . ($trash ? 'NOT NULL' : 'NULL') . ' AND p.deleted_at IS NULL
    ';
    $params = [];

    if ($patientId !== null) {
      $sql .= ' AND cp.patient_id = :patient_id';
      $params[':patient_id'] = $patientId;
    }

    if ($query !== '') {
      $sql .= ' AND (p.full_name LIKE :q_full_name OR p.cpf LIKE :q_cpf OR p.team_ref LIKE :q_team_ref)';
      $params[':q_full_name'] = '%' . $query . '%';
      $params[':q_cpf'] = '%' . $query . '%';
      $params[':q_team_ref'] = '%' . $query . '%';
    }

    $sql .= $trash ? ' ORDER BY cp.deleted_at DESC LIMIT 300' : ' ORDER BY cp.id DESC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return array_map(
      static function (array $row): array {
        $row['id'] = (int)$row['id'];
        $row['patient_id'] = (int)$row['patient_id'];
        return $row;
      },
      $rows
    );
  }

  public static function find(PDO $pdo, int $id, bool $allowDeleted = false): ?array {
    $sql = 'SELECT * FROM care_plans WHERE id = :id';
    if (!$allowDeleted) {
      $sql .= ' AND deleted_at IS NULL';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
      return null;
    }

    $row['id'] = (int)$row['id'];
    $row['patient_id'] = (int)$row['patient_id'];
    return $row;
  }

  public static function items(PDO $pdo, int $carePlanId): array {
    $stmt = $pdo->prepare('SELECT * FROM care_plan_items WHERE care_plan_id = :id ORDER BY sort_order ASC, id ASC');
    $stmt->execute([':id' => $carePlanId]);
    $rows = $stmt->fetchAll();

    return array_map(
      static function (array $row): array {
        $row['id'] = (int)$row['id'];
        $row['care_plan_id'] = (int)$row['care_plan_id'];
        $row['sort_order'] = (int)$row['sort_order'];
        return $row;
      },
      $rows
    );
  }

  public static function patientOptions(PDO $pdo): array {
    $stmt = $pdo->query("
      SELECT id, full_name, cpf, team_ref
      FROM patients
      WHERE deleted_at IS NULL
        AND COALESCE(care_status, 'recebido') <> 'finalizado'
      ORDER BY full_name ASC
      LIMIT 500
    ");
    $rows = $stmt->fetchAll();

    return array_map(
      static function (array $row): array {
        $row['id'] = (int)$row['id'];
        return $row;
      },
      $rows
    );
  }

  public static function formContext(PDO $pdo, ?int $id = null, int $prefPatientId = 0): array {
    $editing = $id !== null;

    if ($editing) {
      $plan = self::find($pdo, $id);
      if ($plan === null) {
        return [
          'editing' => true,
          'plan' => null,
          'items' => [],
          'patients' => self::patientOptions($pdo),
          'error' => 'Plano nao encontrado.',
        ];
      }

      $items = self::items($pdo, $id);
    } else {
      $plan = [
        'patient_id' => $prefPatientId ?: '',
        'start_date' => '',
        'end_date' => '',
        'interventions' => '',
      ];
      $items = [];
    }

    return [
      'editing' => $editing,
      'plan' => $plan,
      'items' => $items,
      'patients' => self::patientOptions($pdo),
      'error' => null,
    ];
  }

  public static function validate(array $payload): array {
    $patientId = (int)($payload['patient_id'] ?? 0);
    $startDate = trim((string)($payload['start_date'] ?? ''));
    $endDate = trim((string)($payload['end_date'] ?? ''));
    $interventions = trim((string)($payload['interventions'] ?? ''));

    $itemType = array_values((array)($payload['item_type'] ?? []));
    $title = array_values((array)($payload['title'] ?? []));
    $situation = array_values((array)($payload['situation'] ?? []));
    $recommendation = array_values((array)($payload['recommendation'] ?? []));
    $difficulty = array_values((array)($payload['difficulty'] ?? []));
    $goal = array_values((array)($payload['goal'] ?? []));
    $sortOrder = array_values((array)($payload['sort_order'] ?? []));

    $errors = [];
    if ($patientId <= 0) {
      $errors['patient_id'] = 'Paciente obrigatorio.';
    }

    if ($startDate === '') {
      $errors['start_date'] = 'Data de inicio obrigatoria.';
    }

    $items = [];
    $count = count($itemType);
    for ($index = 0; $index < $count; $index++) {
      $type = trim((string)($itemType[$index] ?? ''));
      $row = [
        'item_type' => $type,
        'title' => trim((string)($title[$index] ?? '')),
        'situation' => trim((string)($situation[$index] ?? '')),
        'recommendation' => trim((string)($recommendation[$index] ?? '')),
        'difficulty' => trim((string)($difficulty[$index] ?? '')),
        'goal' => trim((string)($goal[$index] ?? '')),
        'sort_order' => (int)($sortOrder[$index] ?? $index + 1),
      ];

      if ($type === '') {
        continue;
      }

      if ($row['title'] === '' && $row['situation'] === '' && $row['recommendation'] === '' && $row['difficulty'] === '' && $row['goal'] === '') {
        continue;
      }

      $items[] = $row;
    }

    return [
      'errors' => $errors,
      'data' => [
        'patient_id' => $patientId,
        'start_date' => $startDate,
        'end_date' => ($endDate === '' ? null : $endDate),
        'interventions' => $interventions,
        'items' => $items,
      ],
    ];
  }

  public static function save(PDO $pdo, ?int $id, array $data, int $actorUserId): array {
    $editing = $id !== null;

    $pdo->beginTransaction();

    try {
      if ($editing) {
        $before = self::find($pdo, $id);
        if ($before === null) {
          throw new RuntimeException('Plano nao encontrado.');
        }

        $update = $pdo->prepare('UPDATE care_plans SET patient_id = :patient_id, start_date = :start_date, end_date = :end_date, interventions = :interventions, updated_at = NOW() WHERE id = :id');
        $update->execute([
          ':patient_id' => $data['patient_id'],
          ':start_date' => $data['start_date'],
          ':end_date' => $data['end_date'],
          ':interventions' => $data['interventions'],
          ':id' => $id,
        ]);

        $pdo->prepare('DELETE FROM care_plan_items WHERE care_plan_id = :id')->execute([':id' => $id]);
        $planId = $id;

        Audit::log($pdo, $actorUserId, 'update', 'care_plans', $id, $before, [
          'patient_id' => $data['patient_id'],
          'start_date' => $data['start_date'],
          'end_date' => $data['end_date'],
        ]);
      } else {
        $insert = $pdo->prepare('INSERT INTO care_plans (patient_id, start_date, end_date, interventions, created_by_user_id) VALUES (:patient_id, :start_date, :end_date, :interventions, :user_id)');
        $insert->execute([
          ':patient_id' => $data['patient_id'],
          ':start_date' => $data['start_date'],
          ':end_date' => $data['end_date'],
          ':interventions' => $data['interventions'],
          ':user_id' => $actorUserId,
        ]);

        $planId = (int)$pdo->lastInsertId();
        Audit::log($pdo, $actorUserId, 'create', 'care_plans', $planId, null, [
          'patient_id' => $data['patient_id'],
          'start_date' => $data['start_date'],
          'end_date' => $data['end_date'],
        ]);
      }

      $insertItem = $pdo->prepare('
        INSERT INTO care_plan_items (care_plan_id, item_type, title, situation, recommendation, difficulty, goal, sort_order)
        VALUES (:care_plan_id, :item_type, :title, :situation, :recommendation, :difficulty, :goal, :sort_order)
      ');

      foreach ($data['items'] as $item) {
        $insertItem->execute([
          ':care_plan_id' => $planId,
          ':item_type' => $item['item_type'],
          ':title' => $item['title'],
          ':situation' => $item['situation'],
          ':recommendation' => $item['recommendation'],
          ':difficulty' => $item['difficulty'],
          ':goal' => $item['goal'],
          ':sort_order' => $item['sort_order'],
        ]);
      }

      $pdo->commit();
      return self::formContext($pdo, $planId);
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      throw $error;
    }
  }

  public static function softDelete(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::find($pdo, $id);
    if ($before === null) {
      return null;
    }

    $stmt = $pdo->prepare('UPDATE care_plans SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $id]);

    Audit::log($pdo, $actorUserId, 'delete', 'care_plans', $id, $before, ['deleted_at' => date('c')]);
    return self::find($pdo, $id, true);
  }

  public static function restore(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::find($pdo, $id, true);
    if ($before === null || $before['deleted_at'] === null) {
      return null;
    }

    $stmt = $pdo->prepare('UPDATE care_plans SET deleted_at = NULL, updated_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $id]);

    Audit::log($pdo, $actorUserId, 'restore', 'care_plans', $id, $before, ['deleted_at' => null]);
    return self::find($pdo, $id);
  }

  public static function destroy(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::find($pdo, $id, true);
    if ($before === null || $before['deleted_at'] === null) {
      return null;
    }

    $pdo->beginTransaction();
    try {
      $pdo->prepare('DELETE FROM care_plan_items WHERE care_plan_id = :id')->execute([':id' => $id]);
      $pdo->prepare('DELETE FROM care_plans WHERE id = :id')->execute([':id' => $id]);
      Audit::log($pdo, $actorUserId, 'destroy', 'care_plans', $id, $before, null);
      $pdo->commit();
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      throw $error;
    }

    return $before;
  }
}
