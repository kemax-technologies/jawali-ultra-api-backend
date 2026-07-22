<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Jawali Ultra — Notification Helper (Email + SMS)
 *
 * ملف مساعد موحّد لإرسال البريد الإلكتروني والرسائل القصيرة (SMS) الحقيقية.
 * يُستخدم من password_recovery.php (ومستقبلاً من أي نقطة نهاية أخرى تحتاج
 * إشعارات فعلية للمستخدم).
 *
 * ✅ البريد الإلكتروني: يدعم SendGrid API (إن كان SENDGRID_API_KEY مضبوطاً في
 *    .env)، وإلا يتراجع تلقائياً إلى دالة mail() المدمجة في PHP (تتطلب MTA
 *    مضبوطاً على الخادم) دون إفشال الطلب الأصلي أبداً.
 *
 * ✅ SMS: يدعم Twilio (إن كانت SMS_PROVIDER=twilio وباقي مفاتيح Twilio مضبوطة
 *    في .env حسب .env.example)، وإلا يسجّل الرسالة في error_log فقط (وضع
 *    تطوير/عدم توفر مزوّد) دون إفشال الطلب الأصلي.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ─── البريد الإلكتروني ──────────────────────────────────────────────────────

function _sendgrid_configured(): bool {
    return (getenv('SENDGRID_API_KEY') ?: '') !== '';
}

/**
 * إرسال بريد إلكتروني حقيقي. يحاول SendGrid أولاً (إن كان مضبوطاً)، وإلا
 * يتراجع إلى mail() المدمجة. لا يُفشل الطلب الأصلي أبداً حتى لو تعذّر الإرسال
 * فعلياً — فقط يُسجّل الخطأ في السجل.
 */
function jawali_send_email(string $to, string $subject, string $textBody, string $htmlBody = ''): bool {
    if (_sendgrid_configured()) {
        $apiKey = getenv('SENDGRID_API_KEY');
        $fromEmail = getenv('SENDGRID_FROM_EMAIL') ?: 'noreply@jawali.app';
        $fromName  = getenv('SENDGRID_FROM_NAME') ?: 'Jawali Ultra';

        $payload = [
            'personalizations' => [['to' => [['email' => $to]]]],
            'from' => ['email' => $fromEmail, 'name' => $fromName],
            'subject' => $subject,
            'content' => array_values(array_filter([
                ['type' => 'text/plain', 'value' => $textBody],
                $htmlBody !== '' ? ['type' => 'text/html', 'value' => $htmlBody] : null,
            ])),
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($code >= 200 && $code < 300) {
                error_log("[Jawali][Notify-Email-OK] to=$to via=sendgrid");
                return true;
            }
            error_log("[Jawali][Notify-Email-FAIL] to=$to via=sendgrid code=$code err=$err resp=" . substr((string)$resp, 0, 300));
            // نتابع بالتراجع إلى mail() أدناه بدلاً من الفشل الكامل
        }
    }

    // تراجع: mail() المدمجة في PHP (تتطلب MTA مضبوطاً على الخادم)
    $headers = "From: noreply@jawali.app\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";
    $sent = @mail($to, $subject, $textBody, $headers);
    if ($sent) {
        error_log("[Jawali][Notify-Email-OK] to=$to via=mail()");
    } else {
        error_log("[Jawali][Notify-Email-FAIL] to=$to via=mail() — لم يتم ضبط SENDGRID_API_KEY ولا MTA محلي");
    }
    return true; // لا نُفشل تدفّق العملية الأصلي حتى لو تعذّر الإرسال فعلياً
}

// ─── الرسائل القصيرة (SMS) ──────────────────────────────────────────────────

function _sms_configured(): bool {
    return (getenv('SMS_PROVIDER') ?: '') !== '' && (getenv('SMS_API_KEY') ?: '') !== '';
}

/**
 * إرسال SMS حقيقي عبر مزوّد Twilio (المزوّد الوحيد المدعوم حالياً عبر
 * SMS_PROVIDER=twilio). إن لم يكن أي مزوّد مضبوطاً، يُسجَّل الرمز في السجل
 * فقط (وضع تطوير) دون إفشال الطلب الأصلي.
 */
function jawali_send_sms(string $to, string $message): bool {
    if (!_sms_configured()) {
        error_log("[Jawali][Notify-SMS-DEV] (SMS_PROVIDER غير مضبوط) to=$to msg=$message");
        return true; // وضع تطوير — لا نُفشل الطلب الأصلي
    }

    $provider = strtolower((string)getenv('SMS_PROVIDER'));

    if ($provider === 'twilio') {
        $accountSid = getenv('SMS_API_KEY');
        $authToken  = getenv('SMS_API_SECRET') ?: '';
        $from       = getenv('SMS_SENDER') ?: 'Jawali';

        if (function_exists('curl_init')) {
            $url = "https://api.twilio.com/2010-04-01/Accounts/$accountSid/Messages.json";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'To'   => $to,
                'From' => $from,
                'Body' => $message,
            ]));
            curl_setopt($ch, CURLOPT_USERPWD, "$accountSid:$authToken");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($code >= 200 && $code < 300) {
                error_log("[Jawali][Notify-SMS-OK] to=$to via=twilio");
                return true;
            }
            error_log("[Jawali][Notify-SMS-FAIL] to=$to via=twilio code=$code err=$err resp=" . substr((string)$resp, 0, 300));
            return false;
        }
    }

    error_log("[Jawali][Notify-SMS-DEV] مزوّد غير معروف أو curl غير متوفر ($provider) to=$to msg=$message");
    return true; // لا نُفشل الطلب الأصلي
}
