<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/Audit.php';

final class CareFlow {
  private const APPLICATION_TIMEZONE = 'America/Sao_Paulo';

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

  private const APPOINTMENT_OUTCOME_REASONS = [
    'patient_absent' => [
      'label' => 'Ausência do paciente',
      'status' => 'ausente',
    ],
    'professional_absent' => [
      'label' => 'Ausência do profissional',
      'status' => 'pendente',
    ],
    'internal_cancellation' => [
      'label' => 'Cancelamento interno',
      'status' => 'pendente',
    ],
    'unit_impediment' => [
      'label' => 'Impedimento da unidade',
      'status' => 'pendente',
    ],
    'other' => [
      'label' => 'Outro impedimento',
      'status' => 'pendente',
    ],
  ];

  private const SYSTEM_APPOINTMENT_OUTCOME_LABELS = [
    'overdue' => 'Horário da consulta expirado',
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
      'appointment_outcome_reasons' => array_map(
        static fn(array $reason): string => $reason['label'],
        self::APPOINTMENT_OUTCOME_REASONS
      ),
      'finalization_reasons' => self::FINALIZATION_REASONS,
    ];
  }

  public static function list(PDO $pdo, array $filters = []): array {
    $query = trim((string)($filters['q'] ?? ''));
    $risk = trim((string)($filters['risk'] ?? ''));
    $careStatus = trim((string)($filters['care_status'] ?? 'active'));
    $appointmentScope = trim((string)($filters['appointment_scope'] ?? 'latest'));
    if ($appointmentScope === 'all') {
      self::expireOverdueAppointments($pdo);
    }
    $appointmentJoin = $appointmentScope === 'all'
      ? '
        LEFT JOIN patient_appointments a
          ON a.patient_id = p.id
         AND a.deleted_at IS NULL
      '
      : '
        LEFT JOIN patient_appointments a
          ON a.id = (
            SELECT pa.id
            FROM patient_appointments pa
            WHERE pa.patient_id = p.id
              AND pa.deleted_at IS NULL
            ORDER BY pa.scheduled_at DESC, pa.id DESC
            LIMIT 1
          )
      ';

    $sql = '
      SELECT
        p.*,
        a.id AS appointment_id,
        a.scheduled_at,
        a.specialty AS appointment_specialty,
        a.modality AS appointment_modality,
        a.professional AS appointment_professional,
        a.team AS appointment_team,
        a.priority AS appointment_priority,
        a.notes AS appointment_notes,
        a.status AS appointment_status,
        a.outcome_reason AS appointment_outcome_reason,
        a.outcome_notes AS appointment_outcome_notes,
        a.resolved_at AS appointment_resolved_at,
        a.resolved_by_user_id AS appointment_resolved_by_user_id,
        referral.id AS referral_id,
        referral.transition_date AS referral_date,
        referral.from_service AS referral_from_service,
        referral.to_service AS referral_to_service,
        referral.status AS referral_status,
        referral.notes AS referral_notes,
        referral.received_at AS referral_received_at
      FROM patients p
      ' . $appointmentJoin . '
      LEFT JOIN transitions referral
        ON referral.id = (
          SELECT candidate.id
          FROM transitions candidate
          WHERE candidate.patient_id = p.id
            AND candidate.deleted_at IS NULL
            AND UPPER(TRIM(candidate.to_service)) LIKE \'CADH%\'
            AND candidate.status <> \'cancelada\'
          ORDER BY candidate.id DESC
          LIMIT 1
        )
      WHERE p.deleted_at IS NULL
        AND referral.id IS NOT NULL
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

    $sql .= $appointmentScope === 'all'
      ? "
        ORDER BY
          CASE p.risk_classification
            WHEN 'muito_alto_risco' THEN 0
            WHEN 'alto_risco' THEN 1
            ELSE 2
          END,
          COALESCE(a.scheduled_at, '9999-12-31 23:59:59') ASC,
          p.full_name ASC,
          a.id ASC
        LIMIT 500
      "
      : "
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
    $modality = self::singleLine((string)($payload['modality'] ?? 'Presencial'));
    $professional = self::singleLine((string)($payload['professional'] ?? ''));
    $team = self::singleLine((string)($payload['team'] ?? ''));
    $priority = trim((string)($payload['priority'] ?? 'nao'));
    $notes = trim((string)($payload['notes'] ?? ''));

    if ($patientId <= 0) {
      throw new InvalidArgumentException('Selecione um paciente.');
    }
    if ($scheduledAt === null) {
      throw new InvalidArgumentException('Informe uma data e hora válidas.');
    }
    if (!in_array($modality, ['Presencial', 'Teleatendimento'], true)) {
      throw new InvalidArgumentException('Modalidade de agendamento inválida.');
    }
    if (!in_array($priority, ['nao', 'sim'], true)) {
      throw new InvalidArgumentException('Prioridade de agendamento inválida.');
    }
    self::assertActivePatient($pdo, $patientId);

    $pdo->beginTransaction();
    try {
      $insert = $pdo->prepare('
        INSERT INTO patient_appointments
          (patient_id, scheduled_at, specialty, modality, professional, team, priority, notes, status, created_by_user_id)
        VALUES
          (:patient_id, :scheduled_at, :specialty, :modality, :professional, :team, :priority, :notes, :status, :user_id)
      ');
      $insert->execute([
        ':patient_id' => $patientId,
        ':scheduled_at' => $scheduledAt,
        ':specialty' => $specialty !== '' ? $specialty : null,
        ':modality' => $modality,
        ':professional' => $professional !== '' ? $professional : null,
        ':team' => $team !== '' ? $team : null,
        ':priority' => $priority,
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
        'specialty' => $specialty,
        'modality' => $modality,
        'professional' => $professional,
        'team' => $team,
        'priority' => $priority,
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

  public static function confirmReceipt(PDO $pdo, array $payload, int $actorUserId): array {
    $patientId = (int)($payload['patient_id'] ?? 0);
    if ($patientId <= 0) {
      throw new InvalidArgumentException('Selecione um encaminhamento.');
    }

    $before = self::findCadhReferral($pdo, $patientId);
    if ($before === null) {
      throw new RuntimeException('Encaminhamento para o CADH não encontrado.');
    }
    if (!empty($before['received_at'])) {
      return self::serializeRow($before);
    }

    $pdo->beginTransaction();
    try {
      $stmt = $pdo->prepare("
        UPDATE transitions
        SET status = CASE WHEN status = 'pendente' THEN 'em_andamento' ELSE status END,
            received_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
          AND deleted_at IS NULL
          AND received_at IS NULL
      ");
      $stmt->execute([':id' => (int)$before['id']]);

      $referral = self::findCadhReferral($pdo, $patientId);
      if ($referral === null) {
        throw new RuntimeException('Encaminhamento para o CADH não encontrado.');
      }

      if ($stmt->rowCount() > 0) {
        Audit::log(
          $pdo,
          $actorUserId,
          'receive',
          'transitions',
          (int)$before['id'],
          $before,
          $referral
        );
      }

      $pdo->commit();
      return self::serializeRow($referral);
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

  public static function appointment(PDO $pdo, int $appointmentId): ?array {
    if ($appointmentId <= 0) {
      return null;
    }

    return self::findAppointment($pdo, $appointmentId);
  }

  public static function markNotPerformed(PDO $pdo, array $payload, int $actorUserId): array {
    $appointmentId = (int)($payload['appointment_id'] ?? 0);
    $reason = trim((string)($payload['reason'] ?? ''));
    $notes = trim((string)($payload['notes'] ?? ''));

    if ($appointmentId <= 0 || !array_key_exists($reason, self::APPOINTMENT_OUTCOME_REASONS)) {
      throw new InvalidArgumentException('Agendamento ou motivo inválido.');
    }
    if ($reason === 'other' && $notes === '') {
      throw new InvalidArgumentException('Descreva o impedimento ocorrido.');
    }

    $pdo->beginTransaction();
    try {
      $lock = $pdo->prepare('
        SELECT a.*, p.full_name, p.cpf, p.risk_classification, p.care_status
        FROM patient_appointments a
        JOIN patients p ON p.id = a.patient_id
        WHERE a.id = :id
          AND a.deleted_at IS NULL
          AND p.deleted_at IS NULL
        LIMIT 1
        FOR UPDATE
      ');
      $lock->execute([':id' => $appointmentId]);
      $before = $lock->fetch();
      if (!$before) {
        throw new RuntimeException('Agendamento não encontrado.');
      }
      if ((string)$before['status'] !== 'agendado') {
        throw new RuntimeException('Este agendamento já possui um desfecho registrado.');
      }

      $status = self::APPOINTMENT_OUTCOME_REASONS[$reason]['status'];
      $update = $pdo->prepare('
        UPDATE patient_appointments
        SET status = :status,
            outcome_reason = :reason,
            outcome_notes = :notes,
            resolved_at = NOW(),
            resolved_by_user_id = :user_id,
            updated_at = NOW()
        WHERE id = :id
      ');
      $update->execute([
        ':status' => $status,
        ':reason' => $reason,
        ':notes' => $notes !== '' ? $notes : null,
        ':user_id' => $actorUserId,
        ':id' => $appointmentId,
      ]);

      Audit::log($pdo, $actorUserId, 'resolve', 'patient_appointments', $appointmentId, $before, [
        'status' => $status,
        'outcome_reason' => $reason,
        'outcome_notes' => $notes !== '' ? $notes : null,
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

  private static function findCadhReferral(PDO $pdo, int $patientId): ?array {
    $stmt = $pdo->prepare("
      SELECT *
      FROM transitions
      WHERE patient_id = :patient_id
        AND deleted_at IS NULL
        AND UPPER(TRIM(to_service)) LIKE 'CADH%'
        AND status <> 'cancelada'
      ORDER BY id DESC
      LIMIT 1
    ");
    $stmt->execute([':patient_id' => $patientId]);
    $row = $stmt->fetch();
    return $row ?: null;
  }

  private static function findPatient(PDO $pdo, int $patientId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM patients WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $patientId]);
    $row = $stmt->fetch();
    return $row ? self::serializeRow($row) : null;
  }

  private static function findAppointment(PDO $pdo, int $appointmentId): ?array {
    $stmt = $pdo->prepare('
      SELECT
        a.*,
        p.full_name,
        p.cpf,
        p.ubs_ref,
        p.team_ref,
        p.risk_classification,
        p.care_status,
        resolver.name AS resolved_by_name
      FROM patient_appointments a
      JOIN patients p ON p.id = a.patient_id
      LEFT JOIN users resolver ON resolver.id = a.resolved_by_user_id
      WHERE a.id = :id AND a.deleted_at IS NULL
      LIMIT 1
    ');
    $stmt->execute([':id' => $appointmentId]);
    $row = $stmt->fetch();
    return $row ? self::serializeRow($row) : null;
  }

  private static function expireOverdueAppointments(PDO $pdo): void {
    $now = (new DateTimeImmutable('now', new DateTimeZone(self::APPLICATION_TIMEZONE)))
      ->format('Y-m-d H:i:s');
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
      $pdo->beginTransaction();
    }

    try {
      $select = $pdo->prepare("
        SELECT a.*
        FROM patient_appointments a
        WHERE a.deleted_at IS NULL
          AND a.status IN ('agendado', 'aguardando', 'em_atendimento')
          AND a.scheduled_at < :current_time
          AND NOT EXISTS (
            SELECT 1
            FROM encounters e
            WHERE e.appointment_id = a.id
              AND e.deleted_at IS NULL
          )
        FOR UPDATE
      ");
      $select->execute([':current_time' => $now]);
      $appointments = $select->fetchAll();

      if ($appointments) {
        $update = $pdo->prepare("
          UPDATE patient_appointments
          SET status = 'pendente',
              outcome_reason = 'overdue',
              outcome_notes = COALESCE(
                NULLIF(TRIM(outcome_notes), ''),
                'Consulta não concluída até a data e o horário agendados.'
              ),
              resolved_at = :resolved_at,
              resolved_by_user_id = NULL,
              updated_at = :updated_at
          WHERE id = :id
            AND deleted_at IS NULL
            AND status IN ('agendado', 'aguardando', 'em_atendimento')
        ");

        foreach ($appointments as $appointment) {
          $appointmentId = (int)$appointment['id'];
          $update->execute([
            ':resolved_at' => $now,
            ':updated_at' => $now,
            ':id' => $appointmentId,
          ]);
          if ($update->rowCount() > 0) {
            Audit::log($pdo, null, 'expire', 'patient_appointments', $appointmentId, $appointment, [
              'status' => 'pendente',
              'outcome_reason' => 'overdue',
              'resolved_at' => $now,
            ]);
          }
        }
      }

      if ($ownsTransaction) {
        $pdo->commit();
      }
    } catch (Throwable $error) {
      if ($ownsTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }
  }

  private static function serializeRow(array $row): array {
    foreach ([
      'id',
      'patient_id',
      'appointment_id',
      'referral_id',
      'finalized_by_user_id',
      'resolved_by_user_id',
      'appointment_resolved_by_user_id',
    ] as $field) {
      if (array_key_exists($field, $row) && $row[$field] !== null) {
        $row[$field] = (int)$row[$field];
      }
    }

    $risk = (string)($row['risk_classification'] ?? 'alto_risco');
    $careStatus = (string)($row['care_status'] ?? 'recebido');
    $appointmentStatus = (string)($row['appointment_status'] ?? $row['status'] ?? '');
    $appointmentOutcome = (string)($row['appointment_outcome_reason'] ?? $row['outcome_reason'] ?? '');
    $reason = (string)($row['finalization_reason'] ?? '');
    $row['risk_label'] = self::RISK_OPTIONS[$risk] ?? 'Alto Risco';
    $row['care_status_label'] = self::CARE_STATUS_OPTIONS[$careStatus] ?? 'Recebido da UBS';
    $row['appointment_status_label'] = self::APPOINTMENT_STATUS_OPTIONS[$appointmentStatus] ?? '';
    $row['appointment_outcome_reason_label'] = self::APPOINTMENT_OUTCOME_REASONS[$appointmentOutcome]['label']
      ?? self::SYSTEM_APPOINTMENT_OUTCOME_LABELS[$appointmentOutcome]
      ?? '';
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
