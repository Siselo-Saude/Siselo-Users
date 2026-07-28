<?php
declare(strict_types=1);

require __DIR__ . '/../../app/core/bootstrap.php';
require __DIR__ . '/../../app/middleware/auth.php';
require __DIR__ . '/../../app/middleware/rbac.php';

require_auth();
require_permission($pdo, 'patients.view');

function patient_show_age_label(?string $birthDate): string {
  if ($birthDate === null || $birthDate === '') {
    return '';
  }

  try {
    $birth = new DateTimeImmutable($birthDate);
    return $birth->diff(new DateTimeImmutable('today'))->y . ' anos';
  } catch (Throwable $e) {
    return '';
  }
}

function tabLink(int $id, string $tab, string $label, string $current): string {
  $active = ($tab === $current) ? 'style="font-weight:bold"' : '';
  return '<a ' . $active . ' href="/patients/show.php?id=' . $id . '&tab=' . $tab . '">' . h($label) . '</a>';
}

$genderLabels = [
  'masculino' => 'Masculino',
  'feminino' => 'Feminino',
  'outro' => 'Outro',
];

$id = (int)($_GET['id'] ?? 0);
$tab = (string)($_GET['tab'] ?? 'planos');

$st = $pdo->prepare('SELECT * FROM patients WHERE id=:id AND deleted_at IS NULL');
$st->execute([':id' => $id]);
$patient = $st->fetch();
if (!$patient) {
  echo 'Paciente nao encontrado.';
  exit;
}

$plans = [];
$encs = [];
$trans = [];

if (can($pdo, 'careplans.view')) {
  $stp = $pdo->prepare('SELECT * FROM care_plans WHERE patient_id=:id AND deleted_at IS NULL ORDER BY id DESC');
  $stp->execute([':id' => $id]);
  $plans = $stp->fetchAll();
}

if (can($pdo, 'encounters.view')) {
  $ste = $pdo->prepare('SELECT * FROM encounters WHERE patient_id=:id AND deleted_at IS NULL ORDER BY encounter_date DESC, id DESC');
  $ste->execute([':id' => $id]);
  $encs = $ste->fetchAll();
}

if (can($pdo, 'transitions.view')) {
  $stt = $pdo->prepare('SELECT * FROM transitions WHERE patient_id=:id AND deleted_at IS NULL ORDER BY transition_date DESC, id DESC');
  $stt->execute([':id' => $id]);
  $trans = $stt->fetchAll();
}

$ageLabel = patient_show_age_label($patient['birth_date'] ?? null);
$genderLabel = $genderLabels[strtolower((string)($patient['sex'] ?? ''))] ?? '';
$statusLabel = ((string)($patient['status'] ?? 'ativo') === 'ativo') ? 'Ativo' : 'Inativo';
?>
<?php
$pageTitle = 'Usuário 360';
require __DIR__ . '/../../app/views/layout/header.php';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Usuário 360</title>
</head>
<body>
  <h1>Usuário 360</h1>

  <p>
    <b><?= h($patient['full_name']) ?></b><br>
    CPF: <?= h($patient['cpf']) ?> | Equipe: <?= h($patient['team_ref'] ?? '') ?><br>
    <?php if ($ageLabel !== ''): ?>Idade: <?= h($ageLabel) ?> | <?php endif; ?>
    <?php if ($genderLabel !== ''): ?>Genero: <?= h($genderLabel) ?> | <?php endif; ?>
    Status: <?= h($statusLabel) ?><br>
    Tel: <?= h($patient['phone'] ?? '') ?> | Email: <?= h($patient['email'] ?? '') ?><br>
    UDS: <?= h($patient['ubs_ref'] ?? '') ?><br>
    Contato de emergencia: <?= h($patient['emergency_contact'] ?? '') ?>
  </p>

  <?php if (($patient['allergies'] ?? '') !== '' || ($patient['chronic_conditions'] ?? '') !== ''): ?>
    <p>
      <?php if (($patient['allergies'] ?? '') !== ''): ?>
        <b>Alergias:</b> <?= nl2br(h($patient['allergies'])) ?><br>
      <?php endif; ?>
      <?php if (($patient['chronic_conditions'] ?? '') !== ''): ?>
        <b>Condicoes cronicas:</b> <?= nl2br(h($patient['chronic_conditions'])) ?>
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <p>
    <?= tabLink($id, 'planos', 'Planos de Cuidado', $tab) ?> |
    <?= tabLink($id, 'atendimentos', 'Atendimentos', $tab) ?> |
    <?= tabLink($id, 'transicoes', 'Encaminhamentos', $tab) ?>
  </p>

  <hr>

  <?php if ($tab === 'planos'): ?>
    <h2>Planos de Cuidado</h2>
    <?php if (can($pdo, 'transitions.create')): ?>
      <p><a href="/transitions/form.php?patient_id=<?= $id ?>">+ Novo encaminhamento para este paciente</a></p>
    <?php endif; ?>
    <?php if (can($pdo, 'careplans.create')): ?>
      <p><a href="/care_plans/form.php?patient_id=<?= $id ?>">+ Novo plano para este paciente</a></p>
    <?php endif; ?>
    <?php if (can($pdo, 'encounters.create')): ?>
      <p><a href="/encounters/form.php?patient_id=<?= $id ?>">+ Novo atendimento para este paciente</a></p>
    <?php endif; ?>

    <table border="1" cellpadding="6">
      <tr><th>ID</th><th>Inicio</th><th>Fim</th><th>Acoes</th></tr>
      <?php foreach ($plans as $p): ?>
        <tr>
          <td><?= (int)$p['id'] ?></td>
          <td><?= h($p['start_date']) ?></td>
          <td><?= h($p['end_date']) ?></td>
          <td>
            <a href="/care_plans/pdf.php?id=<?= (int)$p['id'] ?>" target="_blank">PDF</a>
            <?php if (can($pdo, 'careplans.update')): ?>
              <a href="/care_plans/form.php?id=<?= (int)$p['id'] ?>">Editar</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>

  <?php elseif ($tab === 'atendimentos'): ?>
    <h2>Atendimentos</h2>
    <table border="1" cellpadding="6">
      <tr><th>Data</th><th>Especialidade</th><th>Resumo</th></tr>
      <?php foreach ($encs as $e): ?>
        <tr>
          <td><?= h($e['encounter_date']) ?></td>
          <td><?= h($e['specialty']) ?></td>
          <td><?= h($e['summary']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

  <?php elseif ($tab === 'transicoes'): ?>
    <h2>Encaminhamentos</h2>
    <table border="1" cellpadding="6">
      <tr><th>Data</th><th>De</th><th>Para</th><th>Status</th><th>Notas</th></tr>
      <?php foreach ($trans as $t): ?>
        <tr>
          <td><?= h($t['transition_date']) ?></td>
          <td><?= h($t['from_service']) ?></td>
          <td><?= h($t['to_service']) ?></td>
          <td><?= h($t['status']) ?></td>
          <td><?= h($t['notes']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <hr>
 <p>
    <?php 
        $url_anterior = $_SERVER['HTTP_REFERER'] ?? '/index.php'; 
    ?>
    <a href="<?= $url_anterior ?>">Voltar</a> |
    <a href="/index.php">Home</a>
</p>
  <?php require __DIR__ . '/../../app/views/layout/footer.php'; ?>
</body>
</html>
