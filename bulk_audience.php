<?php

/** Shared recipient rules for broadcasts and bulk wallet credit. */
function bulkEnsureExpirySchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    foreach (['expires_at', 'depleted_at'] as $column) {
        $stmt = $pdo->query("SHOW COLUMNS FROM invoice LIKE '$column'");
        if ($stmt->fetch() === false) {
            try {
                $pdo->exec("ALTER TABLE invoice ADD $column BIGINT UNSIGNED NULL");
            } catch (PDOException $e) {
                if ($pdo->query("SHOW COLUMNS FROM invoice LIKE '$column'")->fetch() === false) {
                    throw $e;
                }
            }
        }
    }
    $ready = true;
}

function bulkCacheInvoiceExpiry(PDO $pdo, string $sql, array $params): void
{
    try {
        bulkEnsureExpirySchema($pdo);
        $pdo->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        error_log('Invoice expiry cache update failed: ' . $e->getMessage());
    }
}

function bulkRefreshInvoiceExpiry(PDO $pdo, string $invoiceId, callable $readPanel): void
{
    try {
        $freshUser = $readPanel();
        if (is_array($freshUser) && is_numeric($freshUser['expire'] ?? null)) {
            bulkCacheInvoiceExpiry($pdo, 'UPDATE invoice SET expires_at = ? WHERE id_invoice = ?', [(int) $freshUser['expire'], $invoiceId]);
        }
    } catch (Throwable $e) {
        error_log('Panel expiry refresh failed: ' . $e->getMessage());
    }
}

function bulkParseDayRange(string $input): ?array
{
    $input = strtr($input, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    if (!preg_match('/^\s*(\d{1,4})\s*[-–]\s*(\d{1,4})\s*$/u', $input, $matches)) {
        return null;
    }
    $from = (int) $matches[1];
    $to = (int) $matches[2];
    return $from <= $to && $to <= 3650 ? [$from, $to] : null;
}

function bulkAudienceQuery(array $criteria, bool $activeOnly = true, ?int $now = null): array
{
    $now ??= time();
    $kind = $criteria['kind'] ?? 'all';
    if (!in_array($kind, ['all', 'customer', 'nonecustomer', 'expired_unrenewed'], true)) {
        throw new InvalidArgumentException('Invalid bulk audience');
    }
    $agent = $criteria['agent'] ?? 'all';
    if (!in_array($agent, ['all', 'f', 'n', 'n2'], true)) {
        throw new InvalidArgumentException('Invalid user group');
    }

    $conditions = [];
    $params = [];
    if ($activeOnly) {
        $conditions[] = "u.User_Status = 'Active'";
    }
    if ($agent !== 'all') {
        $conditions[] = 'u.agent = :audience_agent';
        $params[':audience_agent'] = $agent;
    }
    if ($kind === 'customer') {
        $panel = $criteria['panel'] ?? 'all';
        if ($panel === 'all') {
            $conditions[] = 'EXISTS (SELECT 1 FROM invoice ci WHERE ci.id_user = u.id)';
        } else {
            $conditions[] = 'EXISTS (SELECT 1 FROM invoice ci WHERE ci.id_user = u.id AND ci.Service_location = :audience_panel)';
            $params[':audience_panel'] = $panel;
        }
    } elseif ($kind === 'nonecustomer') {
        $conditions[] = 'NOT EXISTS (SELECT 1 FROM invoice ci WHERE ci.id_user = u.id)';
    } elseif ($kind === 'expired_unrenewed') {
        $from = (int) ($criteria['days_from'] ?? -1);
        $to = (int) ($criteria['days_to'] ?? -1);
        if ($from < 0 || $to < $from || $to > 3650) {
            throw new InvalidArgumentException('Invalid expiry day range');
        }
        $params[':expired_after'] = $now - (($to + 1) * 86400);
        $params[':expired_before'] = $now - ($from * 86400);
        $params[':active_now'] = $now;
        $effective = static fn(string $alias): string => "(CASE
            WHEN $alias.expires_at > 0 AND $alias.depleted_at > 0 THEN LEAST($alias.expires_at, $alias.depleted_at)
            WHEN $alias.expires_at > 0 THEN $alias.expires_at
            WHEN $alias.depleted_at > 0 THEN $alias.depleted_at
            ELSE NULL END)";
        $expiredAt = $effective('ei');
        $newerAt = $effective('newer');
        $activeAt = $effective('active_invoice');
        $conditions[] = "EXISTS (
            SELECT 1 FROM invoice ei
            WHERE ei.id_user = u.id
              AND $expiredAt > :expired_after
              AND $expiredAt <= :expired_before
              AND ei.Status NOT IN ('unpaid', 'reject', 'cancelled')
              AND NOT EXISTS (
                  SELECT 1 FROM invoice newer
                  WHERE newer.id_user = u.id
                    AND newer.Status NOT IN ('unpaid', 'reject', 'cancelled')
                    AND $newerAt > $expiredAt
              )
        )";
        // An unknown expiry is excluded until the panel monitor has synced it.
        $conditions[] = "NOT EXISTS (
            SELECT 1 FROM invoice active_invoice
            WHERE active_invoice.id_user = u.id
              AND active_invoice.Status IN ('active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold')
              AND ($activeAt IS NULL OR $activeAt > :active_now)
        )";
    }
    if (isset($criteria['inactive_before'])) {
        $conditions[] = 'CAST(u.last_message_time AS UNSIGNED) < :inactive_before';
        $params[':inactive_before'] = (int) $criteria['inactive_before'];
    }
    $sql = 'SELECT u.id FROM user u';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    return [$sql, $params];
}

