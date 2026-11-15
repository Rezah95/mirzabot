<?php

return ['create' => <<<SQL
    id CHAR(64) PRIMARY KEY,
    invoice_id VARCHAR(200) NOT NULL,
    user_id VARCHAR(20) NOT NULL,
    delivery_at BIGINT NULL,
    delivery_chat_id VARCHAR(30) NULL,
    delivery_message_id BIGINT NULL,
    delivery_error_code INT NULL,
    expired_at BIGINT NOT NULL,
    sent_count INT NOT NULL DEFAULT 0,
    confirmed_count INT NOT NULL DEFAULT 0,
    last_sent_at BIGINT NOT NULL DEFAULT 0,
    next_attempt_at BIGINT NOT NULL DEFAULT 0,
    coupon_code VARCHAR(40) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    UNIQUE KEY reminder_cycle (invoice_id, user_id, expired_at)
SQL, 'columns' => [
    ['delivery_at', null, 'BIGINT NULL'],
    ['delivery_chat_id', null, 'VARCHAR(30) NULL'],
    ['delivery_message_id', null, 'BIGINT NULL'],
    ['delivery_error_code', null, 'INT NULL'],
]];
