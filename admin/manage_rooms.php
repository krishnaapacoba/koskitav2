<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = checkRole(['ADMIN']); $db = db(); $ids = adminKosanIds(); $arr = pgArr($ids);

function roomOf(PDO $db, int $id, string $arr): ?array {
    $s = $db->prepare('SELECT r.*,(SELECT id_user FROM users WHERE id_room=r.id_room LIMIT 1) AS occupant FROM rooms r WHERE r.id_room=? AND r.id_kosan=ANY(CAST(? AS int[]))');
    $s->execute([$id, $arr]); return $s->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $act = $_POST['action'] ?? '';
    if ($act === 'delete') {
        $r = roomOf($db, (int)($_POST['id_room'] ?? 0), $arr);
        if (!$r) flash('error', 'Kamar tidak ditemukan.');
        elseif ($r['occupant']) flash('error', 'Kamar masih terikat dengan penghuni/pemesan, tidak dapat dihapus.');
        else { foreach (jsonList($r['foto_kamar']) as $f) deleteFile($f); $db->prepare('DELETE FROM rooms WHERE id_room=?')->execute([$r['id_room']]); flash('success', 'Kamar dihapus.'); }
        redirect('admin/manage_rooms.php');
    }
    if ($act === 'save') {
        $idRoom = (int)($_POST['id_room'] ?? 0); $nomor = clean($_POST['nomor_kamar'] ?? ''); $harga = (float)preg_replace('/\D/', '', $_POST['harga'] ?? '');
        $fac = array_map('intval', $_POST['fac'] ?? []); $newFiles = [];
        try {
            if ($nomor === '' || strlen($nomor) > 20) throw new RuntimeException('Nomor kamar wajib diisi (maks. 20 karakter).');
            if ($harga <= 0) throw new RuntimeException('Harga harus lebih dari 0.');
            $photos = []; $room = null;
            if ($idRoom) { $room = roomOf($db, $idRoom, $arr); if (!$room) throw new RuntimeException('Kamar tidak ditemukan.'); $photos = jsonList($room['foto_kamar']); }
            else { $k = (int)($_POST['id_kosan'] ?? 0); if (!in_array($k, $ids, true)) throw new RuntimeException('Pilih kosan yang valid.'); }
            foreach (normalizeFiles('foto') as $f) $newFiles[] = saveUpload($f, 'rooms');
            $db->beginTransaction();
            if ($room) {
                $status = $room['occupant'] ? $room['status_kamar'] : (in_array($_POST['status'] ?? '', ['AVAILABLE', 'ON_HOLD', 'OCCUPIED'], true) ? $_POST['status'] : 'AVAILABLE');
                $del = $_POST['del_photo'] ?? [];
                foreach ($photos as $i => $ph) if (in_array((string)$i, $del, true)) { deleteFile($ph); unset($photos[$i]); }
                $photos = array_values(array_merge($photos, $newFiles));
                $db->prepare('UPDATE rooms SET nomor_kamar=?,harga_per_bulan=?,status_kamar=CAST(? AS room_status_enum),foto_kamar=? WHERE id_room=?')
                   ->execute([$nomor, $harga, $status, json_encode($photos), $idRoom]);
            } else {
                $status = in_array($_POST['status'] ?? '', ['AVAILABLE', 'ON_HOLD', 'OCCUPIED'], true) ? $_POST['status'] : 'AVAILABLE';
                $ins = $db->prepare('INSERT INTO rooms(id_kosan,nomor_kamar,harga_per_bulan,status_kamar,foto_kamar) VALUES (?,?,?,CAST(? AS room_status_enum),?) RETURNING id_room');
                $ins->execute([$k, $nomor, $harga, $status, json_encode($newFiles)]); $idRoom = (int)$ins->fetchColumn();
            }
            $db->prepare('DELETE FROM room_facilities WHERE id_room=?')->execute([$idRoom]);
            $fi = $db->prepare('INSERT INTO room_facilities(id_room,id_facility) VALUES (?,?)');
            foreach (array_unique($fac) as $f) $fi->execute([$idRoom, $f]);
            $db->commit();
            flash('success', 'Data kamar disimpan.');
        } catch (Throwable $t) {
            if ($db->inTransaction()) $db->rollBack();
            foreach ($newFiles as $p) deleteFile($p);
            flash('error', ($t instanceof PDOException && $t->getCode() === '23505') ? 'Nomor kamar sudah dipakai di kosan tersebut.' : ($t instanceof RuntimeException ? $t->getMessage() : 'Gagal menyimpan kamar.'));
            redirect('admin/manage_rooms.php' . ($idRoom ? '?edit=' . $idRoom : ''));
        }
        redirect('admin/manage_rooms.php');
    }
}

