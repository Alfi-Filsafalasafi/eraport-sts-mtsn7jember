<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/xlsx_template.php';
require_role('admin');

kirim_template_xlsx('template_siswa.xlsx', [
    ['NIS Lokal', 24],
    ['NISN', 16],
    ['Nama', 44],
    ['Tanggal Lahir', 18],
    ['Kelas', 12],
]);
