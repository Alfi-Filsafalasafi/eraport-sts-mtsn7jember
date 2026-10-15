<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/xlsx_template.php';
require_role('admin');

kirim_template_xlsx('template_guru.xlsx', [
    ['NIP', 26],
    ['Nama', 48],
]);
