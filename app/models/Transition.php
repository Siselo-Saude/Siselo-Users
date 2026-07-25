<?php
declare(strict_types=1);

require_once __DIR__ . '/CarePlan.php';
require_once __DIR__ . '/../services/Audit.php';

final class Transition {
  public static function statuses(): array {
    return ['pendente', 'em_andamento', 'concluida', 'cancelada'];
  }

  public static function list(PDO $pdo, string $query = '', ?int $patientId = null, bool $trash = false): array {
    $sql = '
      SELECT t.*, p.full_name, p.cpf, p.team_ref, p.care_status
      FROM transitions t
      JOIN patients p ON p.id = t.patient_id
      WHERE t.deleted_at IS ' . ($trash ? 'NOT NULL' : 'NULL') . ' AND p.deleted_at IS NULL
    ';
    $params = [];

    if ($patientId !== null) {
      $sql .= ' AND t.patient_id = :patient_id';
      $params[':patient_id'] = $patientId;
    }

    if ($query !== '') {
      $sql .= ' AND (p.full_name LIKE :q_full_name OR p.cpf LIKE :q_cpf OR p.team_ref LIKE :q_team_ref OR t.status LIKE :q_status OR t.to_service LIKE :q_to_service OR t.from_service LIKE :q_from_service)';
      $params[':q_full_name'] = '%' . $query . '%';
      $params[':q_cpf'] = '%' . $query . '%';
      $params[':q_team_ref'] = '%' . $query . '%';
      $params[':q_status'] = '%' . $query . '%';
      $params[':q_to_service'] = '%' . $query . '%';
      $params[':q_from_service'] = '%' . $query . '%';
    }

    $sql .= $trash ? ' ORDER BY t.deleted_at DESC LIMIT 300' : ' ORDER BY t.transition_date DESC, t.id DESC LIMIT 300';

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
    $sql = 'SELECT * FROM transitions WHERE id = :id';
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

  public static function patientOptions(PDO $pdo): array {
    return CarePlan::patientOptions($pdo);
  }

  public static function formContext(PDO $pdo, ?int $id = null, int $prefPatientId = 0): array {
    $editing = $id !== null;

    if ($editing) {
      $row = self::find($pdo, $id);
      if ($row === null) {
        return [
          'editing' => true,
          'row' => null,
          'patients' => self::patientOptions($pdo),
          'statuses' => self::statuses(),
          'error' => 'Transicao nao encontrada.',
        ];
      }
    } else {
      $row = [
        'patient_id' => $prefPatientId ?: '',
        'transition_date' => date('Y-m-d'),
        'from_service' => '',
        'to_service' => '',
        'status' => 'pendente',
        'notes' => '',
      ];
    }

    return [
      'editing' => $editing,
      'row' => $row,
      'patients' => self::patientOptions($pdo),
      'statuses' => self::statuses(),
      'error' => null,
    ];
  }

  public static function validate(array $payload): array {
    $data = [
      'patient_id' => (int)($payload['patient_id'] ?? 0),
      'transition_date' => trim((string)($payload['transition_date'] ?? '')),
      'from_service' => trim((string)($payload['from_service'] ?? '')),
      'to_service' => trim((string)($payload['to_service'] ?? '')),
      'status' => trim((string)($payload['status'] ?? 'pendente')),
      'notes' => trim((string)($payload['notes'] ?? '')),
    ];

    $errors = [];
    if ($data['patient_id'] <= 0) {
      $errors['patient_id'] = 'Paciente obrigatorio.';
    }
    if ($data['transition_date'] === '') {
      $errors['transition_date'] = 'Data obrigatoria.';
    }
    if ($data['status'] === '') {
      $errors['status'] = 'Status obrigatorio.';
    } elseif (!in_array($data['status'], self::statuses(), true)) {
      $errors['status'] = 'Status invalido.';
    }

    return ['data' => $data, 'errors' => $errors];
  }

  public static function save(PDO $pdo, ?int $id, array $data, int $actorUserId): array {
    $editing = $id !== null;

    if ($editing) {
      $before = self::find($pdo, $id);
      if ($before === null) {
        throw new RuntimeException('Transicao nao encontrada.');
      }

      $stmt = $pdo->prepare('
        UPDATE transitions
        SET patient_id = :patient_id, transition_date = :transition_date, from_service = :from_service, to_service = :to_service, status = :status, notes = :notes, updated_at = NOW()
        WHERE id = :id
      ');
      $stmt->execute([
        ':patient_id' => $data['patient_id'],
        ':transition_date' => $data['transition_date'],
        ':from_service' => $data['from_service'],
        ':to_service' => $data['to_service'],
        ':status' => $data['status'],
        ':notes' => $data['notes'],
        ':id' => $id,
      ]);

      Audit::log($pdo, $actorUserId, 'update', 'transitions', $id, $before, $data);
      return self::formContext($pdo, $id);
    }

    $stmt = $pdo->prepare('
      INSERT INTO transitions (patient_id, transition_date, from_service, to_service, status, notes, created_by_user_id)
      VALUES (:patient_id, :transition_date, :from_service, :to_service, :status, :notes, :user_id)
    ');
    $stmt->execute([
      ':patient_id' => $data['patient_id'],
      ':transition_date' => $data['transition_date'],
      ':from_service' => $data['from_service'],
      ':to_service' => $data['to_service'],
      ':status' => $data['status'],
      ':notes' => $data['notes'],
      ':user_id' => $actorUserId,
    ]);

    $newId = (int)$pdo->lastInsertId();
    Audit::log($pdo, $actorUserId, 'create', 'transitions', $newId, null, $data);
    return self::formContext($pdo, $newId);
  }

  public static function softDelete(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::find($pdo, $id);
    if ($before === null) {
      return null;
    }

    $stmt = $pdo->prepare('UPDATE transitions SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $id]);
    Audit::log($pdo, $actorUserId, 'delete', 'transitions', $id, $before, ['deleted_at' => date('c')]);

    return self::find($pdo, $id, true);
  }

  public static function restore(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::find($pdo, $id, true);
    if ($before === null || $before['deleted_at'] === null) {
      return null;
    }

    $stmt = $pdo->prepare('UPDATE transitions SET deleted_at = NULL, updated_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $id]);
    Audit::log($pdo, $actorUserId, 'restore', 'transitions', $id, $before, ['deleted_at' => null]);

    return self::find($pdo, $id);
  }

  public static function destroy(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::find($pdo, $id, true);
    if ($before === null || $before['deleted_at'] === null) {
      return null;
    }

    $stmt = $pdo->prepare('DELETE FROM transitions WHERE id = :id');
    $stmt->execute([':id' => $id]);
    Audit::log($pdo, $actorUserId, 'destroy', 'transitions', $id, $before, null);

    return $before;
  }
}
