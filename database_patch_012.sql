-- SISELO
-- Migracao 012
-- Identidades externas para autenticacao SSO

USE intranet_cadh;

CREATE TABLE IF NOT EXISTS user_external_identities (

    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    user_id INT NOT NULL,

    provider VARCHAR(50) NOT NULL,

    issuer VARCHAR(255) NOT NULL,

    subject VARCHAR(255) NOT NULL,

    linked_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    last_login_at DATETIME NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uq_external_identity
        (issuer, subject),

    KEY idx_external_user
        (user_id),

    CONSTRAINT fk_external_identity_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
