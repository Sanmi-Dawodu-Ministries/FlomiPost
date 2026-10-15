-- =============================================================================
-- grace_parishes: submissions from the Grace parish registration form
-- =============================================================================
-- One row per parish submitted through public/grace/index.html (handled by
-- backend/forms/GraceParishForm.php). County is optional because many places
-- don't have one.
--
-- Apply:  mysql flowpost < 2026_10_grace_parishes.sql
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS grace_parishes (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parish_name   VARCHAR(200)    NOT NULL,
    shepherd_name VARCHAR(200)    NOT NULL,         -- pastor / shepherd in charge
    city          VARCHAR(120)    NOT NULL,
    county        VARCHAR(120)    NULL,             -- optional: not every place has one
    state         VARCHAR(120)    NOT NULL,         -- state / province / region
    country       VARCHAR(120)    NOT NULL,
    submitter_ip  VARCHAR(45)     NULL,             -- IPv4 or IPv6, for abuse review
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_country_state (country, state),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
