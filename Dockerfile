FROM php:8.2-cli

# ── تثبيت الإضافات المطلوبة (PDO + PostgreSQL + JSON + mbstring) ────────────
RUN apt-get update && apt-get install -y \
        libpq-dev \
        unzip \
    && docker-php-ext-install pdo pdo_pgsql \
    && docker-php-ext-enable pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

# Render يمرّر منفذ التشغيل عبر متغير البيئة PORT (افتراضي 10000 محلياً كخيار احتياطي)
ENV PORT=10000
EXPOSE 10000

# خادم PHP المدمج يكفي لهذا الحجم من الحمل (بديل عن Apache/Nginx)
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /app"]
