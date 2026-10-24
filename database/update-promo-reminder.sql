-- Perubahan database untuk link harga spesial dan email pengingat H-1.
-- Ini cadangan manual. Kodenya membuat semua ini sendiri (lihat lib/promos.php dan lib/reminders.php),
-- jadi file ini hanya perlu dijalankan kalau user database hosting tidak punya izin CREATE/ALTER.
-- Jalankan sekali lewat phpMyAdmin. Galat "Duplicate column name" berarti kolomnya sudah ada, aman diabaikan.

CREATE TABLE IF NOT EXISTS ticket_promos (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id  INT NOT NULL,
    code       VARCHAR(40) NOT NULL,
    label      VARCHAR(80) NOT NULL DEFAULT '',
    price      INT NOT NULL,
    max_uses   INT NULL,
    used       INT NOT NULL DEFAULT 0,
    expires_at DATETIME NULL,
    active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_code (code),
    INDEX (ticket_id)
);

ALTER TABLE orders ADD COLUMN promo_code VARCHAR(40) NULL;
ALTER TABLE orders ADD COLUMN reminder_sent_at DATETIME NULL;
