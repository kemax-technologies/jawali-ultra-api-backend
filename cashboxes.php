<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 💰 Jawali Ultra — API الصناديق والبنوك (المرحلة 3 من إعادة التصميم)
 * حسابات نقدية/بنكية متعددة + حركات تحويل/إيداع/سحب بينها
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Endpoints:
 *   GET    /cashboxes.php                       — قائمة كل الحسابات (صناديق+بنوك)
 *   GET    /cashboxes.php?id=CASH-XXX           — حساب محدد مع آخر حركاته
 *   GET    /cashboxes.php?transactions=1         — كل الحركات (لكل الحسابات)
 *   GET    /cashboxes.php?transactions=1&account_id=CASH-XXX  — حركات حساب محدد
 *   POST   /cashboxes.php                       — إنشاء/تحديث حساب (صندوق أو بنك)
 *   POST   /cashboxes.php?action=transfer       — تحويل بين حسابين
 *   POST   /cashboxes.php?action=deposit        — إيداع/سحب مباشر (حركة على حساب واحد)
 *   DELETE /cashboxes.php?id=CASH-XXX           — حذف حساب (مدير فقط)
 */

require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    // ─────────────────────────────────────────────────────────────────────────
    // GET
    // ─────────────────────────────────────────────────────────────────────────
    case 'GET': {
        require_auth();

        // كل الحركات (تحويلات/إيداعات/سحوبات)
        if (isset($_GET['transactions'])) {
            $sql  = 'SELECT * FROM cash_transactions WHERE 1=1';
            $args = [];
            if (!empty($_GET['account_id'])) {
                $sql .= ' AND account_id = ?';
                $args[] = $_GET['account_id'];
            }
            $sql .= ' ORDER BY created_at DESC LIMIT 500';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);
            json_ok($stmt->fetchAll());
        }

        // حساب محدد + آخر حركاته
        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? LIMIT 1');
            $stmt->execute([$_GET['id']]);
            $acc = $stmt->fetch();
            if (!$acc) json_error('الحساب غير موجود', 404);
            $tx = $pdo->prepare(
                'SELECT * FROM cash_transactions WHERE account_id = ? ORDER BY created_at DESC LIMIT 100'
            );
            $tx->execute([$_GET['id']]);
            $acc['transactions'] = $tx->fetchAll();
            json_ok($acc);
        }

        // قائمة كل الحسابات
        $stmt = $pdo->query(
            "SELECT * FROM cash_accounts WHERE is_active = TRUE ORDER BY created_at ASC"
        );
        json_ok($stmt->fetchAll());
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST — إنشاء/تحديث حساب، أو تحويل/إيداع
    // ─────────────────────────────────────────────────────────────────────────
    case 'POST': {
        require_auth();
        $action = $_GET['action'] ?? '';
        $body   = input_json();

        // ── تحويل بين حسابين ────────────────────────────────────────────────
        if ($action === 'transfer') {
            $fromId = trim($body['from_id'] ?? $body['fromId'] ?? '');
            $toId   = trim($body['to_id']   ?? $body['toId']   ?? '');
            $amount = (float)($body['amount'] ?? 0);
            $notes  = $body['notes'] ?? '';
            if ($fromId === '' || $toId === '' || $amount <= 0) {
                json_error('from_id و to_id و amount مطلوبة');
            }
            if ($fromId === $toId) json_error('لا يمكن التحويل لنفس الحساب');

            $fromStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? LIMIT 1');
            $fromStmt->execute([$fromId]);
            $from = $fromStmt->fetch();
            if (!$from) json_error('الحساب المصدر غير موجود', 404);
            if ((float)$from['balance'] < $amount) {
                json_error('الرصيد غير كافٍ في الحساب المصدر');
            }

            $toStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? LIMIT 1');
            $toStmt->execute([$toId]);
            $to = $toStmt->fetch();
            if (!$to) json_error('الحساب الهدف غير موجود', 404);

            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE cash_accounts SET balance = balance - ? WHERE id = ?')
                    ->execute([$amount, $fromId]);
                $pdo->prepare('UPDATE cash_accounts SET balance = balance + ? WHERE id = ?')
                    ->execute([$amount, $toId]);

                $txOutId = 'TX-' . round(microtime(true) * 1000) . '-OUT';
                $pdo->prepare(
                    'INSERT INTO cash_transactions (id, account_id, type, amount, currency, related_account_id, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $txOutId, $fromId, 'تحويل صادر', $amount, $from['currency'], $toId, $notes,
                    $_SERVER['HTTP_X_USER_EMAIL'] ?? null,
                ]);

                $txInId = 'TX-' . round(microtime(true) * 1000) . '-IN';
                $pdo->prepare(
                    'INSERT INTO cash_transactions (id, account_id, type, amount, currency, related_account_id, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $txInId, $toId, 'تحويل وارد', $amount, $to['currency'], $fromId, $notes,
                    $_SERVER['HTTP_X_USER_EMAIL'] ?? null,
                ]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('[Jawali][cashboxes] فشل التحويل: ' . $e->getMessage());
                json_error('فشل تنفيذ التحويل', 500);
            }

            audit("cash transfer $fromId -> $toId amount=$amount");
            json_ok(['success' => true, 'from_id' => $fromId, 'to_id' => $toId]);
            break;
        }

        // ── إيداع/سحب مباشر على حساب واحد ──────────────────────────────────
        if ($action === 'deposit') {
            $accId = trim($body['account_id'] ?? $body['accountId'] ?? '');
            $amount = (float)($body['amount'] ?? 0);
            $type  = $body['type'] ?? 'إيداع'; // إيداع | سحب
            $notes = $body['notes'] ?? '';
            if ($accId === '' || $amount <= 0) json_error('account_id و amount مطلوبة');

            $accStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? LIMIT 1');
            $accStmt->execute([$accId]);
            $acc = $accStmt->fetch();
            if (!$acc) json_error('الحساب غير موجود', 404);

            $delta = ($type === 'سحب') ? -$amount : $amount;
            if ($type === 'سحب' && (float)$acc['balance'] < $amount) {
                json_error('الرصيد غير كافٍ');
            }

            $pdo->prepare('UPDATE cash_accounts SET balance = balance + ? WHERE id = ?')
                ->execute([$delta, $accId]);

            $txId = 'TX-' . round(microtime(true) * 1000);
            $pdo->prepare(
                'INSERT INTO cash_transactions (id, account_id, type, amount, currency, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $txId, $accId, $type, $amount, $acc['currency'], $notes,
                $_SERVER['HTTP_X_USER_EMAIL'] ?? null,
            ]);

            audit("cash $type on $accId amount=$amount");
            json_ok(['success' => true, 'id' => $txId]);
            break;
        }

        // ── إنشاء/تحديث حساب (صندوق أو بنك) ─────────────────────────────────
        $id       = trim($body['id'] ?? '');
        $name     = trim($body['name'] ?? '');
        if ($name === '') json_error('اسم الحساب مطلوب');
        $isNew = $id === '';
        if ($isNew) $id = 'CASH-' . round(microtime(true) * 1000);

        $type          = $body['type']          ?? 'نقدي'; // نقدي | بنك
        $currency      = $body['currency']      ?? 'YER';
        $balance       = (float)($body['balance'] ?? $body['openingBalance'] ?? 0);
        $accountNumber = $body['accountNumber'] ?? $body['account_number'] ?? null;
        $bankName      = $body['bankName']      ?? $body['bank_name']      ?? null;
        $notes         = $body['notes'] ?? null;

        if ($isNew) {
            $stmt = $pdo->prepare(
                'INSERT INTO cash_accounts (id, name, type, currency, balance, account_number, bank_name, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$id, $name, $type, $currency, $balance, $accountNumber, $bankName, $notes]);
            audit("create cash account $id ($name)");
        } else {
            $stmt = $pdo->prepare(
                'UPDATE cash_accounts SET
                    name = ?, type = ?, currency = ?, account_number = ?, bank_name = ?, notes = ?
                 WHERE id = ?'
            );
            $stmt->execute([$name, $type, $currency, $accountNumber, $bankName, $notes, $id]);
            audit("update cash account $id ($name)");
        }
        json_ok(['success' => true, 'id' => $id]);
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE — حذف حساب (للمدير فقط)
    // ─────────────────────────────────────────────────────────────────────────
    case 'DELETE': {
        require_admin();
        $id = $_GET['id'] ?? '';
        if ($id === '') json_error('id مطلوب');
        $pdo->prepare('UPDATE cash_accounts SET is_active = FALSE WHERE id = ?')->execute([$id]);
        audit("deactivate cash account $id", null, 'warning');
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