$edit = isset($_GET['edit']) ? roomOf($db, (int)$_GET['edit'], $arr) : null;
$editFac = []; if ($edit) { $s = $db->prepare('SELECT id_facility FROM room_facilities WHERE id_room=?'); $s->execute([$edit['id_room']]); $editFac = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)); }
$ks = $db->prepare('SELECT id_kosan,nama_kos FROM kosan WHERE id_kosan=ANY(CAST(? AS int[])) ORDER BY nama_kos'); $ks->execute([$arr]); $kosans = $ks->fetchAll();
$rs = $db->prepare("SELECT r.*,k.nama_kos,(SELECT string_agg(f.nama_fasilitas,', ' ORDER BY f.nama_fasilitas) FROM room_facilities rf JOIN facilities f USING(id_facility) WHERE rf.id_room=r.id_room) AS fas
    FROM rooms r JOIN kosan k USING(id_kosan) WHERE r.id_kosan=ANY(CAST(? AS int[])) ORDER BY k.nama_kos,r.nomor_kamar"); $rs->execute([$arr]); $rooms = $rs->fetchAll();
$facs = allFacilities(); $ePhotos = $edit ? jsonList($edit['foto_kamar']) : [];

app_start('ADMIN', 'rooms', 'Kelola Kamar', 'Tambah, ubah, atau hapus unit kamar, tarif, fasilitas, dan foto.'); ?>
<form class="card" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id_room" value="<?= $edit ? (int)$edit['id_room'] : 0 ?>">
  <h3><?= $edit ? 'Ubah kamar ' . e($edit['nomor_kamar']) : 'Tambah kamar baru' ?></h3>
  <div class="grid g3">
    <label class="field">Kosan<?php if ($edit): ?><input type="text" value="<?= e(array_column($kosans, 'nama_kos', 'id_kosan')[$edit['id_kosan']] ?? '') ?>" disabled><?php else: ?>
      <select name="id_kosan" required><?php foreach ($kosans as $k) echo '<option value="' . $k['id_kosan'] . '">' . e($k['nama_kos']) . '</option>'; ?></select><?php endif; ?></label>
    <label class="field">Nomor kamar<input type="text" name="nomor_kamar" placeholder="Contoh: A-01" maxlength="20" required value="<?= e($edit['nomor_kamar'] ?? '') ?>"></label>
    <label class="field">Harga per bulan (Rp)<input type="text" name="harga" inputmode="numeric" placeholder="1200000" required value="<?= $edit ? (int)$edit['harga_per_bulan'] : '' ?>"></label>
  </div>
  <label class="field">Status kamar
    <?php if ($edit && $edit['occupant']): ?><input type="text" disabled value="<?= e($edit['status_kamar']) ?> (diatur otomatis, kamar terikat penghuni/pemesan)"><?php else: ?>
    <select name="status"><?php foreach (['AVAILABLE' => 'Tersedia', 'ON_HOLD' => 'Ditahan', 'OCCUPIED' => 'Terisi'] as $v => $l) echo '<option value="' . $v . '"' . (($edit['status_kamar'] ?? 'AVAILABLE') === $v ? ' selected' : '') . '>' . $l . '</option>'; ?></select><?php endif; ?></label>
  <div class="field">Fasilitas</div><div class="checks"><?php foreach ($facs as $f): ?><label><input type="checkbox" name="fac[]" value="<?= $f['id_facility'] ?>" <?= in_array((int)$f['id_facility'], $editFac, true) ? 'checked' : '' ?>><?= e($f['nama_fasilitas']) ?></label><?php endforeach; ?></div>
  <?php if ($ePhotos): ?><div class="field">Foto saat ini (centang untuk menghapus)</div><div class="thumbs" style="margin-bottom:1rem"><?php foreach ($ePhotos as $i => $ph): ?><label style="font-weight:400;font-size:.8rem"><img src="<?= e(url($ph)) ?>" alt=""><br><input type="checkbox" name="del_photo[]" value="<?= $i ?>"> hapus</label><?php endforeach; ?></div><?php endif; ?>
  <label class="field">Foto kamar (boleh lebih dari satu)<input type="file" name="foto[]" accept="image/*" multiple></label>
  <div class="actions"><button class="btn" type="submit"><?= $edit ? 'Simpan perubahan' : 'Tambah kamar' ?></button><?php if ($edit): ?><a class="btn btn-outline" href="<?= e(url('admin/manage_rooms.php')) ?>">Batal</a><?php endif; ?></div>
</form>
<div class="card"><h3>Daftar kamar</h3><div class="table-wrap"><table class="table tint"><thead><tr><th>Kosan</th><th>Kamar</th><th>Harga</th><th>Status</th><th>Fasilitas</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($rooms as $r): ?><tr><td><?= e($r['nama_kos']) ?></td><td><b><?= e($r['nomor_kamar']) ?></b></td><td><?= rupiah($r['harga_per_bulan']) ?></td><td><?= statusBadge('room', $r['status_kamar']) ?></td><td class="small"><?= e($r['fas'] ?: '-') ?></td>
  <td class="actions"><a class="btn btn-outline btn-sm" href="<?= e(url('admin/manage_rooms.php?edit=' . $r['id_room'])) ?>">Ubah</a>
  <form method="post" class="inline" data-confirm="Hapus kamar <?= e($r['nomor_kamar']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id_room" value="<?= $r['id_room'] ?>"><button class="btn btn-red btn-sm">Hapus</button></form></td></tr>
<?php endforeach; if (!$rooms): ?><tr><td colspan="6" class="muted">Belum ada kamar.</td></tr><?php endif; ?></tbody></table></div></div>
<?php app_end();
