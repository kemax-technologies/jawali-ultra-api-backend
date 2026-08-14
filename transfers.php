<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 💸 Jawali Ultra — API التحويلات المالية بين الأفراد (المرحلة 4 من إعادة التصميم)
 * تحويل أموال بكود استلام + عمولة + ربط اختياري بالصناديق/البنوك
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Endpoints:
 *   GET    /transfers.php                        — قائمة كل التحويلات (حتى 500)
 *   GET    /transfers.php?status=pending          — فلترة حسب الحالة
 *   GET    /transfers.php?code=XXXXXX             — البحث عن تحويل برمز الاستلام
 *   GET    /transfers.php?id=TR-XXX               — تحويل محدد
 *   POST   /transfers.php                         — إنشاء تحويل جديد (إرسال)
 *   POST   /transfers.php?action=complete         — صرف/استلام تحويل (بالرمز)
 *   POST   /transfers.php?action=cancel           — إلغاء تحويل معلّق (استرجاع المبلغ)
 *   DELETE /transfers.php?id=TR-XXX               — حذف سجل تحويل (مدير فقط)
 */

require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

/** توليد رمز استلام عشوائي مكوّن من 6 أرقام (غير مستخدم مسبقاً) */
function generate_receive_code(PDO $pdo, int $tenantId): string {
    do {
        $code = (string)random_int(100000, 999999);
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM money_transfers WHERE receive_code = ? AND status = 'pending' AND tenant_id = ?"
        );
        $stmt->execute([$code, $tenantId]);
        $exists = (int)$stmt->fetchColumn() > 0;
    } while ($exists);
    return $code;
}

