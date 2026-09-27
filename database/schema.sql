CREATE DATABASE IF NOT EXISTS db_puisi_scalable2
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE db_puisi_scalable2;

CREATE TABLE IF NOT EXISTS users (
  id       INT NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  password VARCHAR(255) NOT NULL,
  nama     VARCHAR(100) NOT NULL,
  no_id    VARCHAR(50) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_no_id (no_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS puisi (
  id         INT NOT NULL AUTO_INCREMENT,
  user_id    INT NOT NULL,
  judul      VARCHAR(150) NOT NULL,
  tgl_submit DATETIME NOT NULL,
  isi        TEXT NOT NULL,
  kategori   VARCHAR(50) NOT NULL,
  keyword    VARCHAR(100) NOT NULL,
  gambar     VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_puisi_user_id (user_id),
  CONSTRAINT fk_puisi_user
    FOREIGN KEY (user_id)
    REFERENCES users (id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
