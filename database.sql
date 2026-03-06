-- ============================================================
--  database.sql — Catatan Keuangan
--  Jalankan file ini di phpMyAdmin / mysql CLI untuk setup awal
--  Database: if0_41304100_keuangan
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- ------------------------------------------------------------
--  Tabel: categories
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
    `id`   VARCHAR(60)  NOT NULL,
    `nama` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Tabel: pemasukan
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pemasukan` (
    `id`           VARCHAR(60)    NOT NULL,
    `tanggal`      DATE           NOT NULL,
    `jumlah`       DECIMAL(15,2)  NOT NULL,
    `catatan`      TEXT,
    `diinput_oleh` VARCHAR(100)   DEFAULT '',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Tabel: pengeluaran
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pengeluaran` (
    `id`            VARCHAR(60)   NOT NULL,
    `tanggal`       DATE          NOT NULL,
    `kategori_id`   VARCHAR(60)   DEFAULT '',
    `kategori_nama` VARCHAR(100)  DEFAULT '',
    `jumlah`        DECIMAL(15,2) NOT NULL,
    `catatan`       TEXT,
    `diinput_oleh`  VARCHAR(100)  DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_kategori_id` (`kategori_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Tabel: users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT           NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(100)  NOT NULL,
    `nama`          VARCHAR(100)  NOT NULL DEFAULT '',
    `password_hash` VARCHAR(255)  NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  DATA: categories
-- ============================================================
INSERT INTO `categories` (`id`, `nama`) VALUES
('cat_001',            'Makanan & Minuman'),
('cat_002',            'Transportasi'),
('cat_003',            'Belanja'),
('cat_004',            'Tagihan & Utilitas'),
('cat_005',            'Kesehatan'),
('cat_69a850c524d60',  'Lain-lain');

-- ============================================================
--  DATA: pemasukan
-- ============================================================
INSERT INTO `pemasukan` (`id`, `tanggal`, `jumlah`, `catatan`, `diinput_oleh`) VALUES
('txn_69a850159f2716.77449784', '2026-03-01', 9600000,  'Gaji',        ''),
('txn_69a8cba3c7c812.89459073', '2026-03-05', 1584000,  'gaji imut',   ''),
('txn_69a8d1ceee8099.34456881', '2026-03-01', 1813000,  'Uang dines',  ''),
('txn_69a8d1ea7f9983.57792162', '2026-03-01',  500000,  'Uang trading','');

-- ============================================================
--  DATA: pengeluaran
-- ============================================================
INSERT INTO `pengeluaran` (`id`, `tanggal`, `kategori_id`, `kategori_nama`, `jumlah`, `catatan`, `diinput_oleh`) VALUES
('txn_69a850dda5a6b9.12571631', '2026-03-04', 'cat_69a850c524d60', 'Lain-lain',          548000, '',                                         ''),
('txn_69a8517dcfd2f4.44599083', '2026-03-04', 'cat_69a850c524d60', 'Lain-lain',         2900000, 'Tak tau',                                  ''),
('txn_69a8d2ddcf5276.02840414', '2026-03-01', 'cat_69a850c524d60', 'Lain-lain',         2313000, 'Ini termasuk yg ntah apa',                 ''),
('txn_69a903bdbb8fb6.15668926', '2026-03-05', 'cat_004',           'Tagihan & Utilitas',  600000, 'pinalti resign',                          'Istri'),
('txn_69a91c85a06859.02484937', '2026-03-05', 'cat_002',           'Transportasi',        20000,  'Bensin',                                  'Suami'),
('txn_69a95a0d2badf8.58188918', '2026-03-05', 'cat_003',           'Belanja',             80000,  '- telor\n- sabun\n- mi\n- galon\n- molto\n- risol', 'Suami');

-- ============================================================
--  DATA: users
--  Jalankan generate_users.php untuk mengisi tabel ini
--  (password di-hash dengan bcrypt, tidak bisa diisi manual)
-- ============================================================
-- Kosong — jalankan generate_users.php setelah import SQL ini
