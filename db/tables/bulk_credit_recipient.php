<?php

return ['create' => <<<SQL
    batch_id CHAR(24) NOT NULL,
    user_id VARCHAR(500) NOT NULL,
    PRIMARY KEY (batch_id, user_id),
    INDEX (user_id)
SQL];
