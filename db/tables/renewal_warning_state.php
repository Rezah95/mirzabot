<?php

return ['create' => <<<SQL
    id CHAR(64) PRIMARY KEY,
    invoice_id VARCHAR(200) NOT NULL,
    user_id VARCHAR(20) NOT NULL,
    kind VARCHAR(10) NOT NULL,
    coupon_code VARCHAR(40) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ready',
    next_attempt_at BIGINT NOT NULL DEFAULT 0,
    created_at BIGINT NOT NULL
SQL];
