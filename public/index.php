<?php
declare(strict_types=1);
require __DIR__ . '/../app/core/bootstrap.php';
require __DIR__ . '/../app/middleware/auth.php';
require __DIR__ . '/../app/middleware/rbac.php';

require_auth();
?>
<?php
$pageTitle = 'Pacientes';
require __DIR__ . '/../app/views/layout/header.php';
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>SisElo</title></head>
<body>
  <h1>SISElo - UBS-CADH</h1>

  <p>
    <a href="/patients/list.php">Pacientes</a>
    <?php if (can($pdo, 'admin.manage')): ?>
      | <a href="/admin/users/list.php">Admin: Usuários</a>
    <?php endif; ?>
    | <a href="/logout.php">Sair</a>
  </p>
| <a href="/care_plans/list.php">Planos de Cuidado</a>
| <a href="/encounters/list.php">Atendimentos</a>
| <a href="/transitions/list.php">Encaminhamentos</a>
<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>
</body>
</html>
