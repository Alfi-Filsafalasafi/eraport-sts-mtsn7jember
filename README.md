# E-Rapor STS - MTs Negeri 7 Jember

## Cara memasang (Laragon / XAMPP, PHP 8.1 - 8.3)
1. Salin folder `e-rapot` ke `C:\laragon\www\` (Laragon) atau `htdocs` (XAMPP).
2. Aktifkan ekstensi PHP `zip` (Laragon: klik kanan ikon > PHP > Extensions > centang zip),
   lalu restart. Ekstensi ini dipakai untuk impor/unduh template Excel.
3. Buka phpMyAdmin > tab Impor > pilih `database/db_erapor_sts.sql` > Kirim.
   File ini membuat database `erapor_sts` beserta data awal (mapel, 57 guru/staf, admin).
4. Cek `config.php`: user/password database dan `base_url` (nama folder proyek, default `/e-rapot`).
5. Buka `http://localhost/e-rapot/login.php`.

## Akun awal
- Admin : NIP `admin`, password `admin123` (segera ganti lewat menu Ganti Password)
- Guru  : NIP masing-masing, password `123` (NIP 1001-1004 = pegawai tanpa NIP)

## Fitur yang sudah ada
Login, ganti password, Kelola Periode, Kelola Guru (+impor Excel), Kelola Siswa (+impor Excel dengan kolom kelas).

## Berikutnya
Kelola Kelas, input nilai, cetak rapor, finalisasi/pengumuman, portal wali + tanda tangan.
