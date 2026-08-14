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
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);

        // كل الحركات (تحويلات/إيداعات/سحوبات)
        if (isset($_GET['transactions'])) {
            $sql  = 'SELECT * FROM cash_transactions WHERE tenant_id = ?';
            $args = [$tenantId];
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
            $stmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? AND tenant_id = ? LIMIT 1');
            $stmt->execute([$_GET['id'], $tenantId]);
            $acc = $stmt->fetch();
            if (!$acc) json_error('الحساب غير موجود', 404);
            $tx = $pdo->prepare(
                'SELECT * FROM cash_transactions WHERE account_id = ? AND tenant_id = ? ORDER BY created_at DESC LIMIT 100'
            );
            $tx->execute([$_GET['id'], $tenantId]);
            $acc['transactions'] = $tx->fetchAll();
            json_ok($acc);
        }

        // قائمة كل الحسابات
        $stmt = $pdo->prepare(
            "SELECT * FROM cash_accounts WHERE is_active = TRUE AND tenant_id = ? ORDER BY created_at ASC"
        );
        $stmt->execute([$tenantId]);
        json_ok($stmt->fetchAll());
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST — إنشاء/تحديث حساب، أو تحويل/إيداع
    // ─────────────────────────────────────────────────────────────────────────
    case 'POST': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
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

            // فحص أولي سريع خارج المعاملة (تجربة مستخدم فقط — رسالة خطأ سريعة
            // إن كان الحساب غير موجود أو الرصيد غير كافٍ في الحالة العادية).
            // الفحص الحاسم الفعلي (قفل الصف + إعادة التحقق) يتم داخل المعاملة.
            $fromStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? AND tenant_id = ? LIMIT 1');
            $fromStmt->execute([$fromId, $tenantId]);
            $from = $fromStmt->fetch();
            if (!$from) json_error('الحساب المصدر غير موجود', 404);
            if ((float)$from['balance'] < $amount) {
                json_error('الرصيد غير كافٍ في الحساب المصدر');
            }

            $toStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? AND tenant_id = ? LIMIT 1');
            $toStmt->execute([$toId, $tenantId]);
            $to = $toStmt->fetch();
            if (!$to) json_error('الحساب الهدف غير موجود', 404);

            // 🔧 إصلاح جوهري (فحص شامل لنظام الصناديق والبنوك): منع التحويل
            // بين حسابين بعملتين مختلفتين بدون سعر صرف — طبقة حماية خادم
            // مطابقة للحارس المُضاف مسبقاً على مستوى العميل في
            // AppStore.transferFunds().
            if ($from['currency'] !== $to['currency']) {
                json_error(
                    'لا يمكن التحويل بين حسابين بعملتين مختلفتين (' . $from['currency'] . ' → ' . $to['currency'] . ')'
                );
            }

            $pdo->beginTransaction();
            try {
                // 🔧 إصلاح جوهري خطير (فحص شامل لنظام الصناديق والبنوك — منع
                // الازدواجية/التعارض في البيانات): كان فحص كفاية الرصيد يتم
                // بـ SELECT عادي *قبل* بدء المعاملة بلا قفل صف (FOR UPDATE)،
                // وجملة UPDATE اللاحقة "balance = balance - ?" تُنفَّذ بلا أي
                // شرط على الرصيد الحالي. النتيجة: طلبا تحويل متزامنان من نفس
                // الحساب المصدر (رصيده يكفي لتحويل واحد فقط) يمكن أن يجتازا
                // كلاهما الفحص الأولي معاً قبل أن يُنهي أيٌّ منهما معاملته،
                // فيُخصَم المبلغ *مرتين* ويصبح رصيد الحساب سالباً بشكل غير
                // صحيح (سحب مزدوج / ازدواجية مالية حقيقية). الإصلاح: قفل صف
                // الحساب المصدر بـ SELECT ... FOR UPDATE داخل المعاملة، إعادة
                // التحقق من كفاية الرصيد على القيمة "الطازجة" بعد القفل، ثم
                // تنفيذ UPDATE بشرط "balance >= amount" صراحة في WHERE مع فحص
                // rowCount() كحماية أخيرة (defense in depth) — فإن نفّذ طلب
                // مزامن آخر الخصم أولاً وأصبح الرصيد غير كافٍ، لن يُحدَّث أي
                // صف ويُرفض الطلب الثاني بأمان بدل تنفيذه خطأً.
                $lockStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? AND tenant_id = ? LIMIT 1 FOR UPDATE');
                $lockStmt->execute([$fromId, $tenantId]);
                $freshFrom = $lockStmt->fetch();
                if (!$freshFrom || (float)$freshFrom['balance'] < $amount) {
                    throw new Exception('الرصيد غير كافٍ في الحساب المصدر');
                }

                $outUpd = $pdo->prepare(
                    'UPDATE cash_accounts SET balance = balance - ? WHERE id = ? AND tenant_id = ? AND balance >= ?'
                );
                $outUpd->execute([$amount, $fromId, $tenantId, $amount]);
                if ($outUpd->rowCount() === 0) {
                    throw new Exception('الرصيد غير كافٍ في الحساب المصدر');
                }
                $pdo->prepare('UPDATE cash_accounts SET balance = balance + ? WHERE id = ? AND tenant_id = ?')
                    ->execute([$amount, $toId, $tenantId]);

                $txOutId = 'TX-' . round(microtime(true) * 1000) . '-OUT';
                $pdo->prepare(
                    'INSERT INTO cash_transactions (id, account_id, type, amount, currency, related_account_id, notes, created_by, tenant_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $txOutId, $fromId, 'تحويل صادر', $amount, $from['currency'], $toId, $notes,
                    $_SERVER['HTTP_X_USER_EMAIL'] ?? null, $tenantId,
                ]);

                $txInId = 'TX-' . round(microtime(true) * 1000) . '-IN';
                $pdo->prepare(
                    'INSERT INTO cash_transactions (id, account_id, type, amount, currency, related_account_id, notes, created_by, tenant_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $txInId, $toId, 'تحويل وارد', $amount, $to['currency'], $fromId, $notes,
                    $_SERVER['HTTP_X_USER_EMAIL'] ?? null, $tenantId,
                ]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('[Jawali][cashboxes] فشل التحويل: ' . $e->getMessage());
                json_error('فشل تنفيذ التحويل', 500);
            }

            audit("cash transfer $fromId -> $toId amount=$amount", null, 'info', $tenantId);
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

            // فحص أولي سريع خارج المعاملة (تجربة مستخدم فقط). الفحص الحاسم
            // الفعلي (قفل الصف + إعادة التحقق) يتم أدناه داخل المعاملة.
            $accStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? AND tenant_id = ? LIMIT 1');
            $accStmt->execute([$accId, $tenantId]);
            $acc = $accStmt->fetch();
            if (!$acc) json_error('الحساب غير موجود', 404);
            if ($type === 'سحب' && (float)$acc['balance'] < $amount) {
                json_error('الرصيد غير كافٍ');
            }

            $txId = 'TX-' . round(microtime(true) * 1000);

            // 🔧 إصلاح جوهري خطير (فحص شامل لنظام الصناديق والبنوك — منع
            // الازدواجية/التعارض في البيانات): كانت هذه العملية بأكملها بلا
            // beginTransaction/commit إطلاقاً — أي أن UPDATE (تحديث الرصيد)
            // وINSERT (تسجيل الحركة) عمليتان منفصلتان تماماً بلا التفاف ذرّي؛
            // فشل الـ INSERT بعد نجاح الـ UPDATE (مثلاً انقطاع اتصال) كان
            // يترك الرصيد مُحدَّثاً بدون أي سجل حركة يوثّقه — فقدان تكامل
            // بيانات. إضافة لذلك، فحص كفاية الرصيد للسحب كان يتم بلا قفل صف،
            // فطلبا سحب متزامنان (رصيد يكفي لأحدهما فقط) كانا يمكن أن ينجحا
            // معاً ويُصبح الرصيد سالباً. الإصلاح: تغليف الخصم/الإضافة والتسجيل
            // بمعاملة واحدة، مع قفل صف الحساب (FOR UPDATE) وإعادة التحقق من
            // كفاية الرصيد قبل السحب تحديداً، وأخيراً شرط "balance >= amount"
            // صريح في WHERE الخاص بـ UPDATE السحب (دفاع أخير عبر rowCount()).
            $pdo->beginTransaction();
            try {
                $lockStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? AND tenant_id = ? LIMIT 1 FOR UPDATE');
                $lockStmt->execute([$accId, $tenantId]);
                $freshAcc = $lockStmt->fetch();
                if (!$freshAcc) throw new Exception('الحساب غير موجود');

                if ($type === 'سحب') {
                    if ((float)$freshAcc['balance'] < $amount) {
                        throw new Exception('الرصيد غير كافٍ');
                    }
                    $upd = $pdo->prepare(
                        'UPDATE cash_accounts SET balance = balance - ? WHERE id = ? AND tenant_id = ? AND balance >= ?'
                    );
                    $upd->execute([$amount, $accId, $tenantId, $amount]);
                    if ($upd->rowCount() === 0) throw new Exception('الرصيد غير كافٍ');
                } else {
                    $pdo->prepare('UPDATE cash_accounts SET balance = balance + ? WHERE id = ? AND tenant_id = ?')
                        ->execute([$amount, $accId, $tenantId]);
                }

                $pdo->prepare(
                    'INSERT INTO cash_transactions (id, account_id, type, amount, currency, notes, created_by, tenant_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $txId, $accId, $type, $amount, $freshAcc['currency'], $notes,
                    $_SERVER['HTTP_X_USER_EMAIL'] ?? null, $tenantId,
                ]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('[Jawali][cashboxes] فشل الإيداع/السحب: ' . $e->getMessage());
                json_error($e->getMessage() ?: 'فشل تنفيذ العملية', 500);
            }

            audit("cash $type on $accId amount=$amount", null, 'info', $tenantId);
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
                'INSERT INTO cash_accounts (id, name, type, currency, balance, account_number, bank_name, notes, tenant_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$id, $name, $type, $currency, $balance, $accountNumber, $bankName, $notes, $tenantId]);
            audit("create cash account $id ($name)", null, 'info', $tenantId);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE cash_accounts SET
                    name = ?, type = ?, currency = ?, account_number = ?, bank_name = ?, notes = ?
                 WHERE id = ? AND tenant_id = ?'
            );
            $stmt->execute([$name, $type, $currency, $accountNumber, $bankName, $notes, $id, $tenantId]);
            if ($stmt->rowCount() === 0) json_error('الحساب غير موجود في متجرك', 404);
            audit("update cash account $id ($name)", null, 'info', $tenantId);
        }
        json_ok(['success' => true, 'id' => $id]);
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE — حذف حساب (للمدير فقط)
    // ─────────────────────────────────────────────────────────────────────────
    case 'DELETE': {
        $auth = require_admin();
        $tenantId = tenant_id_from_auth($auth);
        $id = $_GET['id'] ?? '';
        if ($id === '') json_error('id مطلوب');
        $upd = $pdo->prepare('UPDATE cash_accounts SET is_active = FALSE WHERE id = ? AND tenant_id = ?');
        $upd->execute([$id, $tenantId]);
        if ($upd->rowCount() === 0) json_error('الحساب غير موجود في متجرك', 404);
        audit("deactivate cash account $id", null, 'warning', $tenantId);
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
