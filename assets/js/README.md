# مكتبات JavaScript المحلية — إصلاح #8

## المطلوب تنزيله يدوياً قبل النشر

### html5-qrcode v2.3.8
يُستخدم في: `scanner_scan.php`

**خطوات التنزيل:**

```bash
# من سطر الأوامر على جهاز متصل بالإنترنت
curl -L https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js \
     -o jawali_api/assets/js/html5-qrcode.min.js
```

أو تنزيل مباشر من:
https://github.com/mebjas/html5-qrcode/releases/tag/V2.3.8

**بعد التنزيل:**
- ضع الملف في هذا المجلد: `jawali_api/assets/js/html5-qrcode.min.js`
- الكود في `scanner_scan.php` يشير إليه بالمسار المحلي تلقائياً ✅

## ملاحظة أمنية
- تحقق من hash الملف بعد التنزيل:
  ```
  SHA256: (تحقق من الموقع الرسمي https://github.com/mebjas/html5-qrcode)
  ```
- لا تستخدم CDN خارجي في بيئة الإنتاج (مخاطر Supply Chain Attack)
