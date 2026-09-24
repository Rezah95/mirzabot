<?php

return [
    'create' => <<<SQL
        id TINYINT UNSIGNED PRIMARY KEY,
        enabled TINYINT NOT NULL DEFAULT 0,
        interval_days INT NOT NULL DEFAULT 3,
        max_sends INT NOT NULL DEFAULT 3,
        started_at BIGINT NOT NULL DEFAULT 0,
        include_existing TINYINT NOT NULL DEFAULT 0,
        message_template TEXT NOT NULL,
        discount_send INT NOT NULL DEFAULT 0,
        discount_mode VARCHAR(10) NOT NULL DEFAULT 'percent',
        discount_value INT NOT NULL DEFAULT 10,
        discount_valid_days INT NOT NULL DEFAULT 7
        SQL,
    'seed' => [[
        'id' => 1,
        'message_template' => "سلام 👋\nزمان یا حجم سرویس «{service}» با نام کاربری {username} تمام شده است.\nبرای ادامهٔ استفاده، سرویس خود را تمدید کنید.\n{discount}",
    ]],
];
