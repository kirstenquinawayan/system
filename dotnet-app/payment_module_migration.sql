CREATE TABLE IF NOT EXISTS csharp_payment_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT NOT NULL,
    provider VARCHAR(16) NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    stripe_session_id VARCHAR(255) NULL,
    checkout_url VARCHAR(2048) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'creating',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_csharp_payment_booking (booking_id, id),
    UNIQUE KEY uq_csharp_payment_stripe_session (stripe_session_id),
    KEY ix_csharp_payment_status (status),
    CONSTRAINT fk_csharp_payment_booking
        FOREIGN KEY (booking_id) REFERENCES booking_list (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS csharp_processed_stripe_events (
    event_id VARCHAR(255) NOT NULL,
    processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
