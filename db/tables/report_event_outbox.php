<?php
return ['create' => <<<SQL
    id CHAR(64) PRIMARY KEY,
    event VARCHAR(60) NOT NULL,
    topic VARCHAR(40) NOT NULL,
    context TEXT NOT NULL,
    created_at BIGINT NOT NULL,
    next_attempt_at BIGINT NOT NULL DEFAULT 0,
    attempts INT NOT NULL DEFAULT 0,
    sent_at BIGINT NULL,
    message_id BIGINT NULL,
    last_error INT NULL,
    KEY report_due (sent_at, next_attempt_at, created_at)
SQL];