switch ($method) {
    // ─────────────────────────────────────────────────────────────────────────
    // GET
    // ─────────────────────────────────────────────────────────────────────────
    case 'GET': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);

        // البحث عن تحويل معلّق برمز الاستلام (للتحقق قبل الصرف)
        if (!empty($_GET['code'])) {
            $stmt = $pdo->prepare(
                "SELECT * FROM money_transfers WHERE receive_code = ? AND status = 'pending' AND tenant_id = ? LIMIT 1"
            );
            $stmt->execute([$_GET['code'], $tenantId]);
            $tr = $stmt->fetch();
            if (!$tr) json_error('لم يُعثر على تحويل معلّق بهذا الرمز', 404);
            json_ok($tr);
        }

        // تحويل محدد بالمعرّف
        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT * FROM money_transfers WHERE id = ? AND tenant_id = ? LIMIT 1');
            $stmt->execute([$_GET['id'], $tenantId]);
            $tr = $stmt->fetch();
            if (!$tr) json_error('التحويل غير موجود', 404);
            json_ok($tr);
        }

        // قائمة كل التحويلات (مع فلتر اختياري بالحالة)
        $sql  = 'SELECT * FROM money_transfers WHERE tenant_id = ?';
        $args = [$tenantId];
        if (!empty($_GET['status'])) {
            $sql .= ' AND status = ?';
            $args[] = $_GET['status'];
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        json_ok($stmt->fetchAll());
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST — إنشاء تحويل جديد / صرف / إلغاء
    // ─────────────────────────────────────────────────────────────────────────
    case 'POST': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $action = $_GET['action'] ?? '';
        $body   = input_json();
        $userEmail = $_SERVER['HTTP_X_USER_EMAIL'] ?? null;

        // ── صرف/استلام تحويل معلّق بالرمز ────────────────────────────────────
        if ($action === 'complete') {
            $code = trim($body['code'] ?? $body['receive_code'] ?? '');
            $payoutAccountId = trim($body['payout_cash_account_id'] ?? $body['payoutCashAccountId'] ?? '');
            if ($code === '') json_error('رمز الاستلام مطلوب');

            $stmt = $pdo->prepare(
                "SELECT * FROM money_transfers WHERE receive_code = ? AND status = 'pending' AND tenant_id = ? LIMIT 1"
            );
            $stmt->execute([$code, $tenantId]);
            $tr = $stmt->fetch();
            if (!$tr) json_error('لم يُعثر على تحويل معلّق بهذا الرمز', 404);

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "UPDATE money_transfers
                     SET status = 'completed', completed_by = ?, completed_at = NOW(), payout_cash_account_id = ?
                     WHERE id = ? AND tenant_id = ?"
                )->execute([$userEmail, $payoutAccountId ?: null, $tr['id'], $tenantId]);

                // إذا حُدِّد صندوق صرف، يُخصَم منه مبلغ التحويل الأساسي (بدون العمولة
                // لأن العمولة إيراد وليست جزءاً من المبلغ المطلوب صرفه للمستلم)
                if ($payoutAccountId !== '') {
                    $accStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? AND tenant_id = ? LIMIT 1');
                    $accStmt->execute([$payoutAccountId, $tenantId]);
                    $acc = $accStmt->fetch();
                    if ($acc) {
                        // 🔧 إصلاح جوهري (فحص شامل لنظام الصناديق والبنوك):
                        // منع الصرف من صندوق بعملة مغايرة لعملة التحويل
                        // الأصلية — بدون هذا الفحص كان يُخصَم مبلغ حرفي
                        // (مثلاً 100) من صندوق بعملة مختلفة بلا أي تحويل
                        // بسعر الصرف، ما يُفسد رصيد الصندوق فوراً.
                        if ($acc['currency'] !== $tr['currency']) {
                            throw new Exception(
                                'عملة صندوق الصرف (' . $acc['currency'] . ') لا تطابق عملة التحويل (' . $tr['currency'] . ')'
                            );
                        }
                        if ((float)$acc['balance'] < (float)$tr['amount']) {
                            throw new Exception('الرصيد غير كافٍ في صندوق الصرف');
                        }
                        $pdo->prepare('UPDATE cash_accounts SET balance = balance - ? WHERE id = ? AND tenant_id = ?')
                            ->execute([$tr['amount'], $payoutAccountId, $tenantId]);
                        $txId = 'TX-' . round(microtime(true) * 1000);
                        $pdo->prepare(
                            'INSERT INTO cash_transactions (id, tenant_id, account_id, type, amount, currency, notes, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                        )->execute([
                            $txId, $tenantId, $payoutAccountId, 'سحب', $tr['amount'], $tr['currency'],
                            'صرف حوالة رمز ' . $code, $userEmail,
                        ]);
                    }
                }

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('[Jawali][transfers] فشل الصرف: ' . $e->getMessage());
                json_error('فشل تنفيذ عملية الصرف', 500);
            }

            audit("transfer completed {$tr['id']} code=$code", null, 'info', $tenantId);
            json_ok(['success' => true, 'id' => $tr['id']]);
            break;
        }

        // ── إلغاء تحويل معلّق (استرجاع المبلغ لصندوق المصدر إن وُجد) ─────────
        if ($action === 'cancel') {
            $id = trim($body['id'] ?? '');
            if ($id === '') json_error('id مطلوب');

            $stmt = $pdo->prepare("SELECT * FROM money_transfers WHERE id = ? AND status = 'pending' AND tenant_id = ? LIMIT 1");
            $stmt->execute([$id, $tenantId]);
            $tr = $stmt->fetch();
            if (!$tr) json_error('التحويل غير موجود أو ليس معلّقاً', 404);

            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE money_transfers SET status = 'cancelled', completed_by = ?, completed_at = NOW() WHERE id = ? AND tenant_id = ?")
                    ->execute([$userEmail, $id, $tenantId]);

                // 🔧 إصلاح جوهري خطير (فحص شامل لنظام الصناديق والبنوك):
                // عند إنشاء التحويل يُضاف "total" لصندوق الإرسال لأن العميل
                // سلّم نقداً فعلياً (balance += total، انظر أسفل عند الإنشاء).
                // الإلغاء يعني إرجاع هذا النقد للعميل — أي إخراجه من الصندوق
                // (balance -= total)، وليس إضافته مجدداً. الكود السابق كان
                // يستخدم "+" هنا بالخطأ، ما يجعل كل عملية إلغاء "تُضاعف"
                // رصيد الصندوق بدل عكس الحركة الأصلية — يُفسد الرصيد الفعلي
                // بشكل تراكمي مع كل إلغاء. لاحظ أن معالج DELETE أدناه (لنفس
                // السيناريو تماماً: تحويل معلّق له cash_account_id) يستخدم
                // "balance - total" بشكل صحيح، ما يؤكد أن "+" هنا كان خطأً.
                if (!empty($tr['cash_account_id'])) {
                    $pdo->prepare('UPDATE cash_accounts SET balance = balance - ? WHERE id = ? AND tenant_id = ?')
                        ->execute([$tr['total'], $tr['cash_account_id'], $tenantId]);
                    $txId = 'TX-' . round(microtime(true) * 1000);
                    $pdo->prepare(
                        'INSERT INTO cash_transactions (id, tenant_id, account_id, type, amount, currency, notes, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $txId, $tenantId, $tr['cash_account_id'], 'سحب', $tr['total'], $tr['currency'],
                        'استرجاع نقدي لحوالة ملغاة ' . $id, $userEmail,
                    ]);
                }

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('[Jawali][transfers] فشل الإلغاء: ' . $e->getMessage());
                json_error('فشل تنفيذ الإلغاء', 500);
            }

            audit("transfer cancelled $id", null, 'info', $tenantId);
            json_ok(['success' => true, 'id' => $id]);
            break;
        }

        // ── إنشاء تحويل جديد (إرسال) ─────────────────────────────────────────
        $senderName    = trim($body['senderName']    ?? $body['sender_name']    ?? '');
        $senderPhone   = trim($body['senderPhone']   ?? $body['sender_phone']   ?? '');
        $receiverName  = trim($body['receiverName']  ?? $body['receiver_name']  ?? '');
        $receiverPhone = trim($body['receiverPhone'] ?? $body['receiver_phone'] ?? '');
        $amount        = (float)($body['amount'] ?? 0);
        $commission    = (float)($body['commission'] ?? 0);
        $currency      = $body['currency'] ?? 'YER';
        $cashAccountId = trim($body['cashAccountId'] ?? $body['cash_account_id'] ?? '');
        $notes         = $body['notes'] ?? '';

        if ($senderName === '' || $senderPhone === '' || $receiverName === '' || $receiverPhone === '') {
            json_error('بيانات المرسل والمستلم مطلوبة بالكامل');
        }
        if ($amount <= 0) json_error('المبلغ يجب أن يكون أكبر من صفر');

        $total = $amount + $commission;
        $id    = 'TR-' . round(microtime(true) * 1000);
        $code  = generate_receive_code($pdo, $tenantId);

        $pdo->beginTransaction();
        try {
            // إذا حُدِّد صندوق إرسال، يُضاف إليه المبلغ الإجمالي (المبلغ + العمولة)
            // كإيداع فوري (المرسل يدفع نقداً في نقطة الخدمة)
            if ($cashAccountId !== '') {
                $accStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? AND tenant_id = ? LIMIT 1');
                $accStmt->execute([$cashAccountId, $tenantId]);
                $acc = $accStmt->fetch();
                if (!$acc) throw new Exception('صندوق الاستلام غير موجود');
                // 🔧 إصلاح جوهري (فحص شامل لنظام الصناديق والبنوك): منع
                // إنشاء تحويل بعملة تخالف عملة صندوق الاستلام المرتبط —
                // نفس نمط الثغرة في vouchers.php المُوثَّقة سابقاً.
                if ($acc['currency'] !== $currency) {
                    throw new Exception(
                        'عملة التحويل (' . $currency . ') لا تطابق عملة الصندوق (' . $acc['currency'] . ')'
                    );
                }
                $pdo->prepare('UPDATE cash_accounts SET balance = balance + ? WHERE id = ? AND tenant_id = ?')
                    ->execute([$total, $cashAccountId, $tenantId]);
                $txId = 'TX-' . round(microtime(true) * 1000);
                $pdo->prepare(
                    'INSERT INTO cash_transactions (id, tenant_id, account_id, type, amount, currency, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $txId, $tenantId, $cashAccountId, 'إيداع', $total, $currency,
                    "استلام حوالة صادرة $id", $_SERVER['HTTP_X_USER_EMAIL'] ?? null,
                ]);
            }

            $pdo->prepare(
                'INSERT INTO money_transfers
                    (id, tenant_id, sender_name, sender_phone, receiver_name, receiver_phone,
                     amount, commission, total, currency, cash_account_id, receive_code,
                     status, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $id, $tenantId, $senderName, $senderPhone, $receiverName, $receiverPhone,
                $amount, $commission, $total, $currency, $cashAccountId ?: null, $code,
                'pending', $notes, $_SERVER['HTTP_X_USER_EMAIL'] ?? null,
            ]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][transfers] فشل إنشاء التحويل: ' . $e->getMessage());
            json_error('فشل إنشاء التحويل', 500);
        }

        audit("create transfer $id amount=$amount commission=$commission code=$code", null, 'info', $tenantId);
        json_ok(['success' => true, 'id' => $id, 'receive_code' => $code]);
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE — حذف سجل تحويل (للمدير فقط، لأغراض تنظيف البيانات فقط)
    // 🔧 إصلاح (فحص شامل لنظام الصناديق والبنوك): كان الحذف يزيل السجل مباشرة
    // بدون عكس أي أثر مالي — إن كان التحويل لا يزال pending وله
    // cash_account_id (مبلغ مودَع فعلياً عند الإنشاء)، رصيد ذلك الصندوق كان
    // يبقى أعلى من الصحيح بشكل دائم. الآن: نعكس total عن الصندوق أولاً
    // (بنفس منطق action=cancel)، ثم نحذف السجل — كل ذلك ضمن معاملة واحدة.
    // ─────────────────────────────────────────────────────────────────────────
    case 'DELETE': {
        $auth = require_admin();
        $tenantId = tenant_id_from_auth($auth);
        $id = $_GET['id'] ?? '';
        if ($id === '') json_error('id مطلوب');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM money_transfers WHERE id = ? AND tenant_id = ? LIMIT 1');
            $stmt->execute([$id, $tenantId]);
            $tr = $stmt->fetch();
            if (!$tr) {
                $pdo->rollBack();
                json_error('التحويل غير موجود في متجرك', 404);
            }

            if ($tr['status'] === 'pending' && !empty($tr['cash_account_id'])) {
                $pdo->prepare('UPDATE cash_accounts SET balance = balance - ? WHERE id = ? AND tenant_id = ?')
                    ->execute([$tr['total'], $tr['cash_account_id'], $tenantId]);
                $txId = 'TX-' . round(microtime(true) * 1000);
                $pdo->prepare(
                    'INSERT INTO cash_transactions (id, tenant_id, account_id, type, amount, currency, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $txId, $tenantId, $tr['cash_account_id'], 'عكس حوالة محذوفة', $tr['total'], $tr['currency'],
                    'حذف سجل حوالة معلّقة ' . $id, $_SERVER['HTTP_X_USER_EMAIL'] ?? null,
                ]);
            }

            $del = $pdo->prepare('DELETE FROM money_transfers WHERE id = ? AND tenant_id = ?');
            $del->execute([$id, $tenantId]);
            if ($del->rowCount() === 0) {
                $pdo->rollBack();
                json_error('التحويل غير موجود في متجرك', 404);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][transfers] فشل حذف السجل: ' . $e->getMessage());
            json_error('خطأ داخلي في الخادم', 500);
        }

        audit("delete transfer $id", null, 'warning', $tenantId);
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
