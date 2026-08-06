<?php
declare(strict_types=1);

require_once __DIR__ . '/CarePlan.php';
require_once __DIR__ . '/../services/Audit.php';

final class Encounter {
  private const RECORD_TYPES = [
    'diagnostico' => 'Diagnóstico',
    'exame' => 'Exame',
    'consulta' => 'Consulta',
    'ubs_acompanhamento' => 'Acompanhamento pela UBS',
  ];

  public static function list(PDO $pdo, string $query = '', ?int $patientId = null, bool $trash = false): array {
    $sql = '
      SELECT
        e.*,
        p.full_name,
        p.cpf,
        p.team_ref,
        u.name AS professional_name,
        a.scheduled_at AS appointment_scheduled_at,
        a.specialty AS appointment_specialty,
        a.professional AS appointment_professional,
        a.team AS appointment_team,
        a.status AS appointment_status,
        follow_up.scheduled_at AS follow_up_scheduled_at,
        follow_up.specialty AS follow_up_specialty,
        follow_up.status AS follow_up_status
      FROM encounters e
      JOIN patients p ON p.id = e.patient_id
      LEFT JOIN users u ON u.id = e.professional_user_id
      LEFT JOIN patient_appointments a ON a.id = e.appointment_id
      LEFT JOIN patient_appointments follow_up ON follow_up.id = e.follow_up_appointment_id
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
      if ($row['appointment_id'] !== null) {
        $row['appointment_id'] = (int)$row['appointment_id'];
      }
      if ($row['follow_up_appointment_id'] !== null) {
        $row['follow_up_appointment_id'] = (int)$row['follow_up_appointment_id'];
      }
      if ($row['transition_id'] !== null) {
        $row['transition_id'] = (int)$row['transition_id'];
      }
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
    if ($row['appointment_id'] !== null) {
      $row['appointment_id'] = (int)$row['appointment_id'];
    }
    if ($row['follow_up_appointment_id'] !== null) {
      $row['follow_up_appointment_id'] = (int)$row['follow_up_appointment_id'];
    }
    if ($row['transition_id'] !== null) {
      $row['transition_id'] = (int)$row['transition_id'];
    }
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
        'appointment_id' => '',
        'follow_up_appointment_id' => '',
        'transition_id' => '',
        'encounter_date' => date('Y-m-d'),
        'specialty' => '',
        'record_type' => 'consulta',
        'schema_version' => '1.0',
        'payload_json' => null,
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
      'appointment_id' => (int)($payload['appointment_id'] ?? 0),
      'transition_id' => (int)($payload['transition_id'] ?? 0),
      'encounter_date' => trim((string)($payload['encounter_date'] ?? '')),
      'specialty' => trim((string)($payload['specialty'] ?? '')),
      'record_type' => trim((string)($payload['record_type'] ?? 'consulta')),
      'schema_version' => trim((string)($payload['schema_version'] ?? '1.0')),
      'payload_json' => self::normalizePayloadJson($payload['payload_json'] ?? null),
      'summary' => trim((string)($payload['summary'] ?? '')),
      'follow_up_date' => trim((string)($payload['follow_up_date'] ?? '')),
      'follow_up_time' => trim((string)($payload['follow_up_time'] ?? '')),
      'follow_up_at' => null,
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
    if ($data['schema_version'] === '') {
      $errors['schema_version'] = 'Versao do registro invalida.';
    }
    if ($data['record_type'] === 'ubs_acompanhamento' && $data['summary'] === '') {
      $errors['summary'] = 'Registre um resumo do acompanhamento realizado.';
    }
    if ($data['payload_json'] === false) {
      $errors['payload_json'] = 'Dados clinicos invalidos.';
      $data['payload_json'] = null;
    }
    if (($data['follow_up_date'] === '') !== ($data['follow_up_time'] === '')) {
      $errors['follow_up_at'] = 'Para agendar a consulta subsequente, informe a data e o horario.';
    } elseif ($data['follow_up_date'] !== '' && $data['follow_up_time'] !== '') {
      $followUp = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i',
        $data['follow_up_date'] . ' ' . $data['follow_up_time']
      );
      $followUpErrors = DateTimeImmutable::getLastErrors();
      $hasDateErrors = is_array($followUpErrors)
        && (((int)$followUpErrors['warning_count']) > 0 || ((int)$followUpErrors['error_count']) > 0);
      if (
        $followUp === false
        || $hasDateErrors
        || $followUp->format('Y-m-d') !== $data['follow_up_date']
        || $followUp->format('H:i') !== $data['follow_up_time']
      ) {
        $errors['follow_up_at'] = 'Data ou horario da consulta subsequente invalido.';
      } else {
        $data['follow_up_at'] = $followUp->format('Y-m-d H:i:s');
      }
    }

