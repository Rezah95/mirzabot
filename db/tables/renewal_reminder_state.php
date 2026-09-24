<?php

return ['create' => <<<SQL
    id CHAR(64) PRIMARY KEY,
    invoice_id VARCHAR(200) NOT NULL,
    user_id VARCHAR(20) NOT NULL,
    expired_at BIGINT NOT NULL,
    sent_count INT NOT NULL DEFAULT 0,
    confirmed_count INT NOT NULL DEFAULT 0,
    last_sent_at BIGINT NOT NULL DEFAULT 0,
    next_attempt_at BIGINT NOT NULL DEFAULT 0,
    coupon_code VARCHAR(40) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    UNIQUE KEY reminder_cycle (invoice_id, user_id, expired_at)
SQL];
