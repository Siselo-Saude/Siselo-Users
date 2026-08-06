<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/Audit.php';
require_once __DIR__ . '/CareContracts.php';
require_once __DIR__ . '/CareNetwork.php';

final class CareFlow {
  private const APPLICATION_TIMEZONE = 'America/Sao_Paulo';

  private const RISK_OPTIONS = [
    'alto_risco' => 'Alto Risco',
    'muito_alto_risco' => 'Muito Alto Risco',
  ];

  private const CARE_STATUS_OPTIONS = [
    'recebido' => 'Recebido da UBS',
    'em_acompanhamento' => 'Em acompanhamento',
    'transicionado' => 'Transicionado para a UBS',
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
    return array_merge(CareContracts::options(), [
      'risk_options' => self::RISK_OPTIONS,
      'care_status_options' => self::CARE_STATUS_OPTIONS,
      'appointment_status_options' => self::APPOINTMENT_STATUS_OPTIONS,
      'appointment_outcome_reasons' => array_map(
        static fn(array $reason): string => $reason['label'],
        self::APPOINTMENT_OUTCOME_REASONS
      ),
      'finalization_reasons' => self::FINALIZATION_REASONS,
      'ubs_options' => CareNetwork::ubsOptions(),
      'team_options' => CareNetwork::teamOptions(),
      'ubs_team_groups' => CareNetwork::groups(),
    ]);
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
        a.sharing_transition_id,
        a.scheduled_at,
        a.shift AS appointment_shift,
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
        a.arrived_at AS appointment_arrived_at,
        a.started_at AS appointment_started_at,
        a.completed_at AS appointment_completed_at,
        referral.id AS referral_id,
        referral.transition_date AS referral_date,
        referral.from_service AS referral_from_service,
        referral.to_service AS referral_to_service,
        referral.status AS referral_status,
        referral.origin_ubs AS referral_origin_ubs,
        referral.origin_team AS referral_origin_team,
        referral.entry_condition AS referral_entry_condition,
        referral.justification AS referral_justification,
        referral.notes AS referral_notes,
        referral.received_at AS referral_received_at,
        DATEDIFF(CURDATE(), referral.transition_date) AS referral_waiting_days
      FROM patients p
      ' . $appointmentJoin . '
      LEFT JOIN transitions referral
        ON referral.id = (
          SELECT candidate.id
          FROM transitions candidate
          WHERE candidate.patient_id = p.id
            AND candidate.deleted_at IS NULL
            AND candidate.flow_type = \'care_sharing\'
            AND candidate.status <> \'cancelado\'
          ORDER BY candidate.id DESC
          LIMIT 1
        )
      WHERE p.deleted_at IS NULL
        AND referral.id IS NOT NULL
    ';
    $params = [];

    if ($careStatus === 'finalizado') {
      $sql .= " AND p.care_status = 'finalizado'";
    } elseif ($careStatus === 'transicionado') {
      $sql .= " AND p.care_status = 'transicionado'";
    } elseif ($careStatus !== 'all') {
      $sql .= " AND COALESCE(p.care_status, 'recebido') NOT IN ('finalizado', 'transicionado')";
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

  public static function metrics(PDO $pdo): array {
    $stmt = $pdo->query("
      SELECT
        SUM(CASE WHEN s.id IS NOT NULL AND COALESCE(p.care_status, 'recebido') NOT IN ('finalizado', 'transicionado') THEN 1 ELSE 0 END) AS active_cadh,
        SUM(CASE WHEN s.id IS NOT NULL THEN 1 ELSE 0 END) AS shared_with_cadh,
        SUM(CASE WHEN s.id IS NOT NULL AND s.status IN ('recebido', 'aguardando_agendamento')
          AND NOT EXISTS (
            SELECT 1 FROM patient_appointments a
            WHERE a.sharing_transition_id = s.id AND a.deleted_at IS NULL
          ) THEN 1 ELSE 0 END) AS awaiting_schedule,
        SUM(CASE WHEN p.care_status = 'transicionado' AND EXISTS (
          SELECT 1 FROM transitions ct
          WHERE ct.patient_id = p.id AND ct.flow_type = 'care_transition' AND ct.deleted_at IS NULL
        ) THEN 1 ELSE 0 END) AS transitioned
      FROM patients p
      LEFT JOIN transitions s ON s.id = (
        SELECT t.id FROM transitions t
        WHERE t.patient_id = p.id AND t.flow_type = 'care_sharing' AND t.deleted_at IS NULL
        ORDER BY t.id DESC LIMIT 1
      )
      WHERE p.deleted_at IS NULL
    ");
    $row = $stmt->fetch() ?: [];
    return [
      'active_cadh' => (int)($row['active_cadh'] ?? 0),
      'shared_with_cadh' => (int)($row['shared_with_cadh'] ?? 0),
      'awaiting_schedule' => (int)($row['awaiting_schedule'] ?? 0),
      'transitioned' => (int)($row['transitioned'] ?? 0),
    ];
  }

  public static function alerts(PDO $pdo): array {
    $sharing = $pdo->query("
      SELECT s.id AS transition_id, s.patient_id, p.full_name, p.cpf, s.transition_date,
        DATEDIFF(CURDATE(), s.transition_date) AS days_without_appointment
      FROM transitions s
      JOIN patients p ON p.id = s.patient_id AND p.deleted_at IS NULL
      WHERE s.deleted_at IS NULL AND s.flow_type = 'care_sharing'
        AND s.status IN ('recebido', 'aguardando_agendamento')
        AND s.transition_date <= DATE_SUB(CURDATE(), INTERVAL " . CareContracts::sharingAlertDays() . " DAY)
        AND NOT EXISTS (
          SELECT 1 FROM patient_appointments a
          WHERE a.sharing_transition_id = s.id AND a.deleted_at IS NULL
        )
      ORDER BY s.transition_date ASC
    ")->fetchAll();

    $monitoring = $pdo->query("
      SELECT t.id AS transition_id, t.patient_id, p.full_name, p.cpf, t.transition_date,
        COALESCE(MAX(e.encounter_date), t.transition_date) AS last_monitoring_date,
        DATEDIFF(CURDATE(), COALESCE(MAX(e.encounter_date), t.transition_date)) AS days_without_monitoring
      FROM transitions t
      JOIN patients p ON p.id = t.patient_id AND p.deleted_at IS NULL
      LEFT JOIN encounters e ON e.transition_id = t.id AND e.record_type = 'ubs_acompanhamento' AND e.deleted_at IS NULL
      WHERE t.deleted_at IS NULL AND t.flow_type = 'care_transition'
      GROUP BY t.id, t.patient_id, p.full_name, p.cpf, t.transition_date
      HAVING days_without_monitoring >= " . CareContracts::ubsMonitoringAlertDays() . "
      ORDER BY last_monitoring_date ASC
    ")->fetchAll();

    return [
      'sharing_without_appointment' => array_map([self::class, 'serializeRow'], $sharing),
      'ubs_without_monitoring' => array_map([self::class, 'serializeRow'], $monitoring),
    ];
  }

  public static function refer(PDO $pdo, array $payload, int $actorUserId): array {
    $patientId = (int)($payload['patient_id'] ?? 0);
    $risk = trim((string)($payload['risk_classification'] ?? ''));
    $entryCondition = trim((string)($payload['entry_condition'] ?? ''));
    $justification = trim((string)($payload['justification'] ?? ''));

    if ($patientId <= 0) {
      throw new InvalidArgumentException('Selecione um paciente.');
    }
    if (!array_key_exists($risk, self::RISK_OPTIONS)) {
      throw new InvalidArgumentException('Selecione uma classificacao de risco valida.');
    }
    if (!array_key_exists($entryCondition, CareContracts::entryConditions())) {
      throw new InvalidArgumentException('Selecione uma condicao de entrada valida.');
    }
    if ($justification === '') {
      throw new InvalidArgumentException('Informe a justificativa do compartilhamento.');
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
            entry_condition = :entry_condition,
            care_status = 'recebido',
            first_cadh_date = COALESCE(first_cadh_date, CURDATE()),
            updated_at = NOW()
        WHERE id = :id AND deleted_at IS NULL
      ")->execute([
        ':risk' => $risk,
        ':entry_condition' => $entryCondition,
        ':id' => $patientId,
      ]);

      $activeReferral = $pdo->prepare("
        SELECT *
        FROM transitions
        WHERE patient_id = :patient_id
          AND deleted_at IS NULL
          AND flow_type = 'care_sharing'
          AND status IN ('recebido', 'aguardando_agendamento', 'agendado')
        ORDER BY id DESC
        LIMIT 1
      ");
      $activeReferral->execute([':patient_id' => $patientId]);
      $transition = $activeReferral->fetch();

      if (!$transition) {
        $fromService = trim((string)($before['ubs_ref'] ?? ''));
        $fromService = $fromService !== '' ? $fromService : 'UBS';
        $fromTeam = trim((string)($before['team_ref'] ?? ''));
        $notes = 'Compartilhamento da UBS para o CADH. Classificacao: ' . self::RISK_OPTIONS[$risk] . '.';
        $insert = $pdo->prepare("
          INSERT INTO transitions
            (patient_id, flow_type, transition_date, from_service, to_service, origin_ubs,
             origin_team, entry_condition, justification, status, notes, created_by_user_id)
          VALUES
            (:patient_id, 'care_sharing', CURDATE(), :from_service, 'CADH', :origin_ubs,
             :origin_team, :entry_condition, :justification, 'aguardando_agendamento', :notes, :user_id)
        ");
        $insert->execute([
          ':patient_id' => $patientId,
          ':from_service' => $fromService,
          ':origin_ubs' => $fromService,
          ':origin_team' => $fromTeam !== '' ? $fromTeam : null,
          ':entry_condition' => $entryCondition,
          ':justification' => $justification,
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
        'entry_condition' => $entryCondition,
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
    $sharing = self::findCadhReferral($pdo, $patientId);
    if ($sharing === null) {
      throw new RuntimeException('Compartilhamento UBS-CADH nao encontrado.');
    }
    if (empty($sharing['received_at'])) {
      throw new RuntimeException('Confirme o recebimento do compartilhamento antes de agendar.');
    }

    $pdo->beginTransaction();
    try {
      $insert = $pdo->prepare('
        INSERT INTO patient_appointments
          (patient_id, sharing_transition_id, scheduled_at, shift, specialty, modality, professional, team,
           priority, notes, status, created_by_user_id)
        VALUES
          (:patient_id, :sharing_transition_id, :scheduled_at, :shift, :specialty, :modality, :professional,
           :team, :priority, :notes, :status, :user_id)
      ');
      $insert->execute([
        ':patient_id' => $patientId,
        ':sharing_transition_id' => (int)$sharing['id'],
        ':scheduled_at' => $scheduledAt,
        ':shift' => self::shiftFor($scheduledAt),
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

      $pdo->prepare("
        UPDATE transitions
        SET status = 'agendado', updated_at = NOW()
        WHERE id = :id AND flow_type = 'care_sharing' AND deleted_at IS NULL
      ")->execute([':id' => (int)$sharing['id']]);

      Audit::log($pdo, $actorUserId, 'create', 'patient_appointments', $appointmentId, null, [
        'patient_id' => $patientId,
        'sharing_transition_id' => (int)$sharing['id'],
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
        SET status = CASE WHEN status = 'aguardando_agendamento' THEN 'recebido' ELSE status END,
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

    $allowedTransitions = [
      'agendado' => ['aguardando', 'pendente', 'ausente'],
      'aguardando' => ['em_atendimento', 'pendente', 'ausente'],
      'em_atendimento' => ['atendido', 'pendente'],
      'pendente' => ['agendado', 'aguardando'],
      'ausente' => ['agendado'],
      'atendido' => [],
    ];
    $currentStatus = (string)($before['status'] ?? '');
    if (!in_array($status, $allowedTransitions[$currentStatus] ?? [], true)) {
      throw new InvalidArgumentException('Mudança de situação não permitida para este agendamento.');
    }

    $stmt = $pdo->prepare('
      UPDATE patient_appointments
      SET status = :status,
          arrived_at = CASE WHEN :status_arrived = \'aguardando\' THEN COALESCE(arrived_at, NOW()) ELSE arrived_at END,
          started_at = CASE WHEN :status_started = \'em_atendimento\' THEN COALESCE(started_at, NOW()) ELSE started_at END,
          completed_at = CASE WHEN :status_completed = \'atendido\' THEN COALESCE(completed_at, NOW()) ELSE completed_at END,
          resolved_at = CASE WHEN :status_resolved IN (\'atendido\', \'ausente\') THEN COALESCE(resolved_at, NOW()) ELSE resolved_at END,
          resolved_by_user_id = CASE WHEN :status_user IN (\'atendido\', \'ausente\') THEN :user_id ELSE resolved_by_user_id END,
          updated_at = NOW()
      WHERE id = :id AND deleted_at IS NULL
    ');
    $stmt->execute([
      ':status' => $status,
      ':status_arrived' => $status,
      ':status_started' => $status,
      ':status_completed' => $status,
      ':status_resolved' => $status,
      ':status_user' => $status,
      ':user_id' => $actorUserId,
      ':id' => $appointmentId,
    ]);
    if (!empty($before['sharing_transition_id']) && in_array($status, ['atendido', 'ausente'], true)) {
      $pdo->prepare("
        UPDATE transitions SET status = :status, updated_at = NOW()
        WHERE id = :id AND flow_type = 'care_sharing' AND deleted_at IS NULL
      ")->execute([':status' => $status, ':id' => (int)$before['sharing_transition_id']]);
    }
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
      if (!in_array((string)$before['status'], ['agendado', 'aguardando', 'em_atendimento'], true)) {
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
      if (!empty($before['sharing_transition_id']) && $status === 'ausente') {
        $pdo->prepare("
          UPDATE transitions SET status = 'ausente', updated_at = NOW()
          WHERE id = :id AND flow_type = 'care_sharing' AND deleted_at IS NULL
        ")->execute([':id' => (int)$before['sharing_transition_id']]);
      }

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
        AND COALESCE(care_status, 'recebido') NOT IN ('finalizado', 'transicionado')
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
        AND flow_type = 'care_sharing'
        AND status <> 'cancelado'
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
      'sharing_transition_id',
      'transition_id',
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
    $entryCondition = (string)($row['entry_condition'] ?? $row['referral_entry_condition'] ?? '');
    $row['entry_condition_label'] = CareContracts::entryConditions()[$entryCondition] ?? '';
    if (array_key_exists('referral_waiting_days', $row)) {
      $row['referral_waiting_days'] = (int)$row['referral_waiting_days'];
      $row['sharing_alert'] = empty($row['appointment_id'])
        && $row['referral_waiting_days'] >= CareContracts::sharingAlertDays();
    }
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

  private static function shiftFor(string $scheduledAt): string {
    $hour = (int)substr($scheduledAt, 11, 2);
    if ($hour < 12) {
      return 'manha';
    }
    if ($hour < 18) {
      return 'tarde';
    }
    return 'noite';
  }
}
