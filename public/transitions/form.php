<?php
declare(strict_types=1);

require __DIR__ . '/../../app/core/bootstrap.php';
require __DIR__ . '/../../app/middleware/auth.php';
require __DIR__ . '/../../app/middleware/rbac.php';
require __DIR__ . '/../../app/services/Audit.php';

require_auth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$editing = $id !== null;

$prefPatientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

$patients = $pdo->query("SELECT id, full_name, cpf, team_ref FROM patients WHERE deleted_at IS NULL ORDER BY full_name ASC LIMIT 800")->fetchAll();

$statuses = ['pendente','em_andamento','concluida','cancelada'];

if ($editing) {
  require_permission($pdo, 'transitions.update');

  $st = $pdo->prepare("SELECT * FROM transitions WHERE id=:id AND deleted_at IS NULL");
  $st->execute([':id'=>$id]);
  $row = $st->fetch();
  if (!$row) { echo "Encaminhamento não encontrado."; exit; }
} else {
  require_permission($pdo, 'transitions.create');
  $row = [
    'patient_id' => $prefPatientId ?: '',
    'transition_date' => date('Y-m-d'),
    'from_service' => '',
    'to_service' => '',
    'status' => 'pendente',
    'notes' => ''
  ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $patientId = (int)($_POST['patient_id'] ?? 0);
  $date = (string)($_POST['transition_date'] ?? '');
  $from = trim((string)($_POST['from_service'] ?? ''));
  $to = trim((string)($_POST['to_service'] ?? ''));
  $status = trim((string)($_POST['status'] ?? 'pendente'));
  $notes = trim((string)($_POST['notes'] ?? ''));

  if ($patientId <= 0 || $date === '' || $status === '') {
    $error = "Paciente, data e status são obrigatórios.";
  } elseif (!in_array($status, $statuses, true)) {
    $error = "Status inválido.";
  } else {
    if ($editing) {
      $before = $row;

      $up = $pdo->prepare("
        UPDATE transitions SET
          patient_id=:p,
          transition_date=:d,
          from_service=:f,
          to_service=:t,
          status=:s,
          notes=:n,
          updated_at=NOW()
        WHERE id=:id
      ");
      $up->execute([
        ':p'=>$patientId, ':d'=>$date, ':f'=>$from, ':t'=>$to, ':s'=>$status, ':n'=>$notes, ':id'=>$id
      ]);

      Audit::log($pdo, current_user_id(), 'update', 'transitions', $id, $before, [
        'patient_id'=>$patientId,'transition_date'=>$date,'from_service'=>$from,'to_service'=>$to,'status'=>$status,'notes'=>$notes
      ]);

      redirect("/public/patients/show.php?id={$patientId}&tab=transicoes");
    } else {
      $ins = $pdo->prepare("
        INSERT INTO transitions (patient_id, transition_date, from_service, to_service, status, notes, created_by_user_id)
        VALUES (:p, :d, :f, :t, :s, :n, :u)
      ");
      $ins->execute([
        ':p'=>$patientId, ':d'=>$date, ':f'=>$from, ':t'=>$to, ':s'=>$status, ':n'=>$notes, ':u'=>current_user_id()
      ]);
      $newId = (int)$pdo->lastInsertId();

      Audit::log($pdo, current_user_id(), 'create', 'transitions', $newId, null, [
        'patient_id'=>$patientId,'transition_date'=>$date,'from_service'=>$from,'to_service'=>$to,'status'=>$status,'notes'=>$notes
      ]);

      redirect("/public/patients/show.php?id={$patientId}&tab=transicoes");
    }
  }
}
?>
<?php
$pageTitle = 'Pacientes';
require __DIR__ . '/../../app/views/layout/header.php';
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title><?= $editing ? 'Editar' : 'Novo' ?> Encaminhamento</title></head>
<body>
  <h1><?= $editing ? 'Editar' : 'Novo' ?> Encaminhamento</h1>
  <?php if (!empty($error)) echo "<p style='color:red'>".h($error)."</p>"; ?>

  <form method="post">
    <?= csrf_field() ?>

    <label>Paciente *</label><br>
    <select name="patient_id" required>
      <option value="">-- selecione --</option>
      <?php foreach ($patients as $p): ?>
        <option value="<?= (int)$p['id'] ?>"
          <?= ((int)$row['patient_id']===(int)$p['id']) ? 'selected' : '' ?>>
          <?= h($p['full_name']) ?> (CPF: <?= h($p['cpf']) ?> | Equipe: <?= h($p['team_ref']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <br><br>

    <label>Data *</label><br>
    <input type="date" name="transition_date" value="<?= h($row['transition_date']) ?>" required>
    <br><br>

    <label>De (serviço/origem)</label><br>
    <input name="from_service" value="<?= h($row['from_service']) ?>" style="width:420px">
    <br><br>

    <label>Para (serviço/destino)</label><br>
    <input name="to_service" value="<?= h($row['to_service']) ?>" style="width:420px">
    <br><br>

    <label>Status *</label><br>
    <select name="status" required>
      <?php foreach ($statuses as $s): ?>
        <option value="<?= h($s) ?>" <?= ($row['status']===$s) ? 'selected' : '' ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
    <br><br>

    <label>Notas</label><br>
    <textarea name="notes" style="width:520px; height:120px;"><?= h($row['notes']) ?></textarea>
    <br><br>

    <button type="submit">Salvar</button>
    <a href="/public/transitions/list.php">Cancelar</a>
  </form>
</body>
</html>
