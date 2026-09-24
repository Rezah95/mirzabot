<?php

function discountEnsureRedemptionSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    if ($pdo->query("SHOW COLUMNS FROM Giftcodeconsumed LIKE 'redeem_id'")->fetch() === false) {
        try {
            $pdo->exec('ALTER TABLE Giftcodeconsumed ADD redeem_id VARCHAR(64) NULL');
        } catch (PDOException $e) {
            if ($pdo->query("SHOW COLUMNS FROM Giftcodeconsumed LIKE 'redeem_id'")->fetch() === false) {
                throw $e;
            }
        }
    }
    $index = $pdo->query("SHOW INDEX FROM Giftcodeconsumed WHERE Key_name = 'uniq_gift_redeem_id'");
    if ($index->fetch() === false) {
        try {
            $pdo->exec('ALTER TABLE Giftcodeconsumed ADD UNIQUE INDEX uniq_gift_redeem_id (redeem_id)');
        } catch (PDOException $e) {
            if ($pdo->query("SHOW INDEX FROM Giftcodeconsumed WHERE Key_name = 'uniq_gift_redeem_id'")->fetch() === false) {
                throw $e;
            }
        }
    }
    $ready = true;
}

function discountCodeRow(PDO $pdo, string $code, bool $lock = false): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM DiscountSell WHERE codeDiscount = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$code]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return count($rows) === 1 ? $rows[0] : null;
}

function discountIsEligible(PDO $pdo, array $row, string $userId, string $agent, string $panelCode, string $productCode, string $purpose, string $testName, ?string $excludeInvoice = null): bool
{
    if (!in_array($purpose, ['buy', 'extend'], true)
        || !in_array($row['agent'], ['allusers', $agent], true)
        || !in_array($row['code_panel'], ['/all', $panelCode], true)
        || !in_array($row['code_product'], ['all', $productCode], true)
        || !in_array($row['type'], ['all', $purpose], true)) {
        return false;
    }
    $percent = filter_var($row['price'], FILTER_VALIDATE_INT);
    $limit = filter_var($row['limitDiscount'], FILTER_VALIDATE_INT);
    $perUser = filter_var($row['useuser'], FILTER_VALIDATE_INT);
    $used = filter_var($row['usedDiscount'], FILTER_VALIDATE_INT);
    $expires = filter_var($row['time'], FILTER_VALIDATE_INT);
    if ($percent === false || $percent < 1 || $percent > 100
        || $limit === false || $limit < 1 || $used === false || $used < 0 || $used >= $limit
        || $perUser === false || $perUser < 1 || $expires === false || $expires < 0) {
        return false;
    }
    if ($expires !== 0 && time() >= $expires) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM Giftcodeconsumed WHERE id_user = ? AND code = ?');
    $stmt->execute([$userId, $row['codeDiscount']]);
    if ((int) $stmt->fetchColumn() >= $perUser) {
        return false;
    }
    if ((int) $row['usefirst'] === 1) {
        $stmt = $pdo->prepare("SELECT 1 FROM invoice WHERE id_user = ? AND id_invoice <> ? AND name_product <> ? AND Status IN ('active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold') LIMIT 1");
        $stmt->execute([$userId, $excludeInvoice ?? '', $testName]);
        if ($stmt->fetchColumn() !== false) {
            return false;
        }
    }
    return true;
}

function discountPreview(PDO $pdo, string $code, string $userId, string $agent, string $panelCode, string $productCode, string $purpose, string $testName, ?string $excludeInvoice = null): ?array
{
    $row = discountCodeRow($pdo, $code);
    return $row && discountIsEligible($pdo, $row, $userId, $agent, $panelCode, $productCode, $purpose, $testName, $excludeInvoice) ? $row : null;
}

