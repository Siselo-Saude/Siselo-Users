<?php
declare(strict_types=1);

require_once __DIR__ . '/CarePlan.php';
require_once __DIR__ . '/../services/Audit.php';

final class Encounter {
  private const RECORD_TYPES = [
    'diagnostico' => 'Diagnóstico',
    'exame' => 'Exame',
    'consulta' => 'Consulta',
  ];

  public static function list(PDO $pdo, string $query = '', ?int $patientId = null, bool $trash = false): array {
    $sql = '
      SELECT e.*, p.full_name, p.cpf, p.team_ref
      FROM encounters e
      JOIN patients p ON p.id = e.patient_id
      WHERE e.deleted_at IS ' . ($trash ? 'NOT NULL' : 'NULL') . ' AND p.deleted_at IS NULL
    ';
    $params = [];

    if ($patientId !== null) {
      $sql .= ' AND e.patient_id = :patient_id';
      $params[':patient_id'] = $patientId;
    }

    if ($query !== '') {
      $sql .= ' AND (p.full_name LIKE :q_full_name OR p.cpf LIKE :q_cpf OR p.team_ref LIKE :q_team_ref OR e.specialty LIKE :q_specialty)';
      $params[':q_full_name'] = '%' . $query . '%';
      $params[':q_cpf'] = '%' . $query . '%';
      $params[':q_team_ref'] = '%' . $query . '%';
      $params[':q_specialty'] = '%' . $query . '%';
    }

    $sql .= $trash ? ' ORDER BY e.deleted_at DESC LIMIT 300' : ' ORDER BY e.encounter_date DESC, e.id DESC LIMIT 300';

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
    $sql = 'SELECT * FROM encounters WHERE id = :id';
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
          'error' => 'Atendimento nao encontrado.',
        ];
      }
    } else {
      $row = [
        'patient_id' => $prefPatientId ?: '',
        'encounter_date' => date('Y-m-d'),
        'specialty' => '',
        'record_type' => 'consulta',
        'summary' => '',
      ];
    }

    return [
      'editing' => $editing,
      'row' => $row,
      'patients' => self::patientOptions($pdo),
      'error' => null,
    ];
  }

  public static function validate(array $payload): array {
    $data = [
      'patient_id' => (int)($payload['patient_id'] ?? 0),
      'encounter_date' => trim((string)($payload['encounter_date'] ?? '')),
      'specialty' => trim((string)($payload['specialty'] ?? '')),
      'record_type' => trim((string)($payload['record_type'] ?? 'consulta')),
      'summary' => trim((string)($payload['summary'] ?? '')),
    ];

    $errors = [];
    if ($data['patient_id'] <= 0) {
      $errors['patient_id'] = 'Paciente obrigatorio.';
    }
    if ($data['encounter_date'] === '') {
      $errors['encounter_date'] = 'Data obrigatoria.';
    }
    if ($data['specialty'] === '') {
      $errors['specialty'] = 'Especialidade obrigatoria.';
    }
    if (!array_key_exists($data['record_type'], self::RECORD_TYPES)) {
      $errors['record_type'] = 'Tipo de registro invalido.';
    }

    return ['data' => $data, 'errors' => $errors];
  }

  public static function save(PDO $pdo, ?int $id, array $data, int $actorUserId): array {
    $editing = $id !== null;

    if ($editing) {
      $before = self::find($pdo, $id);
      if ($before === null) {
        throw new RuntimeException('Atendimento nao encontrado.');
      }

      $stmt = $pdo->prepare('
        UPDATE encounters
        SET patient_id = :patient_id, encounter_date = :encounter_date, specialty = :specialty, record_type = :record_type, summary = :summary, professional_user_id = :user_id, updated_at = NOW()
        WHERE id = :id
      ');
      $stmt->execute([
        ':patient_id' => $data['patient_id'],
        ':encounter_date' => $data['encounter_date'],
        ':specialty' => $data['specialty'],
        ':record_type' => $data['record_type'],
        ':summary' => $data['summary'],
        ':user_id' => $actorUserId,
        ':id' => $id,
      ]);

      Audit::log($pdo, $actorUserId, 'update', 'encounters', $id, $before, $data);
      return self::formContext($pdo, $id);
    }

    $stmt = $pdo->prepare('
      INSERT INTO encounters (patient_id, encounter_date, specialty, record_type, professional_user_id, summary)
      VALUES (:patient_id, :encounter_date, :specialty, :record_type, :user_id, :summary)
    ');
    $stmt->execute([
      ':patient_id' => $data['patient_id'],
      ':encounter_date' => $data['encounter_date'],
      ':specialty' => $data['specialty'],
      ':record_type' => $data['record_type'],
      ':user_id' => $actorUserId,
      ':summary' => $data['summary'],
    ]);

    $newId = (int)$pdo->lastInsertId();
    Audit::log($pdo, $actorUserId, 'create', 'encounters', $newId, null, $data);
    return self::formContext($pdo, $newId);
  }

  public static function softDelete(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::find($pdo, $id);
    if ($before === null) {
      return null;
    }

    $stmt = $pdo->prepare('UPDATE encounters SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $id]);
    Audit::log($pdo, $actorUserId, 'delete', 'encounters', $id, $before, ['deleted_at' => date('c')]);

    return self::find($pdo, $id, true);
  }

  public static function restore(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::find($pdo, $id, true);
    if ($before === null || $before['deleted_at'] === null) {
      return null;
    }

    $stmt = $pdo->prepare('UPDATE encounters SET deleted_at = NULL, updated_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $id]);
    Audit::log($pdo, $actorUserId, 'restore', 'encounters', $id, $before, ['deleted_at' => null]);

    return self::find($pdo, $id);
  }

  public static function destroy(PDO $pdo, int $id, int $actorUserId): ?array {
    $before = self::find($pdo, $id, true);
    if ($before === null || $before['deleted_at'] === null) {
      return null;
    }

    $stmt = $pdo->prepare('DELETE FROM encounters WHERE id = :id');
    $stmt->execute([':id' => $id]);
    Audit::log($pdo, $actorUserId, 'destroy', 'encounters', $id, $before, null);

    return $before;
  }
}
