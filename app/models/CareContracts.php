<?php
declare(strict_types=1);

final class CareContracts {
  public const VERSION = '2026-08-06';
  public const RESPONSIBLE_REQUIRED_UNDER_AGE = 18;
  public const SHARING_ALERT_DAYS = 7;
  public const UBS_MONITORING_ALERT_DAYS = 90;

  private const ENTRY_CONDITIONS = [
    'hipertensao' => 'Hipertensao',
    'diabetes' => 'Diabetes',
    'hipertensao_diabetes' => 'Hipertensao + Diabetes',
    'diabetes_infantojuvenil' => 'Diabetes infantojuvenil',
  ];

  private const SHARING_STATUSES = [
    'recebido' => 'Recebido',
    'aguardando_agendamento' => 'Aguardando agendamento',
    'agendado' => 'Agendado',
    'atendido' => 'Atendido',
    'ausente' => 'Ausente',
    'cancelado' => 'Cancelado',
  ];

  private const APPOINTMENT_STATUSES = [
    'agendado' => 'Agendado',
    'aguardando' => 'Aguardando atendimento',
    'em_atendimento' => 'Em atendimento',
    'atendido' => 'Atendido',
    'pendente' => 'Pendente',
    'ausente' => 'Ausente',
  ];

  private const CARE_PLAN_STATES = [
    'em_elaboracao' => 'Em elaboracao',
    'aguardando_reuniao' => 'Aguardando reuniao',
    'pactuado' => 'Pactuado',
    'transicionado' => 'Transicionado',
  ];

  public static function entryConditions(): array {
    return self::ENTRY_CONDITIONS;
  }

  public static function sharingStatuses(): array {
    return self::SHARING_STATUSES;
  }

  public static function appointmentStatuses(): array {
    return self::APPOINTMENT_STATUSES;
  }

  public static function carePlanStates(): array {
    return self::CARE_PLAN_STATES;
  }

  public static function sharingAlertDays(): int {
    return self::positiveEnvironmentInt('SHARING_ALERT_DAYS', self::SHARING_ALERT_DAYS);
  }

  public static function ubsMonitoringAlertDays(): int {
    return self::positiveEnvironmentInt('UBS_MONITORING_ALERT_DAYS', self::UBS_MONITORING_ALERT_DAYS);
  }

  public static function options(): array {
    return [
      'contract_version' => self::VERSION,
      'responsible_required_under_age' => self::RESPONSIBLE_REQUIRED_UNDER_AGE,
      'entry_condition_options' => self::ENTRY_CONDITIONS,
      'sharing_status_options' => self::SHARING_STATUSES,
      'appointment_status_options' => self::APPOINTMENT_STATUSES,
      'care_plan_state_options' => self::CARE_PLAN_STATES,
      'alert_thresholds' => [
        'sharing_without_appointment_days' => self::sharingAlertDays(),
        'ubs_without_monitoring_days' => self::ubsMonitoringAlertDays(),
      ],
      'clinical_record' => [
        'schema_version' => '1.0',
        'payload_field' => 'payload_json',
      ],
    ];
  }

  public static function isMinor(?string $birthDate, ?DateTimeImmutable $onDate = null): bool {
    if ($birthDate === null || $birthDate === '') {
      return false;
    }

    $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
    if ($birth === false || $birth->format('Y-m-d') !== $birthDate) {
      return false;
    }

    $reference = $onDate ?? new DateTimeImmutable('today');
    return $birth->diff($reference)->y < self::RESPONSIBLE_REQUIRED_UNDER_AGE;
  }

  private static function positiveEnvironmentInt(string $name, int $fallback): int {
    $value = filter_var(getenv($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $value === false ? $fallback : (int)$value;
  }
}