function discountConsume(PDO $pdo, string $redeemId, string $code, string $userId, string $agent, string $panelCode, string $productCode, string $purpose, string $testName, ?string $excludeInvoice = null, ?callable $charge = null, bool $allowExisting = false): bool
{
    discountEnsureRedemptionSchema($pdo);
    if (!preg_match('/^[a-f0-9]{32}$/', $redeemId)) {
        throw new InvalidArgumentException('Invalid discount redemption ID');
    }
    $pdo->beginTransaction();
    try {
        $row = discountCodeRow($pdo, $code, true);
        if (!$row) {
            $pdo->rollBack();
            return false;
        }
        $existing = $pdo->prepare('SELECT code, id_user FROM Giftcodeconsumed WHERE redeem_id = ?');
        $existing->execute([$redeemId]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
        if ($existingRow) {
            $pdo->commit();
            return $allowExisting && $existingRow['code'] === $code && (string) $existingRow['id_user'] === $userId;
        }
        if (!discountIsEligible($pdo, $row, $userId, $agent, $panelCode, $productCode, $purpose, $testName, $excludeInvoice)) {
            $pdo->rollBack();
            return false;
        }
        if ($charge !== null && !$charge()) {
            $pdo->rollBack();
            return false;
        }
        $pdo->prepare('INSERT INTO Giftcodeconsumed (id_user, code, redeem_id) VALUES (?, ?, ?)')
            ->execute([$userId, $row['codeDiscount'], $redeemId]);
        $pdo->prepare('UPDATE DiscountSell SET usedDiscount = usedDiscount + 1 WHERE id = ?')
            ->execute([$row['id']]);
        $pdo->commit();
        if (function_exists('clearSelectCache')) {
            clearSelectCache('user');
            clearSelectCache('DiscountSell');
            clearSelectCache('Giftcodeconsumed');
        }
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function discountRelease(PDO $pdo, string $redeemId): void
{
    $lookup = $pdo->prepare('SELECT code FROM Giftcodeconsumed WHERE redeem_id = ?');
    $lookup->execute([$redeemId]);
    $code = $lookup->fetchColumn();
    if ($code === false) {
        return;
    }
    $pdo->beginTransaction();
    try {
        $row = discountCodeRow($pdo, (string) $code, true);
        $stmt = $pdo->prepare('SELECT code FROM Giftcodeconsumed WHERE redeem_id = ? FOR UPDATE');
        $stmt->execute([$redeemId]);
        $lockedCode = $stmt->fetchColumn();
        if ($lockedCode !== false && $lockedCode === $code) {
            $pdo->prepare('DELETE FROM Giftcodeconsumed WHERE redeem_id = ?')->execute([$redeemId]);
            if ($row) {
                $pdo->prepare('UPDATE DiscountSell SET usedDiscount = GREATEST(0, usedDiscount - 1) WHERE id = ?')
                    ->execute([$row['id']]);
            }
        }
        $pdo->commit();
        if (function_exists('clearSelectCache')) {
            clearSelectCache('DiscountSell');
            clearSelectCache('Giftcodeconsumed');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function discountCreate(PDO $pdo, array $row): bool
{
    $code = strtolower((string) ($row['codeDiscount'] ?? ''));
    if (!preg_match('/^[a-z0-9]{1,40}$/', $code)
        || filter_var($row['price'] ?? null, FILTER_VALIDATE_INT) === false
        || (int) $row['price'] < 1 || (int) $row['price'] > 100
        || filter_var($row['limitDiscount'] ?? null, FILTER_VALIDATE_INT) === false
        || (int) $row['limitDiscount'] < 1
        || filter_var($row['useuser'] ?? null, FILTER_VALIDATE_INT) === false
        || (int) $row['useuser'] < 1 || (int) $row['useuser'] > (int) $row['limitDiscount']
        || filter_var($row['time'] ?? null, FILTER_VALIDATE_INT) === false
        || (int) $row['time'] < 0
        || !in_array($row['agent'] ?? null, ['f', 'n', 'n2', 'allusers'], true)
        || !in_array((string) ($row['usefirst'] ?? ''), ['0', '1'], true)
        || !in_array($row['type'] ?? null, ['buy', 'extend', 'all'], true)
        || empty($row['code_panel']) || empty($row['code_product'])) {
        throw new InvalidArgumentException('Invalid discount parameters');
    }
    $lockName = 'discount_sell_' . sha1($code);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $stmt->execute([$lockName]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Cannot lock discount code');
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM DiscountSell WHERE codeDiscount = ?');
        $stmt->execute([$code]);
        $gift = $pdo->prepare('SELECT COUNT(*) FROM Discount WHERE code = ?');
        $gift->execute([$code]);
        if ((int) $stmt->fetchColumn() !== 0 || (int) $gift->fetchColumn() !== 0) {
            return false;
        }
        $row['codeDiscount'] = $code;
        $row['usedDiscount'] = 0;
        $columns = array_keys($row);
        $sql = 'INSERT INTO DiscountSell (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $pdo->prepare($sql)->execute(array_values($row));
        return true;
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

function discountCreateGift(PDO $pdo, string $code, int $amount, int $limit): bool
{
    if (!preg_match('/^[A-Za-z0-9]{1,40}$/', $code) || $amount < 1 || $amount > 100000000 || $limit < 1) {
        throw new InvalidArgumentException('Invalid gift code parameters');
    }
    $lockName = 'discount_sell_' . sha1(strtolower($code));
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $stmt->execute([$lockName]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Cannot lock gift code');
    }
    try {
        $gift = $pdo->prepare('SELECT COUNT(*) FROM Discount WHERE code = ?');
        $gift->execute([$code]);
        $sale = $pdo->prepare('SELECT COUNT(*) FROM DiscountSell WHERE codeDiscount = ?');
        $sale->execute([$code]);
        if ((int) $gift->fetchColumn() !== 0 || (int) $sale->fetchColumn() !== 0) {
            return false;
        }
        $pdo->prepare('INSERT INTO Discount (code, price, limituse, limitused) VALUES (?, ?, ?, 0)')
            ->execute([$code, $amount, $limit]);
        return true;
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

function discountRedeemGift(PDO $pdo, string $code, string $userId): ?int
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM Discount WHERE code = ? FOR UPDATE');
        $stmt->execute([$code]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || (int) $rows[0]['limitused'] >= (int) $rows[0]['limituse']) {
            $pdo->rollBack();
            return null;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM Giftcodeconsumed WHERE id_user = ? AND code = ? LIMIT 1');
        $stmt->execute([$userId, $code]);
        if ($stmt->fetchColumn() !== false) {
            $pdo->rollBack();
            return null;
        }
        $amount = (int) $rows[0]['price'];
        if ($amount < 1 || $amount > 100000000) {
            $pdo->rollBack();
            return null;
        }
        $credit = $pdo->prepare('UPDATE user SET Balance = Balance + ? WHERE id = ? AND Balance <= ?');
        $credit->execute([$amount, $userId, 2147483647 - $amount]);
        if ($credit->rowCount() !== 1) {
            throw new RuntimeException('Gift credit failed');
        }
        $pdo->prepare('UPDATE Discount SET limitused = limitused + 1 WHERE id = ?')->execute([$rows[0]['id']]);
        $pdo->prepare('INSERT INTO Giftcodeconsumed (id_user, code) VALUES (?, ?)')->execute([$userId, $code]);
        $pdo->commit();
        if (function_exists('clearSelectCache')) {
            clearSelectCache('user');
            clearSelectCache('Discount');
            clearSelectCache('Giftcodeconsumed');
        }
        return $amount;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
