<?php
declare(strict_types=1);

final class CareNetwork {
  private const UBS_TEAM_GROUPS = [
    [
      'ubs' => 'UBS 01 SAO SEBASTIAO',
      'teams' => [
        'ESF EQUIPE 04 - LARANJA',
        'ESF EQUIPE 07 - LILAS',
        'ESF EQUIPE 08 - ROSA',
        'ESF EQUIPE 09 - VERDE',
        'ESF EQUIPE 10 - VERMELHA',
        'ESF DOURADA',
      ],
    ],
    ['ubs' => 'UBS 02 SAO SEBASTIAO - T.R.E.', 'teams' => ['ESF EQUIPE T.R.E.']],
    ['ubs' => 'UBS 03 SAO SEBASTIAO - Oeste', 'teams' => ['ESF EQUIPE 12 - OESTE 2', 'ESB OESTE SSB']],
    ['ubs' => 'UBS 04 SAO SEBASTIAO - Morro Azul', 'teams' => ['ESF EQUIPE MORRO AZUL']],
    ['ubs' => 'UBS 05 SAO SEBASTIAO - Nova Betania', 'teams' => ['ESF EQUIPE 01 - NOVA BETANIA', 'ESB NOVA BETANIA SSB']],
    ['ubs' => 'UBS 06 SAO SEBASTIAO - Sao Francisco', 'teams' => ['ESF EQUIPE 16 - SAO FRANCISCO']],
    ['ubs' => 'UBS 07 SAO SEBASTIAO - Morro da Cruz', 'teams' => ['ESF EQUIPE 02 - MORRO DA CRUZ']],
    ['ubs' => 'UBS 08 SAO SEBASTIAO - Cavas de Baixo', 'teams' => ['ESF EQUIPE 03 - CAVAS DE BAIXO']],
    [
      'ubs' => 'UBS 09 SAO SEBASTIAO - Bosque',
      'teams' => ['ESF EQ 21 - BOSQUE 1', 'ESF EQUIPE 23 - VILA NOVA 2', 'ESB BOSQUE 1 SSB', 'EMULTI IPE AMARELO'],
    ],
    ['ubs' => 'UBS 10 SAO SEBASTIAO - Joao Candido', 'teams' => ['ESF EQUIPE 22 - JOAO CANDIDO']],
    ['ubs' => 'UBS 11 SAO SEBASTIAO - Bosque 2', 'teams' => ['ESF EQUIPE 25 - BOSQUE 2']],
    ['ubs' => 'UBS 12 SAO SEBASTIAO - Sao Jose', 'teams' => ['ESF EQUIPE 17 - SAO JOSE']],
    [
      'ubs' => 'UBS 14 SAO SEBASTIAO - PDF IV',
      'teams' => ['EQ. AMPLIADA 27 TIPO III CDP', 'EQ. PSICOSOCIAL 30H - 2238861', 'EQ. AMPLIADA ROSIMEIRE RODRIGUES'],
    ],
    [
      'ubs' => 'UBS 15 SAO SEBASTIAO - CIR',
      'teams' => ['EQ. PSICOSOCIAL 30H - 2238853', 'EQ. AMPLIADA 30H - 2238942', 'EQ. PSICOSOCIAL 30H - 2238969', 'EQ. AMPLIADA CARLA MACHADO'],
    ],
    [
      'ubs' => 'UBS 16 SAO SEBASTIAO - PDF 1',
      'teams' => ['EQ. PSICOSOCIAL 30H - 2238837', 'EQ. AMPLIADA 20H - 2238896', 'EQ. PSICOSOCIAL 20H - 2238918', 'EQ. AMPLIADA EURICO JARDIM'],
    ],
    [
      'ubs' => 'UBS 17 SAO SEBASTIAO - PDF 2',
      'teams' => ['EQ. PSICOSOCIAL 30H - 2238845', 'EQ. AMPLIADA 20H - 2238926', 'EQ. PSICOSOCIAL 20H - 2238934', 'EQ. AMPLIADA ALFA'],
    ],
    ['ubs' => 'UBS 19 SAO SEBASTIAO - Vila do Boa', 'teams' => ['ESF EQUIPE VILA DO BOA']],
    ['ubs' => 'UBS 20 SAO SEBASTIAO CDP', 'teams' => ['EQ. PSICOSOCIAL 30H - 2238888', 'EQUIPE AMPLIADA 20H - 2502216']],
    [
      'ubs' => 'UBS 1 JARDINS MANGUEIRAL',
      'teams' => ['ESF DIAMANTE', 'ESF AMETISTA', 'ESF SAFIRA', 'ESF ESMERALDA', 'ESB 1 JARDINS MANGUEIRAL', 'ESB 2 JARDINS MANGUEIRAL', 'EMULTI RUBI'],
    ],
  ];

  public static function groups(): array {
    return self::UBS_TEAM_GROUPS;
  }

  public static function ubsOptions(): array {
    $options = [];
    foreach (self::UBS_TEAM_GROUPS as $group) {
      $options[(string)$group['ubs']] = (string)$group['ubs'];
    }
    return $options;
  }

  public static function teamOptions(): array {
    $options = [];
    foreach (self::UBS_TEAM_GROUPS as $group) {
      foreach ($group['teams'] as $team) {
        $options[(string)$team] = (string)$team;
      }
    }
    return $options;
  }

  public static function teamsFor(string $ubs): array {
    foreach (self::UBS_TEAM_GROUPS as $group) {
      if (self::comparable((string)$group['ubs']) === self::comparable($ubs)) {
        return array_values($group['teams']);
      }
    }
    return [];
  }

  public static function containsUbs(string $ubs): bool {
    return self::matchOption($ubs, self::ubsOptions()) !== '';
  }

  public static function containsTeam(string $team): bool {
    return self::matchOption($team, self::teamOptions()) !== '';
  }

  public static function normalizeUbs(string $ubs): string {
    return self::matchOption($ubs, self::ubsOptions());
  }

  public static function normalizeTeam(string $team): string {
    return self::matchOption($team, self::teamOptions());
  }

  private static function matchOption(string $value, array $options): string {
    $comparable = self::comparable($value);
    foreach ($options as $key => $label) {
      if (self::comparable((string)$key) === $comparable || self::comparable((string)$label) === $comparable) {
        return (string)$key;
      }
    }
    return '';
  }

  private static function comparable(string $value): string {
    $normalized = function_exists('iconv')
      ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value)
      : $value;
    return strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', ' ', trim((string)$normalized)));
  }
}
