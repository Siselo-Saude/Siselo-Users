<?php
declare(strict_types=1);

/*
 * SIS ELO
 * Rota da API para geração de PDF dos Planos de Cuidado.
 *
 * A implementação original contém as verificações
 * de autenticação e permissão careplans.view.
 */

require __DIR__ . '/../../care_plans/pdf.php';