    return ['data' => $data, 'errors' => $errors];
  }

  public static function save(PDO $pdo, ?int $id, array $data, int $actorUserId): array {
    $pdo->beginTransaction();
    try {
      $before = $id !== null ? self::find($pdo, $id) : null;
      if ($id !== null && $before === null) {
        throw new RuntimeException('Atendimento nao encontrado.');
      }

      if ($before !== null && $data['appointment_id'] <= 0 && !empty($before['appointment_id'])) {
        $data['appointment_id'] = (int)$before['appointment_id'];
      }
      if ($before !== null && $data['payload_json'] === null && !empty($before['payload_json'])) {
        $data['payload_json'] = (string)$before['payload_json'];
      }
      if ($before !== null && $data['transition_id'] <= 0 && !empty($before['transition_id'])) {
        $data['transition_id'] = (int)$before['transition_id'];
      }

      if ($data['record_type'] === 'ubs_acompanhamento') {
        if ($data['transition_id'] <= 0) {
          throw new InvalidArgumentException('Vincule o acompanhamento a uma transicao CADH-UBS.');
        }
        $transition = $pdo->prepare("
          SELECT id FROM transitions
          WHERE id = :id AND patient_id = :patient_id AND flow_type = 'care_transition' AND deleted_at IS NULL
          LIMIT 1
        ");
        $transition->execute([':id' => $data['transition_id'], ':patient_id' => $data['patient_id']]);
        if ($transition->fetchColumn() === false) {
          throw new InvalidArgumentException('A transicao informada nao pertence ao paciente.');
        }
      }

      $appointment = null;
      if ($data['appointment_id'] > 0) {
        $appointment = self::lockAppointment($pdo, $data['appointment_id']);
        if ($appointment === null) {
          throw new RuntimeException('Agendamento nao encontrado.');
        }
        if ((int)$appointment['patient_id'] !== (int)$data['patient_id']) {
          throw new InvalidArgumentException('O agendamento nao pertence ao paciente informado.');
        }
        if (!in_array((string)$appointment['status'], ['agendado', 'aguardando', 'em_atendimento', 'atendido'], true)) {
          throw new RuntimeException('Este agendamento foi encerrado sem atendimento e nao aceita registro clinico.');
        }

        $data['encounter_date'] = substr((string)$appointment['scheduled_at'], 0, 10);
        if (trim((string)$appointment['specialty']) !== '') {
          $data['specialty'] = trim((string)$appointment['specialty']);
        }

        if ($id === null) {
          $linkedEncounter = self::findByAppointment($pdo, $data['appointment_id']);
          if ($linkedEncounter !== null) {
            $id = (int)$linkedEncounter['id'];
            $before = $linkedEncounter;
          }
        } elseif (!empty($before['appointment_id']) && (int)$before['appointment_id'] !== $data['appointment_id']) {
          throw new InvalidArgumentException('O atendimento ja esta vinculado a outro agendamento.');
        }
      }

      if ($id !== null) {
        $stmt = $pdo->prepare('
          UPDATE encounters
          SET patient_id = :patient_id,
              appointment_id = :appointment_id,
              transition_id = :transition_id,
              encounter_date = :encounter_date,
              specialty = :specialty,
              record_type = :record_type,
              schema_version = :schema_version,
              payload_json = :payload_json,
              summary = :summary,
              professional_user_id = :user_id,
              deleted_at = NULL,
              updated_at = NOW()
          WHERE id = :id
        ');
        $stmt->execute(self::saveParams($data, $actorUserId, $id));
        Audit::log($pdo, $actorUserId, 'update', 'encounters', $id, $before, $data);
        $encounterId = $id;
      } else {
        $stmt = $pdo->prepare('
          INSERT INTO encounters
            (patient_id, appointment_id, transition_id, encounter_date, specialty, record_type, schema_version, payload_json, professional_user_id, summary)
          VALUES
            (:patient_id, :appointment_id, :transition_id, :encounter_date, :specialty, :record_type, :schema_version, :payload_json, :user_id, :summary)
        ');
        $stmt->execute(self::saveParams($data, $actorUserId));
        $encounterId = (int)$pdo->lastInsertId();
        Audit::log($pdo, $actorUserId, 'create', 'encounters', $encounterId, null, $data);
      }

      if ($appointment !== null && (string)$appointment['status'] !== 'atendido') {
        $updateAppointment = $pdo->prepare("
          UPDATE patient_appointments
          SET status = 'atendido',
              completed_at = COALESCE(completed_at, NOW()),
              outcome_reason = NULL,
              outcome_notes = NULL,
              resolved_at = NOW(),
              resolved_by_user_id = :user_id,
              updated_at = NOW()
          WHERE id = :id
        ");
        $updateAppointment->execute([
          ':user_id' => $actorUserId,
          ':id' => (int)$appointment['id'],
        ]);
        if (!empty($appointment['sharing_transition_id'])) {
          $pdo->prepare("
            UPDATE transitions SET status = 'atendido', updated_at = NOW()
            WHERE id = :id AND flow_type = 'care_sharing' AND deleted_at IS NULL
          ")->execute([':id' => (int)$appointment['sharing_transition_id']]);
        }
        Audit::log($pdo, $actorUserId, 'complete', 'patient_appointments', (int)$appointment['id'], $appointment, [
          'status' => 'atendido',
          'encounter_id' => $encounterId,
        ]);
      }

      $followUpAppointment = null;
      if ($data['follow_up_at'] !== null) {
        if ($appointment === null) {
          throw new InvalidArgumentException('A consulta subsequente so pode ser criada a partir de um agendamento.');
        }
        $followUpAppointment = self::syncFollowUpAppointment(
          $pdo,
          $encounterId,
          $before,
          $appointment,
          (string)$data['follow_up_at'],
          $actorUserId
        );
      }

      $pdo->commit();
      $context = self::formContext($pdo, $encounterId);
      $context['follow_up_appointment'] = $followUpAppointment;
      return $context;
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }
  }

  private static function saveParams(array $data, int $actorUserId, ?int $id = null): array {
    $params = [
      ':patient_id' => $data['patient_id'],
      ':appointment_id' => $data['appointment_id'] > 0 ? $data['appointment_id'] : null,
      ':transition_id' => $data['transition_id'] > 0 ? $data['transition_id'] : null,
      ':encounter_date' => $data['encounter_date'],
      ':specialty' => $data['specialty'],
      ':record_type' => $data['record_type'],
      ':schema_version' => $data['schema_version'],
      ':payload_json' => $data['payload_json'],
      ':summary' => $data['summary'],
      ':user_id' => $actorUserId,
    ];
    if ($id !== null) {
      $params[':id'] = $id;
    }
    return $params;
  }

  private static function lockAppointment(PDO $pdo, int $appointmentId): ?array {
    $stmt = $pdo->prepare('
      SELECT *
      FROM patient_appointments
      WHERE id = :id AND deleted_at IS NULL
      LIMIT 1
      FOR UPDATE
    ');
    $stmt->execute([':id' => $appointmentId]);
    $row = $stmt->fetch();
    return $row ?: null;
  }

  private static function findByAppointment(PDO $pdo, int $appointmentId): ?array {
    $stmt = $pdo->prepare('
      SELECT *
      FROM encounters
      WHERE appointment_id = :appointment_id
      LIMIT 1
    ');
    $stmt->execute([':appointment_id' => $appointmentId]);
    $row = $stmt->fetch();
    return $row ?: null;
  }

  private static function syncFollowUpAppointment(
    PDO $pdo,
    int $encounterId,
    ?array $before,
    array $sourceAppointment,
    string $followUpAt,
    int $actorUserId
  ): array {
    if ($followUpAt <= (string)$sourceAppointment['scheduled_at']) {
      throw new InvalidArgumentException('A consulta subsequente deve ocorrer depois da consulta atual.');
    }

    $existingId = (int)($before['follow_up_appointment_id'] ?? 0);
    $appointmentData = [
      'patient_id' => (int)$sourceAppointment['patient_id'],
      'scheduled_at' => $followUpAt,
      'specialty' => trim((string)($sourceAppointment['specialty'] ?? '')),
      'modality' => trim((string)($sourceAppointment['modality'] ?? '')) ?: 'Presencial',
      'professional' => trim((string)($sourceAppointment['professional'] ?? '')),
      'team' => trim((string)($sourceAppointment['team'] ?? '')),
      'priority' => trim((string)($sourceAppointment['priority'] ?? '')) ?: 'nao',
      'notes' => 'Retorno definido no registro da consulta anterior.',
      'status' => 'agendado',
    ];

    if ($existingId > 0) {
      $existing = self::lockAppointment($pdo, $existingId);
      if ($existing === null || (int)$existing['patient_id'] !== $appointmentData['patient_id']) {
        throw new RuntimeException('Agendamento subsequente nao encontrado.');
      }
      if ((string)$existing['status'] !== 'agendado') {
        if ((string)$existing['scheduled_at'] === $followUpAt) {
          return $existing;
        }
        throw new RuntimeException('A consulta subsequente ja possui um desfecho e nao pode ser reagendada por este registro.');
      }

      $update = $pdo->prepare('
        UPDATE patient_appointments
        SET scheduled_at = :scheduled_at,
            specialty = :specialty,
            modality = :modality,
            professional = :professional,
            team = :team,
            priority = :priority,
            notes = :notes,
            updated_at = NOW()
        WHERE id = :id
      ');
      $update->execute([
        ':scheduled_at' => $appointmentData['scheduled_at'],
        ':specialty' => $appointmentData['specialty'] !== '' ? $appointmentData['specialty'] : null,
        ':modality' => $appointmentData['modality'],
        ':professional' => $appointmentData['professional'] !== '' ? $appointmentData['professional'] : null,
        ':team' => $appointmentData['team'] !== '' ? $appointmentData['team'] : null,
        ':priority' => $appointmentData['priority'],
        ':notes' => $appointmentData['notes'],
        ':id' => $existingId,
      ]);
      Audit::log($pdo, $actorUserId, 'update', 'patient_appointments', $existingId, $existing, $appointmentData);
      return self::lockAppointment($pdo, $existingId) ?? $appointmentData;
    }

    $insert = $pdo->prepare('
      INSERT INTO patient_appointments
        (patient_id, scheduled_at, specialty, modality, professional, team, priority, notes, status, created_by_user_id)
      VALUES
        (:patient_id, :scheduled_at, :specialty, :modality, :professional, :team, :priority, :notes, :status, :user_id)
    ');
    $insert->execute([
      ':patient_id' => $appointmentData['patient_id'],
      ':scheduled_at' => $appointmentData['scheduled_at'],
      ':specialty' => $appointmentData['specialty'] !== '' ? $appointmentData['specialty'] : null,
      ':modality' => $appointmentData['modality'],
      ':professional' => $appointmentData['professional'] !== '' ? $appointmentData['professional'] : null,
      ':team' => $appointmentData['team'] !== '' ? $appointmentData['team'] : null,
      ':priority' => $appointmentData['priority'],
      ':notes' => $appointmentData['notes'],
      ':status' => $appointmentData['status'],
      ':user_id' => $actorUserId,
    ]);
    $followUpId = (int)$pdo->lastInsertId();

    $link = $pdo->prepare('
      UPDATE encounters
      SET follow_up_appointment_id = :follow_up_appointment_id,
          updated_at = NOW()
      WHERE id = :id
    ');
    $link->execute([
      ':follow_up_appointment_id' => $followUpId,
      ':id' => $encounterId,
    ]);

    Audit::log($pdo, $actorUserId, 'create', 'patient_appointments', $followUpId, null, [
      ...$appointmentData,
      'source_encounter_id' => $encounterId,
    ]);
    return self::lockAppointment($pdo, $followUpId) ?? [
      'id' => $followUpId,
      ...$appointmentData,
    ];
  }

  private static function normalizePayloadJson(mixed $value): string|false|null {
    if ($value === null || $value === '') {
      return null;
    }
    if (is_array($value)) {
      $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      return $encoded !== false ? $encoded : false;
    }
    if (!is_string($value)) {
      return false;
    }

    json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $value : false;
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
