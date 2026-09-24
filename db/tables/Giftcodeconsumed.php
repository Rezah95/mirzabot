<?php

return [
    'create' => <<<SQL
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code varchar(2000) NULL,
        id_user varchar(200) NULL,
        redeem_id VARCHAR(64) NULL
        SQL,
    'columns' => [
        ['redeem_id', null, 'VARCHAR(64) NULL'],
    ],
];
