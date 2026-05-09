-- Jalankan di phpMyAdmin (database: gamezone)

CREATE TABLE IF NOT EXISTS menu_items (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(100)               NOT NULL,
  category     ENUM('makanan','minuman')  NOT NULL,
  price        INT                        NOT NULL DEFAULT 0,
  description  TEXT,
  image        VARCHAR(255),
  is_available TINYINT(1)                NOT NULL DEFAULT 1,
  created_at   TIMESTAMP                  DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS menu_orders (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT                          NOT NULL,
  item_id    INT                          NOT NULL,
  quantity   INT                          NOT NULL DEFAULT 1,
  note       TEXT,
  status     ENUM('pending','selesai')   NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP                    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)      ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES menu_items(id)  ON DELETE CASCADE
);
