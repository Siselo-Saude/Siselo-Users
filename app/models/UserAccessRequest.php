<?php
declare(strict_types=1);

/**
 * SIS ELO - Solicitacoes de Acesso
 *
 * Este modelo armazena as preferencias informadas
 * pelo usuario durante o cadastro.
 *
 * IMPORTANTE:
 * Uma solicitacao nunca concede permissoes.
 * Somente o administrador pode autorizar o acesso.
 */

final class UserAccessRequest
{
    public static function createPending(
        PDO $pdo,
        int $userId,
        array $input
    ): int {
        if ($userId <= 0) {
            throw new RuntimeException(
                'Usuario invalido para a solicitacao.'
            );
        }

        $requestedRole = strtolower(
            trim((string)($input['requested_role'] ?? ''))
        );

        $requestedUnit = strtoupper(
            trim((string)($input['user_type'] ?? ''))
        );

        $requestedSpecialty = trim(
            (string)($input['specialty'] ?? '')
        );

        // O usuario nunca pode solicitar acesso administrativo.
        if (!in_array(
            $requestedRole,
            ['alimentador', 'visualizador'],
            true
        )) {
            throw new RuntimeException(
                'Selecione um perfil valido: alimentador ou visualizador.'
            );
        }

        if (!in_array(
            $requestedUnit,
            ['CADH', 'UBS'],
            true
        )) {
            throw new RuntimeException(
                'Selecione a unidade CADH ou UBS.'
            );
        }

        // Especialidade obrigatoria somente para CADH.
        if ($requestedUnit === 'CADH') {
            if (!in_array(
                $requestedSpecialty,
                User::authSpecialties(),
                true
            )) {
                throw new RuntimeException(
                    'Selecione uma especialidade valida para CADH.'
                );
            }
        } else {
            $requestedSpecialty = '';
        }

        $stmt = $pdo->prepare('
            INSERT INTO user_access_requests (
                user_id,
                requested_role,
                requested_user_type,
                requested_specialty,
                status,
                submitted_at
            )
            VALUES (
                :user_id,
                :requested_role,
                :requested_user_type,
                :requested_specialty,
                :status,
                NOW()
            )
        ');

        $stmt->execute([
            ':user_id' => $userId,
            ':requested_role' => $requestedRole,
            ':requested_user_type' => $requestedUnit,
            ':requested_specialty' => $requestedSpecialty !== ''
                ? $requestedSpecialty
                : null,
            ':status' => 'pending',
        ]);

        return (int)$pdo->lastInsertId();
    }

    public static function latestForUser(
        PDO $pdo,
        int $userId
    ): ?array {
        $stmt = $pdo->prepare('
            SELECT
                id,
                user_id,
                requested_role,
                requested_user_type,
                requested_specialty,
                status,
                submitted_at,
                reviewed_at,
                reviewed_by_user_id,
                review_notes
            FROM user_access_requests
            WHERE user_id = :user_id
            ORDER BY id DESC
            LIMIT 1
        ');

        $stmt->execute([
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
