<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 📒 Jawali Ultra — API دليل الحسابات والقيود اليومية (المرحلتان 7 و 9)
 * قيد مزدوج حقيقي: كل قيد يومية يجب أن يتساوى فيه مجموع المدين مع مجموع الدائن.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Endpoints:
 *   GET    /accounting.php?coa=1                       — دليل الحسابات كاملاً
 *   GET    /accounting.php?coa=1&id=COA-XXX             — حساب محدد
 *   GET    /accounting.php?statement=1&account_id=X     — كشف حساب (كل القيود + رصيد متحرك)
 *   GET    /accounting.php                              — قائمة القيود اليومية (رؤوس فقط)
 *   GET    /accounting.php?id=JE-XXX                    — قيد محدد مع سطوره
 *   POST   /accounting.php?coa=1                        — إنشاء/تحديث حساب في الدليل
 *   POST   /accounting.php                              — إنشاء قيد يومية جديد (lines[])
 *   DELETE /accounting.php?coa=1&id=COA-XXX             — تعطيل حساب (مدير فقط)
 *   DELETE /accounting.php?id=JE-XXX                    — إلغاء قيد (مدير فقط، status=void)
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

        // دليل الحسابات
        if (isset($_GET['coa'])) {
            if (!empty($_GET['id'])) {
                $stmt = $pdo->prepare('SELECT * FROM chart_of_accounts WHERE id = ? LIMIT 1');
                $stmt->execute([$_GET['id']]);
                json_ok($stmt->fetch() ?: []);
            }
            $stmt = $pdo->query(
                'SELECT * FROM chart_of_accounts WHERE is_active = TRUE ORDER BY code ASC'
            );
            json_ok($stmt->fetchAll());
        }

        // كشف حساب: كل سطور القيود المرتبطة بحساب معيّن + رصيد متحرك
        if (isset($_GET['statement'])) {
            $accountId = $_GET['account_id'] ?? '';
            if ($accountId === '') json_error('account_id مطلوب');

            $accStmt = $pdo->prepare('SELECT * FROM chart_of_accounts WHERE id = ? LIMIT 1');
            $accStmt->execute([$accountId]);
            $acc = $accStmt->fetch();
            if (!$acc) json_error('الحساب غير موجود', 404);

            $stmt = $pdo->prepare(
                'SELECT jel.id, jel.debit, jel.credit, jel.notes,
                        je.id AS entry_id, je.entry_number, je.date, je.description, je.reference
                 FROM journal_entry_lines jel
                 JOIN journal_entries je ON je.id = jel.entry_id
                 WHERE jel.account_id = ? AND je.status != \'void\'
                 ORDER BY je.date ASC, jel.id ASC'
            );
            $stmt->execute([$accountId]);
            $lines = $stmt->fetchAll();

            $balance = (float)$acc['opening_balance'];
            $isDebitNormal = in_array($acc['type'], ['asset', 'expense'], true);
            foreach ($lines as &$l) {
                $delta = $isDebitNormal
                    ? ((float)$l['debit'] - (float)$l['credit'])
                    : ((float)$l['credit'] - (float)$l['debit']);
                $balance += $delta;
                $l['running_balance'] = $balance;
            }
            unset($l);

            json_ok([
                'account'         => $acc,
                'opening_balance' => (float)$acc['opening_balance'],
                'closing_balance' => $balance,
                'lines'           => $lines,
            ]);
        }

        // قيد محدد مع سطوره
        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT * FROM journal_entries WHERE id = ? LIMIT 1');
            $stmt->execute([$_GET['id']]);
            $entry = $stmt->fetch();
            if (!$entry) json_error('القيد غير موجود', 404);
            $lStmt = $pdo->prepare(
                'SELECT jel.*, coa.name AS account_name, coa.code AS account_code
                 FROM journal_entry_lines jel
                 JOIN chart_of_accounts coa ON coa.id = jel.account_id
                 WHERE jel.entry_id = ? ORDER BY jel.id ASC'
            );
            $lStmt->execute([$_GET['id']]);
            $entry['lines'] = $lStmt->fetchAll();
            json_ok($entry);
        }

        // قائمة رؤوس القيود
        $stmt = $pdo->query('SELECT * FROM journal_entries ORDER BY date DESC LIMIT 500');
        json_ok($stmt->fetchAll());
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST
    // ─────────────────────────────────────────────────────────────────────────
    case 'POST': {
        require_auth();
        $body = input_json();

        // ── إنشاء/تحديث حساب في الدليل ──────────────────────────────────────
        if (isset($_GET['coa'])) {
            $id   = trim($body['id'] ?? '');
            $code = trim($body['code'] ?? '');
            $name = trim($body['name'] ?? '');
            if ($code === '' || $name === '') json_error('code و name مطلوبان');
            $isNew = $id === '';
            if ($isNew) $id = 'COA-' . $code;

            $type = $body['type'] ?? 'asset';
            $parentId = trim($body['parent_id'] ?? $body['parentId'] ?? '');
            $opening = (float)($body['opening_balance'] ?? $body['openingBalance'] ?? 0);
            $notes = $body['notes'] ?? '';

            if ($isNew) {
                $stmt = $pdo->prepare(
                    'INSERT INTO chart_of_accounts (id, code, name, type, parent_id, opening_balance, notes)
                     VALUES (?, ?, ?, ?, NULLIF(?, \'\'), ?, ?)'
                );
                $stmt->execute([$id, $code, $name, $type, $parentId, $opening, $notes]);
                audit("create COA account $id ($name)");
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE chart_of_accounts SET
                        code = ?, name = ?, type = ?, parent_id = NULLIF(?, \'\'), notes = ?
                     WHERE id = ?'
                );
                $stmt->execute([$code, $name, $type, $parentId, $notes, $id]);
                audit("update COA account $id ($name)");
            }
            json_ok(['success' => true, 'id' => $id]);
            break;
        }

        // ── إنشاء قيد يومية (قيد مزدوج) ──────────────────────────────────────
        $description = trim($body['description'] ?? '');
        $reference   = $body['reference'] ?? '';
        $lines       = $body['lines'] ?? [];
        $date        = $body['date'] ?? null;

        if (!is_array($lines) || count($lines) < 2) {
            json_error('يجب إدخال سطرين على الأقل (مدين ودائن)');
        }

        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($lines as $l) {
            $totalDebit  += (float)($l['debit']  ?? 0);
            $totalCredit += (float)($l['credit'] ?? 0);
        }
        if (abs($totalDebit - $totalCredit) > 0.01) {
            json_error(
                'القيد غير متوازن: مجموع المدين (' . $totalDebit .
                ') لا يساوي مجموع الدائن (' . $totalCredit . ')'
            );
        }
        if ($totalDebit <= 0) json_error('لا يمكن إنشاء قيد بقيمة صفرية');

        $id = 'JE-' . round(microtime(true) * 1000);
        $entryNumber = $body['entry_number'] ?? $body['entryNumber'] ?? ('JE-' . substr($id, -6));

        try {
            $pdo->beginTransaction();

            $ins = $pdo->prepare(
                'INSERT INTO journal_entries (id, entry_number, date, description, reference, status, created_by)
                 VALUES (?, ?, COALESCE(NULLIF(?, \'\')::timestamp, NOW()), ?, ?, \'posted\', ?)'
            );
            $ins->execute([
                $id, $entryNumber, $date, $description, $reference,
                $_SERVER['HTTP_X_USER_EMAIL'] ?? '',
            ]);

            foreach ($lines as $i => $l) {
                $accountId = trim($l['account_id'] ?? $l['accountId'] ?? '');
                if ($accountId === '') {
                    $pdo->rollBack();
                    json_error('account_id مطلوب لكل سطر');
                }
                $lineId = $id . '-L' . ($i + 1);
                $pdo->prepare(
                    'INSERT INTO journal_entry_lines (id, entry_id, account_id, debit, credit, notes)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $lineId, $id, $accountId,
                    (float)($l['debit'] ?? 0), (float)($l['credit'] ?? 0),
                    $l['notes'] ?? '',
                ]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][accounting] فشل إنشاء القيد: ' . $e->getMessage());
            json_error('خطأ داخلي في الخادم', 500);
        }

        audit("create journal entry $id total=$totalDebit");
        json_ok(['success' => true, 'id' => $id, 'entry_number' => $entryNumber]);
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE
    // ─────────────────────────────────────────────────────────────────────────
    case 'DELETE': {
        require_admin();

        if (isset($_GET['coa'])) {
            $id = $_GET['id'] ?? '';
            if ($id === '') json_error('id مطلوب');
            $pdo->prepare('UPDATE chart_of_accounts SET is_active = FALSE WHERE id = ?')->execute([$id]);
            audit("deactivate COA account $id", null, 'warning');
            json_ok(['success' => true]);
            break;
        }

        $id = $_GET['id'] ?? '';
        if ($id === '') json_error('id مطلوب');
        $pdo->prepare("UPDATE journal_entries SET status = 'void' WHERE id = ?")->execute([$id]);
        audit("void journal entry $id", null, 'warning');
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
