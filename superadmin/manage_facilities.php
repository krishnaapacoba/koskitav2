<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = checkRole(['SUPERADMIN']); $db = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $act = $_POST['action'] ?? ''; $nama = clean($_POST['nama'] ?? ''); $id = (int)($_POST['id_facility'] ?? 0);
    try {
        if ($act === 'delete') { $db->prepare('DELETE FROM facilities WHERE id_facility=?')->execute([$id]); flash('success', 'Fasilitas dihapus.'); }
        elseif ($nama === '' || strlen($nama) > 50) flash('error', 'Nama fasilitas wajib diisi (maks. 50 karakter).');
        elseif ($act === 'rename') { $db->prepare('UPDATE facilities SET nama_fasilitas=? WHERE id_facility=?')->execute([$nama, $id]); flash('success', 'Fasilitas diperbarui.'); }
        else { $db->prepare('INSERT INTO facilities(nama_fasilitas) VALUES (?)')->execute([$nama]); flash('success', 'Fasilitas ditambahkan.'); }
    } catch (PDOException $e) { flash('error', $e->getCode() === '23505' ? 'Nama fasilitas sudah ada.' : 'Gagal menyimpan fasilitas.'); }
    redirect('superadmin/manage_facilities.php');
}
$rows = $db->query('SELECT f.*,(SELECT COUNT(*) FROM room_facilities rf WHERE rf.id_facility=f.id_facility) AS dipakai FROM facilities f ORDER BY nama_fasilitas')->fetchAll();
app_start('SUPERADMIN', 'fasilitas', 'Kelola Fasilitas', 'Katalog fasilitas yang dapat dipilih admin untuk setiap kamar.'); ?>
<form class="card" method="post"><?= csrf_field() ?><h3>Tambah fasilitas</h3><div class="actions"><input type="text" name="nama" placeholder="Contoh: Dapur bersama" maxlength="50" required style="margin:0;max-width:340px"><button class="btn">Tambah</button></div></form>
<div class="card"><h3>Daftar fasilitas</h3><div class="table-wrap"><table class="table"><thead><tr><th>Nama fasilitas</th><th>Dipakai</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><form method="post" class="actions" style="flex-wrap:nowrap"><?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id_facility" value="<?= $r['id_facility'] ?>"><input type="text" name="nama" value="<?= e($r['nama_fasilitas']) ?>" maxlength="50" style="margin:0;max-width:260px"><button class="btn btn-outline btn-sm">Simpan</button></form></td>
  <td><?= (int)$r['dipakai'] ?> kamar</td><td><form method="post" class="inline" data-confirm="Hapus fasilitas ini dari semua kamar?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id_facility" value="<?= $r['id_facility'] ?>"><button class="btn btn-red btn-sm">Hapus</button></form></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php app_end();
