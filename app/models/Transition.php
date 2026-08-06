<?php
declare(strict_types=1);

require_once __DIR__ . '/CarePlan.php';
require_once __DIR__ . '/CareContracts.php';
require_once __DIR__ . '/CareNetwork.php';
require_once __DIR__ . '/../services/Audit.php';

final class Transition {
  public static function statuses(): array {
    return ['pendente', 'em_andamento', 'concluida', 'cancelada'];
  }

  public static function list(PDO $pdo, string $query = '', ?int $patientId = null, bool $trash = false): array {
    $sql = '
      SELECT t.*, p.full_name, p.cpf, p.team_ref, p.care_status, creator.name AS created_by_name
      FROM transitions t
      JOIN patients p ON p.id = t.patient_id
      LEFT JOIN users creator ON creator.id = t.created_by_user_id
      WHERE t.deleted_at IS ' . ($trash ? 'NOT NULL' : 'NULL') . '
        AND p.deleted_at IS NULL AND t.flow_type = \'care_transition\'
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
      if ($row === null || (string)($row['flow_type'] ?? 'care_transition') !== 'care_transition') {
        return [
          'editing' => true,
          'row' => null,
          'patients' => self::patientOptions($pdo),
          'statuses' => self::statuses(),
          'ubs_options' => CareNetwork::ubsOptions(),
          'team_options' => CareNetwork::teamOptions(),
          'ubs_team_groups' => CareNetwork::groups(),
          'error' => 'Encaminhamento nao encontrado.',
        ];
      }
    } else {
      $row = [
        'patient_id' => $prefPatientId ?: '',
        'transition_date' => date('Y-m-d'),
        'from_service' => 'CADH',
        'to_service' => '',
        'destination_ubs' => '',
        'destination_team' => '',
        'status' => 'concluida',
        'notes' => '',
      ];
    }

    return [
      'editing' => $editing,
      'row' => $row,
      'patients' => self::patientOptions($pdo),
      'statuses' => self::statuses(),
      'ubs_options' => CareNetwork::ubsOptions(),
      'team_options' => CareNetwork::teamOptions(),
      'ubs_team_groups' => CareNetwork::groups(),
      'error' => null,
    ];
  }

  public static function validate(array $payload): array {
    $data = [
      'patient_id' => (int)($payload['patient_id'] ?? 0),
      'transition_date' => trim((string)($payload['transition_date'] ?? '')),
      'from_service' => trim((string)($payload['from_service'] ?? '')),
      'to_service' => trim((string)($payload['to_service'] ?? '')),
      'destination_ubs' => trim((string)($payload['destination_ubs'] ?? $payload['to_service'] ?? '')),
      'destination_team' => trim((string)($payload['destination_team'] ?? '')),
      'status' => 'concluida',
      'notes' => trim((string)($payload['notes'] ?? '')),
    ];

    $errors = [];
    if ($data['patient_id'] <= 0) {
      $errors['patient_id'] = 'Paciente obrigatorio.';
    }
    if ($data['transition_date'] === '') {
      $errors['transition_date'] = 'Data obrigatoria.';
    }
    $data['from_service'] = 'CADH';
    $data['destination_ubs'] = CareNetwork::normalizeUbs($data['destination_ubs']);
    $data['destination_team'] = CareNetwork::normalizeTeam($data['destination_team']);
    if ($data['destination_ubs'] === '') {
      $errors['destination_ubs'] = 'UBS de destino obrigatoria.';
    }
    if ($data['destination_team'] === '') {
      $errors['destination_team'] = 'Equipe de destino obrigatoria.';
    } elseif (!in_array($data['destination_team'], CareNetwork::teamsFor($data['destination_ubs']), true)) {
      $errors['destination_team'] = 'A equipe nao pertence a UBS selecionada.';
    }
    $data['to_service'] = $data['destination_ubs'];

    return ['data' => $data, 'errors' => $errors];
  }

  public static function save(PDO $pdo, ?int $id, array $data, int $actorUserId): array {
    $editing = $id !== null;
    $before = $editing ? self::find($pdo, (int)$id) : null;
    if ($editing && ($before === null || (string)($before['flow_type'] ?? 'care_transition') !== 'care_transition')) {
      throw new RuntimeException('Transicao de cuidado nao encontrada.');
    }
    if ($editing) {
      $data['patient_id'] = (int)$before['patient_id'];
    }

    $pdo->beginTransaction();
    try {
      $patient = $pdo->prepare('SELECT * FROM patients WHERE id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
      $patient->execute([':id' => $data['patient_id']]);
      $patientBefore = $patient->fetch();
      if (!$patientBefore) {
        throw new RuntimeException('Paciente nao encontrado.');
      }
      if (!$editing && in_array((string)($patientBefore['care_status'] ?? ''), ['finalizado', 'transicionado'], true)) {
        throw new RuntimeException('Paciente nao esta em acompanhamento ativo no CADH.');
      }

      if ($editing) {
        $stmt = $pdo->prepare("
          UPDATE transitions
          SET patient_id = :patient_id, flow_type = 'care_transition', transition_date = :transition_date,
              from_service = 'CADH', to_service = :to_service, destination_ubs = :destination_ubs,
              destination_team = :destination_team, status = 'concluida', notes = :notes, updated_at = NOW()
          WHERE id = :id AND flow_type = 'care_transition'
        ");
      } else {
        $stmt = $pdo->prepare("
          INSERT INTO transitions
            (patient_id, flow_type, transition_date, from_service, to_service, destination_ubs,
             destination_team, status, notes, received_at, created_by_user_id)
          VALUES
            (:patient_id, 'care_transition', :transition_date, 'CADH', :to_service, :destination_ubs,
             :destination_team, 'concluida', :notes, NOW(), :user_id)
        ");
      }
      $params = [
        ':patient_id' => $data['patient_id'],
        ':transition_date' => $data['transition_date'],
        ':to_service' => $data['destination_ubs'],
        ':destination_ubs' => $data['destination_ubs'],
        ':destination_team' => $data['destination_team'],
        ':notes' => $data['notes'] !== '' ? $data['notes'] : null,
      ];
      if ($editing) {
        $params[':id'] = $id;
      } else {
        $params[':user_id'] = $actorUserId;
      }
      $stmt->execute($params);
      $transitionId = $editing ? (int)$id : (int)$pdo->lastInsertId();

      $pdo->prepare("
        UPDATE patients
        SET ubs_ref = :ubs, team_ref = :team, care_status = 'transicionado', status = 'active', updated_at = NOW()
        WHERE id = :id AND deleted_at IS NULL
      ")->execute([
        ':ubs' => $data['destination_ubs'],
        ':team' => $data['destination_team'],
        ':id' => $data['patient_id'],
      ]);
      $pdo->prepare("
        UPDATE care_plans SET state = 'transicionado', updated_at = NOW()
        WHERE patient_id = :patient_id AND deleted_at IS NULL AND state <> 'transicionado'
      ")->execute([':patient_id' => $data['patient_id']]);

      Audit::log($pdo, $actorUserId, $editing ? 'update' : 'create', 'transitions', $transitionId, $before, $data);
      Audit::log($pdo, $actorUserId, 'transition_care', 'patients', (int)$data['patient_id'], $patientBefore, [
        'care_status' => 'transicionado',
        'ubs_ref' => $data['destination_ubs'],
        'team_ref' => $data['destination_team'],
      ]);
      $pdo->commit();
      return self::formContext($pdo, $transitionId);
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
