<?php

return ['create' => <<<SQL
    id CHAR(24) PRIMARY KEY,
    admin_id VARCHAR(200) NOT NULL,
    amount INT UNSIGNED NOT NULL,
    recipient_count INT UNSIGNED NOT NULL,
    notify TINYINT(1) NOT NULL DEFAULT 0,
    notification_status VARCHAR(20) NOT NULL DEFAULT 'none',
    admin_message_id VARCHAR(100) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at BIGINT UNSIGNED NOT NULL,
    applied_at BIGINT UNSIGNED NULL
SQL];