function bulkAudienceIds(PDO $pdo, array $criteria, bool $activeOnly = true): array
{
    if (($criteria['kind'] ?? '') === 'expired_unrenewed') {
        bulkEnsureExpirySchema($pdo);
    }
    [$sql, $params] = bulkAudienceQuery($criteria, $activeOnly);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function bulkAudienceCount(PDO $pdo, array $criteria, bool $activeOnly = true): int
{
    if (($criteria['kind'] ?? '') === 'expired_unrenewed') {
        bulkEnsureExpirySchema($pdo);
    }
    [$sql, $params] = bulkAudienceQuery($criteria, $activeOnly);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM (' . $sql . ') audience');
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function bulkBroadcastUsesInactivityFilter(array $data): bool
{
    // Service expiry uses its own day range, regardless of the last bot interaction.
    return ($data['typeservice'] ?? '') === 'xdaynotmessage'
        && ($data['typeusermessage'] ?? '') !== 'expired_unrenewed';
}

function bulkBroadcastCriteria(PDO $pdo, array $data): array
{
    $criteria = [
        'kind' => $data['typeusermessage'] ?? '',
        'agent' => $data['agent'] ?? '',
    ];
    if ($criteria['kind'] === 'customer' && ($data['selectpanel'] ?? 'all') !== 'all') {
        $stmt = $pdo->prepare('SELECT name_panel FROM marzban_panel WHERE code_panel = ? LIMIT 1');
        $stmt->execute([$data['selectpanel']]);
        $criteria['panel'] = $stmt->fetchColumn();
        if (!$criteria['panel']) {
            throw new InvalidArgumentException('Panel not found');
        }
    }
    if ($criteria['kind'] === 'expired_unrenewed') {
        $criteria['days_from'] = $data['days_from'] ?? -1;
        $criteria['days_to'] = $data['days_to'] ?? -1;
    }
    if (bulkBroadcastUsesInactivityFilter($data)) {
        $days = filter_var($data['daynoyuse'] ?? null, FILTER_VALIDATE_INT);
        if ($days === false || $days < 0 || $days > 3650) {
            throw new InvalidArgumentException('Invalid inactive day count');
        }
        $criteria['inactive_before'] = time() - ($days * 86400);
    }
    return $criteria;
}
