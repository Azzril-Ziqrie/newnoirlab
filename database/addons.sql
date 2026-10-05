-- Add-on tiket (mis. tenda) per tier.

CREATE TABLE IF NOT EXISTS ticket_addons (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    name      VARCHAR(120) NOT NULL,
    price     INT NOT NULL DEFAULT 0,
    INDEX (ticket_id)
);

-- Snapshot add-on yang dibeli di sebuah order (nama & harga disalin).
CREATE TABLE IF NOT EXISTS order_addons (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    addon_id INT NULL,
    name     VARCHAR(120) NOT NULL,
    price    INT NOT NULL DEFAULT 0,
    qty      INT NOT NULL DEFAULT 1,
    INDEX (order_id)
);
