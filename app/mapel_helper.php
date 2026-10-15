<?php
const TINGKAT_MAPEL = [7 => 'VII', 8 => 'VIII', 9 => 'IX'];

/**
 * Merapikan urutan seluruh mapel: induk berurutan 1..n, sub-mapel tepat di bawah induknya.
 * Nomor (1, 2, 3...) dan huruf sub-mapel (A, B, C...) juga dihitung ulang otomatis.
 */
function rapikan_urutan(): void {
    $pdo = db();
    $top = [];
    $anak = [];
    foreach ($pdo->query('SELECT id, parent_id FROM mapel ORDER BY urutan, id')->fetchAll() as $r) {
        if ($r['parent_id'] === null) $top[] = (int)$r['id'];
        else $anak[(int)$r['parent_id']][] = (int)$r['id'];
    }
    $upd = $pdo->prepare('UPDATE mapel SET urutan = ?, no_urut = ?, label = ? WHERE id = ?');
    $u = 0;
    $no = 0;
    foreach ($top as $id) {
        $upd->execute([++$u, ++$no, null, $id]);
        foreach ($anak[$id] ?? [] as $i => $cid) {
            $upd->execute([++$u, null, chr(65 + min($i, 25)), $cid]);
        }
    }
}

/** Menggeser satu mapel naik (-1) atau turun (+1) di antara saudaranya. */
function geser_mapel(int $id, int $arah): void {
    $pdo = db();
    rapikan_urutan();

    $st = $pdo->prepare('SELECT parent_id FROM mapel WHERE id = ?');
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) return;

    if ($m['parent_id'] === null) {
        $sib = $pdo->query('SELECT id, urutan FROM mapel WHERE parent_id IS NULL ORDER BY urutan, id')->fetchAll();
    } else {
        $st = $pdo->prepare('SELECT id, urutan FROM mapel WHERE parent_id = ? ORDER BY urutan, id');
        $st->execute([$m['parent_id']]);
        $sib = $st->fetchAll();
    }
    $i = null;
    foreach ($sib as $k => $s) if ((int)$s['id'] === $id) $i = $k;
    $t = $i === null ? null : $i + $arah;
    if ($t === null || !isset($sib[$t])) return;

    $upd = $pdo->prepare('UPDATE mapel SET urutan = ? WHERE id = ?');
    $upd->execute([$sib[$t]['urutan'], $sib[$i]['id']]);
    $upd->execute([$sib[$i]['urutan'], $sib[$t]['id']]);
    rapikan_urutan();
}

/** Baris judul grup (PAI, Muatan Lokal) otomatis ikut tingkat yang punya sub-mapel. */
function sinkron_header(int $pid): void {
    $pdo = db();
    $pdo->prepare('DELETE mt FROM mapel_tingkat mt JOIN mapel m ON m.id = mt.mapel_id
                   WHERE mt.periode_id = ? AND m.is_header = 1')->execute([$pid]);
    $pdo->prepare('INSERT INTO mapel_tingkat (periode_id, tingkat, mapel_id)
                   SELECT DISTINCT mt.periode_id, mt.tingkat, m.parent_id
                   FROM mapel_tingkat mt JOIN mapel m ON m.id = mt.mapel_id
                   WHERE mt.periode_id = ? AND m.parent_id IS NOT NULL')->execute([$pid]);
}