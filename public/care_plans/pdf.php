<?php
declare(strict_types=1);

require __DIR__ . '/../../app/core/bootstrap.php';
require __DIR__ . '/../../app/middleware/auth.php';
require __DIR__ . '/../../app/middleware/rbac.php';

require_auth();
require_permission($pdo, 'careplans.view');

require __DIR__ . '/../../vendor/autoload.php';

$id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$id) {
    http_response_code(400);
    exit('Identificador do plano inválido.');
}

// Consulta somente planos e pacientes ativos.
$st = $pdo->prepare("
    SELECT
        cp.*,
        p.full_name,
        p.cpf,
        p.birth_date,
        p.phone,
        p.ubs_ref,
        p.team_ref
    FROM care_plans cp
    JOIN patients p ON p.id = cp.patient_id
    WHERE
        cp.id = :id
        AND cp.deleted_at IS NULL
        AND p.deleted_at IS NULL
    LIMIT 1
");

$st->execute([':id' => $id]);
$plan = $st->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    http_response_code(404);
    exit('Plano não encontrado.');
}

// Busca os registros das diferentes seções.
$st = $pdo->prepare("
    SELECT *
    FROM care_plan_items
    WHERE care_plan_id = :id
    ORDER BY sort_order ASC, id ASC
");

$st->execute([':id' => $id]);
$items = $st->fetchAll(PDO::FETCH_ASSOC);

// Escapa texto antes de incluir no HTML.
function pdfEsc($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Exibe texto multilinha ou um marcador para campos vazios.
function pdfValue($value): string
{
    $text = trim((string)($value ?? ''));

    if ($text === '') {
        return '<span class="empty">Não informado</span>';
    }

    return nl2br(pdfEsc($text), false);
}

// Converte datas do MySQL para o padrão brasileiro.
function pdfDate($value): string
{
    $raw = trim((string)($value ?? ''));

    if ($raw === '') {
        return 'Não informada';
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        substr($raw, 0, 10)
    );

    return $date
        ? $date->format('d/m/Y')
        : $raw;
}

// Localiza um item considerando tipo e título.
// Isso evita misturar a especialidade Endocrinologia
// com suas intervenções e fatores dificultadores.
function pdfFindItem(
    array $items,
    string $type,
    string $title
): ?array {
    foreach ($items as $item) {
        if (
            ($item['item_type'] ?? '') === $type &&
            trim((string)($item['title'] ?? '')) === $title
        ) {
            return $item;
        }
    }

    return null;
}

// Campos da avaliação geral.
$profiles = [
    'Autocuidado',
    'Letramento funcional em saúde',
    'Adesão terapêutica',
    'Estágio motivacional para a mudança',
    'Suporte familiar',
    'Suporte social',
];

// Especialidades do CADH.
$specialties = [
    'Psicologia',
    'Enfermagem',
    'Endocrinologia',
    'Cardiologia',
    'Oftalmologia',
    'Nutrição',
    'Assistência Social',
    'Fisioterapia',
];

// Intervenções específicas da ficha atual.
$interventionSpecialties = [
    'Endocrinologia',
    'Cardiologia',
];

// Sinais de alerta.
$alerts = [
    'Sinal de alerta 1',
    'Sinal de alerta 2',
    'Sinal de alerta 3',
];

// Prioridades e metas cadastradas.
$goals = array_values(array_filter(
    $items,
    static function (array $item): bool {
        return ($item['item_type'] ?? '') === 'meta';
    }
));

// Preserva a exibição de eventuais registros adicionais
// que não façam parte dos campos conhecidos da ficha.
$extras = [];

foreach ($items as $item) {
    $type = (string)($item['item_type'] ?? '');
    $title = trim((string)($item['title'] ?? ''));

    $known =
        $type === 'meta' ||
        (
            $type === 'alerta' &&
            in_array($title, $alerts, true)
        ) ||
        (
            $type === 'dificuldade' &&
            in_array($title, $specialties, true)
        ) ||
        (
            $type === 'recomendacao' &&
            (
                in_array($title, $profiles, true) ||
                in_array(
                    $title,
                    $interventionSpecialties,
                    true
                )
            )
        );

    if (!$known) {
        $extras[] = $item;
    }
}

$generatedAt = (
    new DateTimeImmutable(
        'now',
        new DateTimeZone('America/Sao_Paulo')
    )
)->format('d/m/Y \à\s H:i');

// Montagem do HTML do PDF.
ob_start();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">

<style>
    @page {
        margin: 40px 38px 58px;
    }

    body {
        font-family: 'DejaVu Sans', sans-serif;
        color: #203246;
        font-size: 9px;
        line-height: 1.38;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    .brand {
        margin-bottom: 13px;
        border-bottom: 2px solid #1c678c;
    }

    .brand td {
        padding: 0 0 11px;
        vertical-align: middle;
    }

    .brand-name {
        font-size: 21px;
        font-weight: bold;
        color: #0d4268;
        letter-spacing: 1px;
    }

    .brand-sub {
        font-size: 8px;
        color: #60778b;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .doc-title {
        text-align: right;
        font-size: 17px;
        font-weight: bold;
        color: #0d4268;
    }

    .doc-sub {
        text-align: right;
        font-size: 8px;
        color: #60778b;
    }

    .intro {
        padding: 9px 11px;
        background: #edf5f9;
        margin-bottom: 12px;
        border-left: 4px solid #1b9c91;
    }

    .intro b {
        color: #164d70;
    }

    .section {
        margin-bottom: 11px;
    }

    .section-title {
        background: #e9f3f8;
        border-left: 4px solid #16a08d;
        padding: 7px 9px;
        font-size: 10px;
        font-weight: bold;
        color: #123c5d;
        margin-bottom: 5px;
    }

    .data th {
        background: #f2f6f9;
        color: #33516b;
        font-weight: bold;
        text-align: left;
    }

    .data th,
    .data td {
        border: 1px solid #d7e2eb;
        padding: 7px 8px;
        vertical-align: top;
        overflow-wrap: break-word;
    }

    .data tr {
        page-break-inside: avoid;
    }

    .data thead {
        display: table-header-group;
    }

    .patient td {
        border: 1px solid #d7e2eb;
        padding: 6px 8px;
        vertical-align: top;
        width: 50%;
    }

    .label {
        display: block;
        color: #667d90;
        font-size: 7.5px;
        text-transform: uppercase;
        margin-bottom: 3px;
    }

    .value {
        font-size: 9px;
        font-weight: bold;
        color: #203246;
    }

    .empty {
        color: #8a9ba9;
        font-style: italic;
        font-weight: normal;
    }

    .warning {
        font-size: 7.8px;
        color: #64798d;
        padding: 5px 0;
    }

    .footer {
        position: fixed;
        bottom: -37px;
        left: 0;
        right: 0;
        border-top: 1px solid #d5e1ea;
        padding-top: 7px;
        color: #6b8091;
        font-size: 7.5px;
    }

    .footer-right {
        float: right;
    }
</style>
</head>

<body>

<!-- CABEÇALHO INSTITUCIONAL -->

<table class="brand">
    <tr>
        <td style="width: 48%">
            <div class="brand-name">SISELO</div>
            <div class="brand-sub">
                Sistema de Saúde
            </div>
        </td>

        <td>
            <div class="doc-title">
                PLANO DE CUIDADO
            </div>
            <div class="doc-sub">
                CADH • Planificação da Atenção à Saúde / SUS
            </div>
        </td>
    </tr>
</table>

<div class="intro">
    <b>
        Registro assistencial • Plano nº
        <?= pdfEsc($id) ?>
    </b>

    <br>

    Documento gerado para consulta e acompanhamento
    pelos profissionais autorizados.
</div>

<!-- 01 - IDENTIFICAÇÃO DO PACIENTE -->

<div class="section">
    <div class="section-title">
        01 • Identificação do usuário do SUS
    </div>

    <table class="patient">
        <tr>
            <td>
                <span class="label">Nome</span>
                <span class="value">
                    <?= pdfValue($plan['full_name']) ?>
                </span>
            </td>

            <td>
                <span class="label">CPF</span>
                <span class="value">
                    <?= pdfValue($plan['cpf']) ?>
                </span>
            </td>
        </tr>

        <tr>
            <td>
                <span class="label">
                    Data de nascimento
                </span>
                <span class="value">
                    <?= pdfEsc(pdfDate($plan['birth_date'])) ?>
                </span>
            </td>

            <td>
                <span class="label">Telefone</span>
                <span class="value">
                    <?= pdfValue($plan['phone']) ?>
                </span>
            </td>
        </tr>

        <tr>
            <td>
                <span class="label">UBS de origem</span>
                <span class="value">
                    <?= pdfValue($plan['ubs_ref']) ?>
                </span>
            </td>

            <td>
                <span class="label">
                    Equipe de referência
                </span>
                <span class="value">
                    <?= pdfValue($plan['team_ref']) ?>
                </span>
            </td>
        </tr>

        <tr>
            <td>
                <span class="label">
                    Início do plano
                </span>
                <span class="value">
                    <?= pdfEsc(pdfDate($plan['start_date'])) ?>
                </span>
            </td>

            <td>
                <span class="label">
                    Próxima revisão*
                </span>
                <span class="value">
                    <?= pdfEsc(pdfDate($plan['end_date'])) ?>
                </span>
            </td>
        </tr>
    </table>
</div>

<!-- 02 - AVALIAÇÃO GERAL -->

<div class="section">
    <div class="section-title">
        02 • Plano de cuidados — avaliação geral
    </div>

    <table class="data">
        <thead>
            <tr>
                <th style="width: 44%">
                    Aspecto avaliado
                </th>
                <th>Situação</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($profiles as $title): ?>
                <?php
                    $item = pdfFindItem(
                        $items,
                        'recomendacao',
                        $title
                    );
                ?>

                <tr>
                    <td>
                        <?= pdfEsc($title) ?>
                    </td>

                    <td>
                        <?= pdfValue(
                            $item['recommendation'] ?? null
                        ) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- 03 - ESPECIALIDADES -->

<div class="section">
    <div class="section-title">
        03 • Fatores dificultadores e recomendações
        por especialidade
    </div>

    <table class="data">
        <thead>
            <tr>
                <th style="width: 20%">
                    Especialidade
                </th>

                <th style="width: 40%">
                    Fatores dificultadores
                </th>

                <th>
                    Recomendação
                </th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($specialties as $title): ?>
                <?php
                    $item = pdfFindItem(
                        $items,
                        'dificuldade',
                        $title
                    );
                ?>

                <tr>
                    <td>
                        <b><?= pdfEsc($title) ?></b>
                    </td>

                    <td>
                        <?= pdfValue(
                            $item['difficulty'] ?? null
                        ) ?>
                    </td>

                    <td>
                        <?= pdfValue(
                            $item['recommendation'] ?? null
                        ) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- 04 - INTERVENÇÕES -->

<div class="section">
    <div class="section-title">
        04 • Intervenções medicamentosas e
        não medicamentosas
    </div>

    <table class="data">
        <thead>
            <tr>
                <th style="width: 26%">
                    Especialidade
                </th>

                <th>
                    Condutas e intervenções
                </th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($interventionSpecialties as $title): ?>
                <?php
                    $item = pdfFindItem(
                        $items,
                        'recomendacao',
                        $title
                    );
                ?>

                <tr>
                    <td>
                        <b><?= pdfEsc($title) ?></b>
                    </td>

                    <td>
                        <?= pdfValue(
                            $item['recommendation'] ?? null
                        ) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- 05 - SINAIS DE ALERTA -->

<div class="section">
    <div class="section-title">
        05 • Orientações para sinais de alerta
    </div>

    <table class="data">
        <thead>
            <tr>
                <th style="width: 19%">
                    Alerta
                </th>

                <th style="width: 40%">
                    Situação
                </th>

                <th>
                    Recomendação
                </th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($alerts as $title): ?>
                <?php
                    $item = pdfFindItem(
                        $items,
                        'alerta',
                        $title
                    );
                ?>

                <tr>
                    <td>
                        <b><?= pdfEsc($title) ?></b>
                    </td>

                    <td>
                        <?= pdfValue(
                            $item['situation'] ?? null
                        ) ?>
                    </td>

                    <td>
                        <?= pdfValue(
                            $item['recommendation'] ?? null
                        ) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- 06 - PRIORIDADES E METAS -->

<div class="section">
    <div class="section-title">
        06 • Prioridades e recomendações da
        equipe especializada
    </div>

    <table class="data">
        <thead>
            <tr>
                <th style="width: 17%">
                    Prioridade
                </th>

                <th style="width: 41%">
                    Dificuldade
                </th>

                <th>
                    Meta estabelecida
                </th>
            </tr>
        </thead>

        <tbody>
            <?php if (!$goals): ?>
                <tr>
                    <td colspan="3">
                        <span class="empty">
                            Nenhuma prioridade registrada.
                        </span>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($goals as $item): ?>
                    <tr>
                        <td>
                            <b>
                                <?= pdfValue(
                                    $item['title'] ?? null
                                ) ?>
                            </b>
                        </td>

                        <td>
                            <?= pdfValue(
                                $item['difficulty'] ?? null
                            ) ?>
                        </td>

                        <td>
                            <?= pdfValue(
                                $item['goal'] ?? null
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 07 - REGISTRO DA APS -->

<div class="section">
    <div class="section-title">
        07 • Registro e monitoramento da
        Atenção Primária (APS)
    </div>

    <table class="patient">
        <tr>
            <td style="width: 32%">
                <span class="label">
                    Data da avaliação APS*
                </span>

                <span class="value">
                    <?= pdfEsc(
                        pdfDate($plan['end_date'])
                    ) ?>
                </span>
            </td>

            <td>
                <span class="label">
                    Monitoramento na APS
                </span>

                <?= pdfValue(
                    $plan['interventions']
                ) ?>
            </td>
        </tr>
    </table>

    <div class="warning">
        * Atualmente, a data da avaliação APS
        e a data da próxima revisão compartilham
        o mesmo campo no sistema. Elas ainda
        não são registradas de forma independente.
    </div>
</div>

<!-- REGISTROS ADICIONAIS, SE EXISTIREM -->

<?php if ($extras): ?>
    <div class="section">
        <div class="section-title">
            08 • Outros registros do plano
        </div>

        <table class="data">
            <thead>
                <tr>
                    <th style="width: 27%">
                        Registro
                    </th>

                    <th>
                        Conteúdo
                    </th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($extras as $item): ?>
                    <tr>
                        <td>
                            <?= pdfValue(
                                $item['title'] ?? null
                            ) ?>
                        </td>

                        <td>
                            <?php
                                $fields = [
                                    'situation' => 'Situação',
                                    'recommendation' => 'Recomendação',
                                    'difficulty' => 'Dificuldade',
                                    'goal' => 'Meta',
                                ];
                            ?>

                            <?php foreach ($fields as $field => $label): ?>
                                <?php if (
                                    trim((string)(
                                        $item[$field] ?? ''
                                    )) !== ''
                                ): ?>
                                    <b>
                                        <?= pdfEsc($label) ?>:
                                    </b>

                                    <?= pdfValue(
                                        $item[$field]
                                    ) ?>

                                    <br>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- RODAPÉ -->

<div class="footer">
    SISELO • Plano de Cuidado nº
    <?= pdfEsc($id) ?>
    • Acesso restrito a profissionais autorizados

    <span class="footer-right">
        Gerado em <?= pdfEsc($generatedAt) ?>
        (Brasília)
    </span>
</div>

</body>
</html>

<?php

$html = (string)ob_get_clean();

// Configurações do Dompdf.
$options = new \Dompdf\Options();

$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isPhpEnabled', false);

$dompdf = new \Dompdf\Dompdf($options);

$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Numeração das páginas.
$font = $dompdf
    ->getFontMetrics()
    ->getFont('DejaVu Sans', 'normal');

$dompdf->getCanvas()->page_text(
    480,
    807,
    'Página {PAGE_NUM} de {PAGE_COUNT}',
    $font,
    8,
    [0.40, 0.49, 0.57]
);

// Geração final.
$pdf = $dompdf->output();

$filename = 'Plano_de_Cuidado_' . $id . '.pdf';

// Cabeçalhos de segurança e exibição.
header('Content-Type: application/pdf');

header(
    'Content-Disposition: inline; filename="' .
    $filename .
    '"'
);

header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($pdf));

echo $pdf;
