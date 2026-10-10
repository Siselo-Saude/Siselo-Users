
-- SIS ELO
-- Database Patch 013
-- Solicitacoes de acesso e aprovacao administrativa
--
-- As informacoes solicitadas pelo usuario nao representam
-- permissoes autorizadas.
--
-- As permissoes efetivas continuam armazenadas
-- em users e user_roles e dependem de aprovacao.

CREATE TABLE IF NOT EXISTS user_access_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    user_id INT NOT NULL,

    requested_role VARCHAR(20) NULL,

    requested_user_type VARCHAR(10) NULL,

    requested_specialty VARCHAR(80) NULL,

    status VARCHAR(20) NOT NULL DEFAULT 'pending',

    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    reviewed_at DATETIME NULL,

    reviewed_by_user_id INT NULL,

    review_notes TEXT NULL,

    PRIMARY KEY (id),

    INDEX idx_access_requests_user_status (
        user_id,
        status
    ),

    INDEX idx_access_requests_status_date (
        status,
        submitted_at
    ),

    CONSTRAINT fk_access_requests_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_access_requests_reviewer
        FOREIGN KEY (reviewed_by_user_id)
        REFERENCES users(id)
        ON DELETE SET NULL

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4;

