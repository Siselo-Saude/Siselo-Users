<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/Audit.php';

final class CareFlow {
  private const RISK_OPTIONS = [
    'alto_risco' => 'Alto Risco',
    'muito_alto_risco' => 'Muito Alto Risco',
  ];

  private const CARE_STATUS_OPTIONS = [
    'recebido' => 'Recebido da UBS',
    'em_acompanhamento' => 'Em acompanhamento',
    'finalizado' => 'Finalizado',
  ];

  private const APPOINTMENT_STATUS_OPTIONS = [
    'agendado' => 'Agendado',
    'aguardando' => 'Aguardando atendimento',
    'em_atendimento' => 'Em atendimento',
    'atendido' => 'Atendido',
    'pendente' => 'Pendente',
    'ausente' => 'Ausente',
  ];

  private const FINALIZATION_REASONS = [
    'obito' => 'Óbito',
    'ausencia' => 'Ausência',
    'tratamento_finalizado' => 'Tratamento finalizado',
    'outro' => 'Outro',
  ];

  public static function options(): array {
    return [
      'risk_options' => self::RISK_OPTIONS,
      'care_status_options' => self::CARE_STATUS_OPTIONS,
      'appointment_status_options' => self::APPOINTMENT_STATUS_OPTIONS,
      'finalization_reasons' => self::FINALIZATION_REASONS,
    ];
  }

  public static function list(PDO $pdo, array $filters = []): array {
    $query = trim((string)($filters['q'] ?? ''));
    $risk = trim((string)($filters['risk'] ?? ''));
    $careStatus = trim((string)($filters['care_status'] ?? 'active'));

    $sql = '
      SELECT
        p.*,
        a.id AS appointment_id,
        a.scheduled_at,
        a.specialty AS appointment_specialty,
        a.notes AS appointment_notes,
        a.status AS appointment_status
      FROM patients p
      LEFT JOIN patient_appointments a
        ON a.id = (
          SELECT pa.id
          FROM patient_appointments pa
          WHERE pa.patient_id = p.id
            AND pa.deleted_at IS NULL
          ORDER BY pa.scheduled_at DESC, pa.id DESC
          LIMIT 1
        )
      WHERE p.deleted_at IS NULL
        AND EXISTS (
          SELECT 1
          FROM transitions referral
          WHERE referral.patient_id = p.id
            AND referral.deleted_at IS NULL
            AND UPPER(TRIM(referral.to_service)) LIKE \'CADH%\'
            AND referral.status <> \'cancelada\'
        )
    ';
    $params = [];

    if ($careStatus === 'finalizado') {
      $sql .= " AND p.care_status = 'finalizado'";
    } elseif ($careStatus !== 'all') {
      $sql .= " AND COALESCE(p.care_status, 'recebido') <> 'finalizado'";
    }

    if ($query !== '') {
      $sql .= ' AND (p.full_name LIKE :q_name OR p.cpf LIKE :q_cpf)';
      $params[':q_name'] = '%' . $query . '%';
      $params[':q_cpf'] = '%' . $query . '%';
    }

    if ($risk !== '') {
      if (!array_key_exists($risk, self::RISK_OPTIONS)) {
        return [];
      }
      $sql .= ' AND p.risk_classification = :risk';
      $params[':risk'] = $risk;
    }

    $sql .= "
      ORDER BY
        CASE p.risk_classification
          WHEN 'muito_alto_risco' THEN 0
          WHEN 'alto_risco' THEN 1
          ELSE 2
        END,
        p.full_name ASC
      LIMIT 500
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map([self::class, 'serializeRow'], $stmt->fetchAll());
  }

