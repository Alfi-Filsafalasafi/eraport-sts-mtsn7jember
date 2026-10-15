-- ============================================================
-- DATABASE E-RAPOR STS (Sumatif Tengah Semester)
-- MySQL / MariaDB | PHP Native
-- ============================================================
CREATE DATABASE IF NOT EXISTS erapor_sts
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE erapor_sts;

-- ------------------------------------------------------------
-- 1. PENGATURAN MADRASAH (hanya 1 baris, id = 1)
--    Dipakai untuk kop & tanda tangan Kepala Madrasah saat cetak
-- ------------------------------------------------------------
CREATE TABLE pengaturan (
  id               TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  instansi_atas    VARCHAR(100) NOT NULL DEFAULT 'KEMENTERIAN AGAMA REPUBLIK INDONESIA',
  nama_madrasah    VARCHAR(100) NOT NULL,          -- MADRASAH TSANAWIYAH NEGERI 7
  nama_singkat     VARCHAR(50)  NOT NULL,          -- MTs Negeri 7 Jember
  alamat           VARCHAR(255) NOT NULL,
  kabupaten        VARCHAR(50)  NOT NULL,          -- JEMBER (baris kop terakhir)
  tempat_cetak     VARCHAR(50)  NOT NULL,          -- "Jember"
  logo             VARCHAR(255) NULL,              -- path file logo
  kepala_nama      VARCHAR(100) NOT NULL,
  kepala_nip       VARCHAR(30)  NOT NULL,
  CONSTRAINT chk_single CHECK (id = 1)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2. PERIODE (Tahun Ajaran + Semester) -> "Kelola Semester"
--    Hanya 1 baris yang is_aktif = 1 (dijaga lewat PHP / transaksi)
-- ------------------------------------------------------------
CREATE TABLE periode (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tahun_ajaran   VARCHAR(9) NOT NULL,                       -- 2024/2025
  semester       ENUM('Ganjil','Genap') NOT NULL,
  tanggal_cetak  DATE NULL,                                 -- "Jember, 17 April 2025"
  is_aktif       TINYINT(1) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_periode (tahun_ajaran, semester)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. USERS (Guru & Admin)
--    Login: NIP + password (default 123, WAJIB disimpan hash
--    memakai password_hash() di PHP, jangan plain text)
-- ------------------------------------------------------------
CREATE TABLE users (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nip         VARCHAR(30)  NOT NULL UNIQUE,
  nama        VARCHAR(100) NOT NULL,                        -- termasuk gelar
  password    VARCHAR(255) NOT NULL,                        -- hasil password_hash()
  role        ENUM('admin','guru') NOT NULL DEFAULT 'guru',
  is_aktif    TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. SISWA (data induk, tidak berubah tiap semester)
-- ------------------------------------------------------------
CREATE TABLE siswa (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nis_lokal     VARCHAR(30) NOT NULL UNIQUE,
  nis_nasional  VARCHAR(20) NULL UNIQUE,                    -- NISN
  nama          VARCHAR(100) NOT NULL,
  tanggal_lahir DATE NULL,                                  -- verifikasi tambahan akses wali (disarankan)
  is_aktif      TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. KELAS (per periode, karena nama kelas & walas bisa berubah)
-- ------------------------------------------------------------
CREATE TABLE kelas (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  periode_id    INT UNSIGNED NOT NULL,
  nama_kelas    VARCHAR(20) NOT NULL,                       -- VII A
  tingkat       TINYINT UNSIGNED NOT NULL,                  -- 7, 8, 9 (menentukan mapel yang tampil)
  fase          VARCHAR(5)  NOT NULL DEFAULT 'D',           -- D
  wali_kelas_id INT UNSIGNED NULL,                          -- users.id (walas)
  -- Alur finalisasi: draft -> final. Saat 'final', nilai dikunci (tidak bisa diedit guru)
  status_rapor    ENUM('draft','final') NOT NULL DEFAULT 'draft',
  difinalkan_at   DATETIME NULL,
  difinalkan_oleh INT UNSIGNED NULL,                        -- users.id
  UNIQUE KEY uq_kelas (periode_id, nama_kelas),
  FOREIGN KEY (periode_id)    REFERENCES periode(id) ON DELETE CASCADE,
  FOREIGN KEY (wali_kelas_id)   REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (difinalkan_oleh) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6. ANGGOTA KELAS (siswa di kelas pada periode tertentu)
--    Nilai & absensi menempel ke tabel ini, bukan langsung ke siswa
-- ------------------------------------------------------------
CREATE TABLE anggota_kelas (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  periode_id  INT UNSIGNED NOT NULL,                        -- sama dengan kelas.periode_id
  kelas_id    INT UNSIGNED NOT NULL,
  siswa_id    INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_anggota (kelas_id, siswa_id),
  UNIQUE KEY uq_siswa_periode (periode_id, siswa_id),       -- 1 siswa hanya 1 kelas per periode
  FOREIGN KEY (periode_id) REFERENCES periode(id) ON DELETE CASCADE,
  FOREIGN KEY (kelas_id)   REFERENCES kelas(id)   ON DELETE CASCADE,
  FOREIGN KEY (siswa_id)   REFERENCES siswa(id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 7. MATA PELAJARAN
--    - parent_id  : untuk sub-mapel (PAI -> A/B/C/D, Mulok -> A/B)
--    - is_header  : TRUE = hanya judul grup, tidak punya nilai
--    - no_urut    : nomor tampil di rapor (1..12)
--    - label      : "A.", "B." dst untuk sub-mapel
-- ------------------------------------------------------------
CREATE TABLE mapel (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id  INT UNSIGNED NULL,
  no_urut    TINYINT UNSIGNED NULL,
  label      VARCHAR(5) NULL,
  nama       VARCHAR(150) NOT NULL,
  is_header  TINYINT(1) NOT NULL DEFAULT 0,
  urutan     SMALLINT UNSIGNED NOT NULL,                    -- urutan baris di tabel
  is_aktif   TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (parent_id) REFERENCES mapel(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 8. NILAI (1 baris = 1 siswa x 1 mapel, dalam 1 periode)
--    TP1-TP7 (Nilai Tugas) & PH1-PH5 -> boleh NULL (kosong)
-- ------------------------------------------------------------
CREATE TABLE nilai (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  anggota_kelas_id INT UNSIGNED NOT NULL,
  mapel_id         INT UNSIGNED NOT NULL,
  tp1 DECIMAL(5,2) NULL, tp2 DECIMAL(5,2) NULL, tp3 DECIMAL(5,2) NULL,
  tp4 DECIMAL(5,2) NULL, tp5 DECIMAL(5,2) NULL, tp6 DECIMAL(5,2) NULL,
  tp7 DECIMAL(5,2) NULL,
  ph1 DECIMAL(5,2) NULL, ph2 DECIMAL(5,2) NULL, ph3 DECIMAL(5,2) NULL,
  ph4 DECIMAL(5,2) NULL, ph5 DECIMAL(5,2) NULL,
  nilai_sts        DECIMAL(5,2) NULL,
  diinput_oleh     INT UNSIGNED NULL,                       -- users.id (log siapa yg input)
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_nilai (anggota_kelas_id, mapel_id),
  FOREIGN KEY (anggota_kelas_id) REFERENCES anggota_kelas(id) ON DELETE CASCADE,
  FOREIGN KEY (mapel_id)         REFERENCES mapel(id)         ON DELETE CASCADE,
  FOREIGN KEY (diinput_oleh)     REFERENCES users(id)         ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 8b. MAPEL PER TINGKAT PER PERIODE
--     Menentukan mapel apa saja yang berlaku di tiap tingkat (7/8/9)
--     pada periode tertentu. Awalnya semua tingkat sama; kalau kelak
--     berbeda, admin tinggal mencentang/mencabut mapel di tingkat itu.
--     Karena per periode, semester lama tidak ikut berubah.
--     Saat membuat periode baru, salin baris dari periode sebelumnya.
--     Baris header (PAI, Muatan Lokal) ditampilkan hanya jika
--     minimal satu sub-mapelnya terdaftar di tingkat tersebut.
-- ------------------------------------------------------------
CREATE TABLE mapel_tingkat (
  periode_id  INT UNSIGNED NOT NULL,
  tingkat     TINYINT UNSIGNED NOT NULL,
  mapel_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (periode_id, tingkat, mapel_id),
  FOREIGN KEY (periode_id) REFERENCES periode(id) ON DELETE CASCADE,
  FOREIGN KEY (mapel_id)   REFERENCES mapel(id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 9. KETIDAKHADIRAN (1 baris per siswa per periode)
-- ------------------------------------------------------------
CREATE TABLE ketidakhadiran (
  anggota_kelas_id INT UNSIGNED PRIMARY KEY,
  sakit  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  izin   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  alpa   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (anggota_kelas_id) REFERENCES anggota_kelas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 10. PENGUMUMAN (rapor dibagikan lewat pengumuman)
--     kelas_id NULL = berlaku untuk semua kelas pada periode itu.
--     Wali baru bisa buka rapor jika: pengumuman is_tampil = 1
--     DAN kelas.status_rapor = 'final'.
-- ------------------------------------------------------------
CREATE TABLE pengumuman (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  periode_id   INT UNSIGNED NOT NULL,
  kelas_id     INT UNSIGNED NULL,
  judul        VARCHAR(200) NOT NULL,
  isi          TEXT NULL,
  is_rapor     TINYINT(1) NOT NULL DEFAULT 1,               -- 1 = pengumuman pembagian rapor
  is_tampil    TINYINT(1) NOT NULL DEFAULT 0,
  tampil_mulai DATETIME NULL,
  tampil_akhir DATETIME NULL,
  dibuat_oleh  INT UNSIGNED NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (periode_id)  REFERENCES periode(id) ON DELETE CASCADE,
  FOREIGN KEY (kelas_id)    REFERENCES kelas(id)   ON DELETE CASCADE,
  FOREIGN KEY (dibuat_oleh) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 11. TANDA TANGAN WALI (bukti rapor sudah dilihat)
--     1 siswa = 1 tanda tangan per periode.
--     tanda_tangan : gambar PNG base64 dari <canvas> (atau path file)
--     hash_rapor   : SHA-256 dari data nilai+absensi saat ditandatangani,
--                    sebagai bukti isi rapor yang dilihat tidak berubah.
-- ------------------------------------------------------------
CREATE TABLE tanda_tangan_wali (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  anggota_kelas_id INT UNSIGNED NOT NULL UNIQUE,
  nama_penanda     VARCHAR(100) NOT NULL,                   -- nama orang tua/wali yang ttd
  hubungan         ENUM('Ayah','Ibu','Wali') NOT NULL,
  tanda_tangan     MEDIUMTEXT NOT NULL,
  hash_rapor       CHAR(64) NOT NULL,
  ditandatangani_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address       VARCHAR(45) NULL,
  user_agent       VARCHAR(255) NULL,
  FOREIGN KEY (anggota_kelas_id) REFERENCES anggota_kelas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 12. LOG AKSES RAPOR (opsional: kapan wali membuka rapor)
-- ------------------------------------------------------------
CREATE TABLE log_akses_rapor (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  anggota_kelas_id INT UNSIGNED NOT NULL,
  waktu            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address       VARCHAR(45) NULL,
  FOREIGN KEY (anggota_kelas_id) REFERENCES anggota_kelas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 13. PERCOBAAN LOGIN WALI (pembatasan percobaan / anti tebak NIS)
-- ------------------------------------------------------------
CREATE TABLE percobaan_login_wali (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_address  VARCHAR(45) NOT NULL,
  input_nis   VARCHAR(30) NULL,
  berhasil    TINYINT(1) NOT NULL DEFAULT 0,
  waktu       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip_waktu (ip_address, waktu)
) ENGINE=InnoDB;

-- ============================================================
-- DATA AWAL (SEED)
-- ============================================================
INSERT INTO pengaturan (id, nama_madrasah, nama_singkat, alamat, kabupaten, tempat_cetak, kepala_nama, kepala_nip)
VALUES (1, 'MADRASAH TSANAWIYAH NEGERI 7', 'MTs Negeri 7 Jember',
        'Jl. WR. Supratman No. 55, Umbulrejo, Umbulsari Telp. (0336) 441816 Kode Pos 68166',
        'JEMBER', 'Jember', 'Ihsanuddin, S.Pd., M.Pd.', '197108081999031004');

INSERT INTO periode (tahun_ajaran, semester, tanggal_cetak, is_aktif)
VALUES ('2024/2025', 'Genap', '2025-04-17', 1);

-- Mapel sesuai format rapor
INSERT INTO mapel (id, parent_id, no_urut, label, nama, is_header, urutan) VALUES
(1,  NULL, 1,    NULL, 'Pendidikan Agama Islam', 1, 1),
(2,  1,    NULL, 'A',  'Al Qur''an Hadis',            0, 2),
(3,  1,    NULL, 'B',  'Akidah Akhlak',               0, 3),
(4,  1,    NULL, 'C',  'Fikih',                       0, 4),
(5,  1,    NULL, 'D',  'Sejarah Kebudayaan Islam',    0, 5),
(6,  NULL, 2,    NULL, 'Bahasa Arab',                 0, 6),
(7,  NULL, 3,    NULL, 'Pendidikan Pancasila',        0, 7),
(8,  NULL, 4,    NULL, 'Bahasa Indonesia',            0, 8),
(9,  NULL, 5,    NULL, 'Matematika',                  0, 9),
(10, NULL, 6,    NULL, 'Ilmu Pengetahuan Alam',       0, 10),
(11, NULL, 7,    NULL, 'Ilmu Pengetahuan Sosial',     0, 11),
(12, NULL, 8,    NULL, 'Bahasa Inggris',              0, 12),
(13, NULL, 9,    NULL, 'Pendidikan Jasmani, Olahraga dan Kesehatan', 0, 13),
(14, NULL, 10,   NULL, 'Informatika',                 0, 14),
(15, NULL, 11,   NULL, 'Seni Budaya',                 0, 15),
(16, NULL, 14,   NULL, 'Muatan Lokal',                1, 18),
(17, 16,   NULL, 'A',  'Bahasa Jawa',                 0, 19),
(18, 16,   NULL, 'B',  'Unggulan .....',              0, 20),
(19, NULL, 12,   NULL, 'Coding',                      0, 16),
(20, NULL, 13,   NULL, 'Kecerdasan Artifisial (AI)',  0, 17);

-- Semua mapel berlaku untuk tingkat 7, 8, 9 pada periode awal
INSERT INTO mapel_tingkat (periode_id, tingkat, mapel_id)
SELECT 1, t.tingkat, m.id
FROM mapel m
CROSS JOIN (SELECT 7 AS tingkat UNION SELECT 8 UNION SELECT 9) t;

-- Salin ke periode baru (contoh periode id 2 dari id 1):
-- INSERT INTO mapel_tingkat (periode_id, tingkat, mapel_id)
-- SELECT 2, tingkat, mapel_id FROM mapel_tingkat WHERE periode_id = 1;

-- Admin awal. Password DI-HASH lewat PHP, contoh:
--   echo password_hash('123', PASSWORD_DEFAULT);
-- lalu tempel hasilnya di kolom password.
-- INSERT INTO users (nip, nama, password, role) VALUES ('admin', 'Administrator', '<hash>', 'admin');


-- ============================================================
-- DATA PENGGUNA: ADMIN + GURU & STAF MTsN 7 JEMBER
-- Password guru: 123 | Password admin: admin123 (segera ganti)
-- NIP 1001-1004 = pegawai yang belum punya NIP
-- ============================================================
SET @h = '$2y$10$vHu5Wm.f2wOGvrIC9BCcJOJonJBRVfRv.SNL..Pp3RAOPXwww9bPq';

INSERT INTO users (nip, nama, password, role)
VALUES ('admin', 'Administrator', '$2y$10$LtLEIeZqX6E7Gv0QOucFCevR9x/omyB7qkcPe/S7kY1/THik1GAOu', 'admin');

INSERT INTO users (nip, nama, password, role) VALUES
('197404082007011028', 'DEDI EPENDI, S.Ag., M.M.Pd.', @h, 'guru'),
('196908032007011024', 'M. NURUL HUDA, S.Pd.I', @h, 'guru'),
('197212102005011005', 'DARMANI, S.Pd, M.Pd.I', @h, 'guru'),
('198203102007101001', 'HERMAWAN SUPRIYADI S.Pd, M.Pd', @h, 'guru'),
('198310302005011001', 'M. FATKHIS SUUD S. PdI, M.Pd', @h, 'guru'),
('198205152023212035', 'YATUN SUNDARSIH S.Pd', @h, 'guru'),
('196705272005011002', 'Drs. HASIT, M.Pd.I', @h, 'guru'),
('197208081998032002', 'NURUL LAILI, S.Pd, M.Pd.I', @h, 'guru'),
('196705151992032004', 'SITI ISNAINI MURDININGRUM, S.Pd, M.Pd', @h, 'guru'),
('197704142005012002', 'DEFI MASRUROH, S.Ag', @h, 'guru'),
('196908052000032005', 'Dra. USWATUN HASANAH, M.Pd.I', @h, 'guru'),
('197010122005012002', 'RIRIN SULISTYOWATI S,Pd', @h, 'guru'),
('197407222005012005', 'IZZA NUR LAILA, S.Ag.', @h, 'guru'),
('197304172005011007', 'EKO BUDI SETIYADI, S.Pd., M.Pd', @h, 'guru'),
('197606192005012004', 'YUNI HERAWATI, S.Pd.', @h, 'guru'),
('197604052007102007', 'SITI FATHIMAH S.Pd', @h, 'guru'),
('197007012007012026', 'SUJARWATI S.Pd', @h, 'guru'),
('197506252007102003', 'CHUSNUL CHOTIMAH S.Pd', @h, 'guru'),
('197212092007102002', 'SRI HIDAYATI S.Pd', @h, 'guru'),
('197008142009011007', 'AGUS SUPANIADI S.Pd', @h, 'guru'),
('197510172007012021', 'ANIS SA`ADAH, S.Pd', @h, 'guru'),
('196911102006041001', 'SO`IM, S.Pd.I,.M.Pd', @h, 'guru'),
('197403012007102001', 'EMI MASRUROH S.Ag, M.Pd', @h, 'guru'),
('197111022005012001', 'IIN INDRAWATI, S.Pd', @h, 'guru'),
('197803152007102005', 'IVA AMINATUZ ZUHRIYAH, S.Pd', @h, 'guru'),
('198508292009102002', 'ELIK SETIYANINGSIH S.Sos', @h, 'guru'),
('198507272009102002', 'YULIA YUSNITA, S.Sos.', @h, 'guru'),
('199502012019032021', 'KUNI NOERVITA ROMADHINI, S.Si', @h, 'guru'),
('199309132019032032', 'NAILUL FAUZIATI RIZQI S.Pd.', @h, 'guru'),
('198811102019031010', 'MOH. FATKUR ROHMAN SHOLEH, S.S', @h, 'guru'),
('199008072019031014', 'ENKI DANI NUGROHO, S.Pd. Gr. M.Pd.', @h, 'guru'),
('197801102023212010', 'NIKE KUSUMAWARDANI S. Pd.', @h, 'guru'),
('198001232023212008', 'IZZAH DINIHARI S.Pd.I.', @h, 'guru'),
('197107082023211002', 'ALI IMRON S.Pd', @h, 'guru'),
('199402022023212057', 'NALA IZZATUL FARDANA ARWI S.Pd, M.Pd', @h, 'guru'),
('199401082023211015', 'MUHAMMAD PANDU SYARIFUDDIN, S.Pd', @h, 'guru'),
('199606082023211008', 'VAREZA JUNIARDI, S.Pd', @h, 'guru'),
('199607012023212037', 'INTAN WULANDARI S.Pd.', @h, 'guru'),
('199205232025211007', 'MIFTAHUR RIZAL, S.Pd', @h, 'guru'),
('198710262025212014', 'KHOLISHOH LISTIANA, S.Pd', @h, 'guru'),
('198108032025211005', 'VIENDY ANDRE WIYANDANA, S.Pd', @h, 'guru'),
('198807072025211028', 'AHMAD TAQIYYUDIN, S.Pd', @h, 'guru'),
('198505262025211001', 'AHMAD FAISAL MUTTAQIN, S.Pd', @h, 'guru'),
('199205262025212023', 'ANDRIANA NEFELIAN CAHYA LUTFITA REGAR, S.Pd', @h, 'guru'),
('1001', 'FEMDANA ERLITASARI, S.Pd', @h, 'guru'),
('1002', 'ISNAINI, S.Pd', @h, 'guru'),
('200204092025051003', 'ALFI FILSAFALASAFI, S.Pd', @h, 'guru'),
('199807252025052004', 'RISKI FIDIANA, S.Pd', @h, 'guru'),
('199205252025051007', 'DENDI BAGUS WINDIAR, S.Or', @h, 'guru'),
('197604232025211005', 'ACHMAD JUNAEDI', @h, 'guru'),
('199501182025212010', 'NUR ANGGRAENI', @h, 'guru'),
('198608242025212009', 'RIKA INDARWATI', @h, 'guru'),
('197406102025211028', 'MOHAMMAD ASROFI', @h, 'guru'),
('197512022025211018', 'EKO CAHYONO', @h, 'guru'),
('197008012025211026', 'SULIADI', @h, 'guru'),
('1003', 'JOKO SUSILO', @h, 'guru'),
('1004', 'TONI PURWANTO', @h, 'guru');

-- Kepala Madrasah sesuai website madrasah
UPDATE pengaturan
SET kepala_nama = 'DEDI EPENDI, S.Ag., M.M.Pd.', kepala_nip = '197404082007011028'
WHERE id = 1;

-- ============================================================
-- CONTOH QUERY CETAK RAPOR (1 siswa)
-- ============================================================
-- SELECT m.*, n.tp1,n.tp2,n.tp3,n.tp4,n.tp5,n.tp6,n.tp7,
--        n.ph1,n.ph2,n.ph3,n.ph4,n.ph5,n.nilai_sts
-- FROM kelas k
-- JOIN mapel_tingkat mt ON mt.periode_id = k.periode_id AND mt.tingkat = k.tingkat
-- JOIN mapel m          ON m.id = mt.mapel_id AND m.is_aktif = 1
-- LEFT JOIN nilai n     ON n.mapel_id = m.id AND n.anggota_kelas_id = :anggota_id
-- WHERE k.id = :kelas_id
-- ORDER BY m.urutan;
-- (di PHP: sembunyikan baris header yang tidak punya sub-mapel tampil)

-- ============================================================
-- CONTOH QUERY ALUR WALI SISWA
-- ============================================================
-- 1) Wali login dengan NIS/NISN (+ tanggal lahir), hanya jika rapor final & diumumkan:
-- SELECT s.id AS siswa_id, ak.id AS anggota_id, k.nama_kelas
-- FROM siswa s
-- JOIN anggota_kelas ak ON ak.siswa_id = s.id
-- JOIN kelas k          ON k.id = ak.kelas_id
-- JOIN periode p        ON p.id = k.periode_id AND p.is_aktif = 1
-- WHERE (s.nis_lokal = :nis OR s.nis_nasional = :nis)
--   AND s.tanggal_lahir = :tgl_lahir
--   AND k.status_rapor = 'final'
--   AND EXISTS (SELECT 1 FROM pengumuman pg
--               WHERE pg.periode_id = p.id AND pg.is_rapor = 1 AND pg.is_tampil = 1
--                 AND (pg.kelas_id IS NULL OR pg.kelas_id = k.id));
--
-- 2) Cek sudah ttd atau belum:
-- SELECT * FROM tanda_tangan_wali WHERE anggota_kelas_id = :anggota_id;