  public static function refer(PDO $pdo, array $payload, int $actorUserId): array {
    $patientId = (int)($payload['patient_id'] ?? 0);
    $risk = trim((string)($payload['risk_classification'] ?? ''));

    if ($patientId <= 0) {
      throw new InvalidArgumentException('Selecione um paciente.');
    }
    if (!array_key_exists($risk, self::RISK_OPTIONS)) {
      throw new InvalidArgumentException('Selecione uma classificacao de risco valida.');
    }

    $before = self::findPatient($pdo, $patientId);
    if ($before === null || (string)($before['care_status'] ?? '') === 'finalizado') {
      throw new RuntimeException('Paciente nao encontrado ou ja finalizado.');
    }

    $pdo->beginTransaction();
    try {
      $pdo->prepare("
        UPDATE patients
        SET risk_classification = :risk,
            care_status = 'recebido',
            first_cadh_date = COALESCE(first_cadh_date, CURDATE()),
            updated_at = NOW()
        WHERE id = :id AND deleted_at IS NULL
      ")->execute([
        ':risk' => $risk,
        ':id' => $patientId,
      ]);

      $activeReferral = $pdo->prepare("
        SELECT *
        FROM transitions
        WHERE patient_id = :patient_id
          AND deleted_at IS NULL
          AND UPPER(TRIM(to_service)) LIKE 'CADH%'
          AND status IN ('pendente', 'em_andamento')
        ORDER BY id DESC
        LIMIT 1
      ");
      $activeReferral->execute([':patient_id' => $patientId]);
      $transition = $activeReferral->fetch();

      if (!$transition) {
        $fromService = trim((string)($before['ubs_ref'] ?? ''));
        $fromService = $fromService !== '' ? $fromService : 'UBS';
        $notes = 'Encaminhamento da UBS para o CADH. Classificacao: ' . self::RISK_OPTIONS[$risk] . '.';
        $insert = $pdo->prepare("
          INSERT INTO transitions
            (patient_id, transition_date, from_service, to_service, status, notes, created_by_user_id)
          VALUES
            (:patient_id, CURDATE(), :from_service, 'CADH', 'pendente', :notes, :user_id)
        ");
        $insert->execute([
          ':patient_id' => $patientId,
          ':from_service' => $fromService,
          ':notes' => $notes,
          ':user_id' => $actorUserId,
        ]);
        $transitionId = (int)$pdo->lastInsertId();
        $transitionQuery = $pdo->prepare('SELECT * FROM transitions WHERE id = :id LIMIT 1');
        $transitionQuery->execute([':id' => $transitionId]);
        $transition = $transitionQuery->fetch() ?: [];
        Audit::log($pdo, $actorUserId, 'create', 'transitions', $transitionId, null, $transition);
      }

      $patient = self::findPatient($pdo, $patientId) ?? [];
      Audit::log($pdo, $actorUserId, 'refer', 'patients', $patientId, $before, [
        'risk_classification' => $risk,
        'care_status' => 'recebido',
      ]);
      $pdo->commit();

      if (isset($transition['id'])) {
        $transition['id'] = (int)$transition['id'];
      }
      if (isset($transition['patient_id'])) {
        $transition['patient_id'] = (int)$transition['patient_id'];
      }

      return [
        'patient' => $patient,
        'transition' => $transition,
      ];
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }
  }

  public static function schedule(PDO $pdo, array $payload, int $actorUserId): array {
    $patientId = (int)($payload['patient_id'] ?? 0);
    $scheduledAt = self::normalizeDateTime((string)($payload['scheduled_at'] ?? ''));
    $specialty = self::singleLine((string)($payload['specialty'] ?? ''));
    $notes = trim((string)($payload['notes'] ?? ''));

    if ($patientId <= 0) {
      throw new InvalidArgumentException('Selecione um paciente.');
    }
    if ($scheduledAt === null) {
      throw new InvalidArgumentException('Informe uma data e hora válidas.');
    }
    self::assertActivePatient($pdo, $patientId);

    $pdo->beginTransaction();
    try {
      $insert = $pdo->prepare('
        INSERT INTO patient_appointments
          (patient_id, scheduled_at, specialty, notes, status, created_by_user_id)
        VALUES
          (:patient_id, :scheduled_at, :specialty, :notes, :status, :user_id)
      ');
      $insert->execute([
        ':patient_id' => $patientId,
        ':scheduled_at' => $scheduledAt,
        ':specialty' => $specialty !== '' ? $specialty : null,
        ':notes' => $notes !== '' ? $notes : null,
        ':status' => 'agendado',
        ':user_id' => $actorUserId,
      ]);
      $appointmentId = (int)$pdo->lastInsertId();

      $pdo->prepare("
        UPDATE patients
        SET care_status = 'em_acompanhamento', updated_at = NOW()
        WHERE id = :id AND deleted_at IS NULL
      ")->execute([':id' => $patientId]);

      Audit::log($pdo, $actorUserId, 'create', 'patient_appointments', $appointmentId, null, [
        'patient_id' => $patientId,
        'scheduled_at' => $scheduledAt,
        'status' => 'agendado',
      ]);
      $pdo->commit();
      return self::findAppointment($pdo, $appointmentId) ?? [];
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }
  }

  public static function move(PDO $pdo, array $payload, int $actorUserId): array {
    $appointmentId = (int)($payload['appointment_id'] ?? 0);
    $status = trim((string)($payload['status'] ?? ''));
    if ($appointmentId <= 0 || !array_key_exists($status, self::APPOINTMENT_STATUS_OPTIONS)) {
      throw new InvalidArgumentException('Agendamento ou situação inválida.');
    }

    $before = self::findAppointment($pdo, $appointmentId);
    if ($before === null) {
      throw new RuntimeException('Agendamento não encontrado.');
    }

    $stmt = $pdo->prepare('
      UPDATE patient_appointments
      SET status = :status, updated_at = NOW()
      WHERE id = :id AND deleted_at IS NULL
    ');
    $stmt->execute([':status' => $status, ':id' => $appointmentId]);
    Audit::log($pdo, $actorUserId, 'update', 'patient_appointments', $appointmentId, $before, ['status' => $status]);

    return self::findAppointment($pdo, $appointmentId) ?? [];
  }

  public static function finalize(PDO $pdo, array $payload, int $actorUserId): array {
    $patientId = (int)($payload['patient_id'] ?? 0);
    $reason = trim((string)($payload['reason'] ?? ''));
    $notes = trim((string)($payload['notes'] ?? ''));
    if ($patientId <= 0 || !array_key_exists($reason, self::FINALIZATION_REASONS)) {
      throw new InvalidArgumentException('Paciente ou motivo de finalização inválido.');
    }
    if ($reason === 'outro' && $notes === '') {
      throw new InvalidArgumentException('Descreva o motivo da finalização.');
    }

    $before = self::findPatient($pdo, $patientId);
    if ($before === null) {
      throw new RuntimeException('Paciente não encontrado.');
    }

    $stmt = $pdo->prepare("
      UPDATE patients
      SET care_status = 'finalizado',
          finalization_reason = :reason,
          finalization_notes = :notes,
          finalized_at = NOW(),
          finalized_by_user_id = :user_id,
          updated_at = NOW()
      WHERE id = :id AND deleted_at IS NULL
    ");
    $stmt->execute([
      ':reason' => $reason,
      ':notes' => $notes !== '' ? $notes : null,
      ':user_id' => $actorUserId,
      ':id' => $patientId,
    ]);
    Audit::log($pdo, $actorUserId, 'finalize', 'patients', $patientId, $before, [
      'care_status' => 'finalizado',
      'finalization_reason' => $reason,
    ]);

    return self::findPatient($pdo, $patientId) ?? [];
  }

  public static function reopen(PDO $pdo, int $patientId, int $actorUserId): array {
    $before = self::findPatient($pdo, $patientId);
    if ($before === null) {
      throw new RuntimeException('Paciente não encontrado.');
    }

    $stmt = $pdo->prepare("
      UPDATE patients
      SET care_status = 'recebido',
          finalization_reason = NULL,
          finalization_notes = NULL,
          finalized_at = NULL,
          finalized_by_user_id = NULL,
          updated_at = NOW()
      WHERE id = :id AND deleted_at IS NULL
    ");
    $stmt->execute([':id' => $patientId]);
    Audit::log($pdo, $actorUserId, 'reopen', 'patients', $patientId, $before, ['care_status' => 'recebido']);

    return self::findPatient($pdo, $patientId) ?? [];
  }

  private static function assertActivePatient(PDO $pdo, int $patientId): void {
    $stmt = $pdo->prepare("
      SELECT id FROM patients
      WHERE id = :id AND deleted_at IS NULL
        AND COALESCE(care_status, 'recebido') <> 'finalizado'
      LIMIT 1
    ");
    $stmt->execute([':id' => $patientId]);
    if ($stmt->fetchColumn() === false) {
      throw new RuntimeException('Paciente não encontrado ou já finalizado.');
    }
  }

  private static function findPatient(PDO $pdo, int $patientId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM patients WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $patientId]);
    $row = $stmt->fetch();
    return $row ? self::serializeRow($row) : null;
  }

  private static function findAppointment(PDO $pdo, int $appointmentId): ?array {
    $stmt = $pdo->prepare('
      SELECT a.*, p.full_name, p.cpf, p.risk_classification, p.care_status
      FROM patient_appointments a
      JOIN patients p ON p.id = a.patient_id
      WHERE a.id = :id AND a.deleted_at IS NULL
      LIMIT 1
    ');
    $stmt->execute([':id' => $appointmentId]);
    $row = $stmt->fetch();
    return $row ? self::serializeRow($row) : null;
  }

  private static function serializeRow(array $row): array {
    foreach (['id', 'patient_id', 'appointment_id', 'finalized_by_user_id'] as $field) {
      if (array_key_exists($field, $row) && $row[$field] !== null) {
        $row[$field] = (int)$row[$field];
      }
    }

    $risk = (string)($row['risk_classification'] ?? 'alto_risco');
    $careStatus = (string)($row['care_status'] ?? 'recebido');
    $appointmentStatus = (string)($row['appointment_status'] ?? $row['status'] ?? '');
    $reason = (string)($row['finalization_reason'] ?? '');
    $row['risk_label'] = self::RISK_OPTIONS[$risk] ?? 'Alto Risco';
    $row['care_status_label'] = self::CARE_STATUS_OPTIONS[$careStatus] ?? 'Recebido da UBS';
    $row['appointment_status_label'] = self::APPOINTMENT_STATUS_OPTIONS[$appointmentStatus] ?? '';
    $row['finalization_reason_label'] = self::FINALIZATION_REASONS[$reason] ?? '';
    unset($row['health_insurance'], $row['blood_type']);
    return $row;
  }

  private static function normalizeDateTime(string $value): ?string {
    $value = trim(str_replace('T', ' ', $value));
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
      $value .= ':00';
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $value : null;
  }

  private static function singleLine(string $value): string {
    return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
  }
}
