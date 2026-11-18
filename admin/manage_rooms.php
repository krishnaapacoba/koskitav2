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

app_start('ADMIN', 'rooms', 'Kelola Kamar', 'Tambah, ubah, atau hapus unit kamar, tarif, fasilitas, dan foto.');
?>

<style>
  .mr-card { padding: 1.65rem; border-radius: 18px; }
  .mr-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.35rem; padding-bottom: 1rem; border-bottom: 1px solid var(--line); }
  .mr-head h3 { margin: 0; font-size: 1.15rem; font-weight: 800; }

  /* Grid 4 Kolom untuk Form Utama agar Sejajar Rapi */
  .mr-form-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.25rem; }
  @media (max-width: 1100px) { .mr-form-grid { grid-template-columns: 1fr 1fr; } }
  @media (max-width: 640px)  { .mr-form-grid { grid-template-columns: 1fr; } }
  .mr-form-grid .field { margin-bottom: 0; }

  .mr-section-lbl { display: flex; justify-content: space-between; align-items: center; font-weight: 700; font-size: .88rem; color: var(--ink); margin-bottom: .6rem; }
  .mr-section-lbl span.hint { font-weight: 400; font-size: .8rem; color: var(--muted); }

  /* Custom Checkbox Card untuk Fasilitas */
  .fac-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(195px, 1fr)); gap: .65rem; margin-bottom: 1.35rem; }
  .fac-option {
    position: relative;
    display: flex;
    align-items: center;
    gap: .65rem;
    padding: .72rem .95rem;
    border-radius: 13px;
    border: 1.5px solid #e2ddd0;
    background: #faf9f6;
    color: var(--ink);
    font-size: .9rem;
    font-weight: 500;
    cursor: pointer;
    user-select: none;
    transition: all .15s ease;
  }
  .fac-option:hover { border-color: #99c2bd; background: #f2f8f7; }
  .fac-option input[type="checkbox"] {
    appearance: none;
    -webkit-appearance: none;
    width: 19px;
    height: 19px;
    margin: 0;
    border-radius: 6px;
    border: 1.8px solid #cbd5e1;
    background: #fff;
    display: inline-grid;
    place-content: center;
    flex-shrink: 0;
    cursor: pointer;
    transition: all .15s ease;
  }
  .fac-option input[type="checkbox"]::before {
    content: "";
    width: 10px;
    height: 10px;
    transform: scale(0);
    transition: transform .12s ease-in-out;
    box-shadow: inset 1em 1em #fff;
    clip-path: polygon(14% 44%, 0 65%, 50% 100%, 100% 16%, 80% 0%, 43% 62%);
  }
  .fac-option input[type="checkbox"]:checked {
    background: var(--teal);
    border-color: var(--teal);
  }
  .fac-option input[type="checkbox"]:checked::before {
    transform: scale(1);
  }
  .fac-option:has(input:checked) {
    background: var(--soft);
    border-color: var(--teal);
    color: var(--teal-d);
    font-weight: 700;
  }

  /* Custom File Upload Dropzone */
  .upload-zone {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: .95rem 1.15rem;
    border: 1.5px dashed #cbd5e1;
    border-radius: 14px;
    background: #faf9f6;
    cursor: pointer;
    margin-bottom: 1.35rem;
    transition: border-color .15s, background .15s;
  }
  .upload-zone:hover { border-color: var(--teal); background: #f2f8f7; }
  .upload-zone input[type="file"] { display: none; }
  .upload-ico { width: 40px; height: 40px; border-radius: 11px; background: var(--soft); color: var(--teal); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .upload-text strong { display: block; font-size: .9rem; color: var(--ink); }
  .upload-text span { font-size: .8rem; color: var(--muted); }

  /* Preview Foto Saat Edit + Checkbox Hapus Modern */
  .photo-edit-grid { display: flex; gap: .85rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
  .photo-item { position: relative; width: 120px; border-radius: 12px; overflow: hidden; border: 1.5px solid var(--line); background: #faf9f6; transition: all .15s; }
  .photo-item img { width: 100%; height: 84px; object-fit: cover; display: block; }
  .photo-del-lbl { display: flex; align-items: center; justify-content: center; gap: .35rem; padding: .4rem; font-size: .78rem; font-weight: 600; color: var(--muted); cursor: pointer; background: #fff; }
  .photo-del-lbl input[type="checkbox"] { accent-color: var(--red); width: 15px; height: 15px; margin: 0; cursor: pointer; }
  .photo-item:has(input:checked) { border-color: var(--red); opacity: .75; }
  .photo-item:has(input:checked) .photo-del-lbl { background: var(--red-bg); color: var(--red); }

  /* Badge Fasilitas di Tabel Daftar Kamar */
  .fas-pills { display: flex; flex-wrap: wrap; gap: .3rem; }
  .fas-tag { background: #f3f1ea; color: #4b5563; padding: .15rem .55rem; border-radius: 7px; font-size: .76rem; font-weight: 600; }

</style>

<form class="card mr-card" method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id_room" value="<?= $edit ? (int)$edit['id_room'] : 0 ?>">

  <div class="mr-head">
    <h3><?= $edit ? 'Ubah Unit Kamar · ' . e($edit['nomor_kamar']) : 'Tambah Kamar Baru' ?></h3>
    <?php if ($edit): ?>
      <span class="badge badge-amber">Mode Edit</span>
    <?php endif; ?>
  </div>

  <!-- 4 Input Utama Sejajar -->
  <div class="mr-form-grid">
    <label class="field">Kosan
      <?php if ($edit): ?>
        <input type="text" value="<?= e(array_column($kosans, 'nama_kos', 'id_kosan')[$edit['id_kosan']] ?? '') ?>" disabled>
      <?php else: ?>
        <select name="id_kosan" required>
          <?php foreach ($kosans as $k) echo '<option value="' . $k['id_kosan'] . '">' . e($k['nama_kos']) . '</option>'; ?>
        </select>
      <?php endif; ?>
    </label>

    <label class="field">Nomor kamar
      <input type="text" name="nomor_kamar" placeholder="Contoh: A-01" maxlength="20" required value="<?= e($edit['nomor_kamar'] ?? '') ?>">
    </label>

    <label class="field">Harga per bulan (Rp)
      <input type="text" name="harga" inputmode="numeric" placeholder="1200000" required value="<?= $edit ? (int)$edit['harga_per_bulan'] : '' ?>">
    </label>

    <label class="field">Status kamar
      <?php if ($edit && $edit['occupant']): ?>
        <input type="text" disabled value="<?= e($edit['status_kamar']) ?> (Terisi penghuni)">
      <?php else: ?>
        <select name="status">
          <?php foreach (['AVAILABLE' => 'Tersedia', 'ON_HOLD' => 'Ditahan', 'OCCUPIED' => 'Terisi'] as $v => $l) echo '<option value="' . $v . '"' . (($edit['status_kamar'] ?? 'AVAILABLE') === $v ? ' selected' : '') . '>' . $l . '</option>'; ?>
        </select>
      <?php endif; ?>
    </label>
  </div>

  <!-- Checkbox Fasilitas Modern -->
  <div class="mr-section-lbl">
    <span>Fasilitas Kamar</span>
    <span class="hint">Pilih satu atau lebih fasilitas yang tersedia</span>
  </div>
  <div class="fac-grid">
    <?php foreach ($facs as $f): ?>
      <label class="fac-option">
        <input type="checkbox" name="fac[]" value="<?= $f['id_facility'] ?>" <?= in_array((int)$f['id_facility'], $editFac, true) ? 'checked' : '' ?>>
        <span><?= e($f['nama_fasilitas']) ?></span>
      </label>
    <?php endforeach; ?>
  </div>

  <!-- Foto Saat Ini (Mode Edit) -->
  <?php if ($ePhotos): ?>
    <div class="mr-section-lbl">
      <span>Foto saat ini</span>
      <span class="hint">Centang untuk menghapus foto lama</span>
    </div>
    <div class="photo-edit-grid">
      <?php foreach ($ePhotos as $i => $ph): ?>
        <div class="photo-item">
          <img src="<?= e(url($ph)) ?>" alt="Foto kamar">
          <label class="photo-del-lbl">
            <input type="checkbox" name="del_photo[]" value="<?= $i ?>">
            <span>Hapus</span>
          </label>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Unggah Foto Modern -->
  <div class="mr-section-lbl">
    <span>Foto Kamar</span>
    <span class="hint">Format JPG, PNG, WebP (boleh lebih dari satu)</span>
  </div>
  <label class="upload-zone">
    <div class="upload-ico">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
        <polyline points="17 8 12 3 7 8"></polyline>
        <line x1="12" y1="3" x2="12" y2="15"></line>
      </svg>
    </div>
    <div class="upload-text">
      <strong id="file-label">Klik untuk memilih foto kamar</strong>
      <span id="file-sub">Belum ada file baru yang dipilih</span>
    </div>
    <input type="file" name="foto[]" accept="image/*" multiple onchange="updateFileLabel(this)">
  </label>

  <div class="actions">
    <button class="btn" type="submit"><?= $edit ? 'Simpan perubahan' : 'Tambah kamar' ?></button>
    <?php if ($edit): ?>
      <a class="btn btn-outline" href="<?= e(url('admin/manage_rooms.php')) ?>">Batal</a>
    <?php endif; ?>
  </div>
</form>

<!-- Daftar Kamar -->
<div class="card mr-card">
  <div class="row-between" style="margin-bottom: 1rem;">
    <h3 style="margin:0">Daftar kamar</h3>
    <span class="small muted">Total <?= count($rooms) ?> unit</span>
  </div>
  <div class="table-wrap">
    <table class="table tint">
      <thead>
        <tr>
          <th>Kosan</th>
          <th>Kamar</th>
          <th>Harga / Bulan</th>
          <th>Status</th>
          <th>Fasilitas</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rooms as $r): ?>
          <tr>
            <td><?= e($r['nama_kos']) ?></td>
            <td><b><?= e($r['nomor_kamar']) ?></b></td>
            <td><?= rupiah($r['harga_per_bulan']) ?></td>
            <td><?= statusBadge('room', $r['status_kamar']) ?></td>
            <td>
              <?php if ($r['fas']): ?>
                <div class="fas-pills">
                  <?php foreach (explode(', ', $r['fas']) as $tag): ?>
                    <span class="fas-tag"><?= e($tag) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="muted small">-</span>
              <?php endif; ?>
            </td>
            <td class="actions">
              <a class="btn btn-outline btn-sm" href="<?= e(url('admin/manage_rooms.php?edit=' . $r['id_room'])) ?>">Ubah</a>
              <form method="post" class="inline" data-confirm="Hapus kamar <?= e($r['nomor_kamar']) ?>?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id_room" value="<?= $r['id_room'] ?>">
                <button class="btn btn-red btn-sm">Hapus</button>
              </form>
            </td>
          </tr>
        <?php endforeach; if (!$rooms): ?>
          <tr><td colspan="6" class="muted">Belum ada kamar.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function updateFileLabel(input) {
  const lbl = document.getElementById('file-label');
  const sub = document.getElementById('file-sub');
  if (input.files && input.files.length > 0) {
    lbl.textContent = input.files.length + ' foto siap diunggah';
    sub.textContent = Array.from(input.files).map(f => f.name).join(', ');
  } else {
    lbl.textContent = 'Klik untuk memilih foto kamar';
    sub.textContent = 'Belum ada file baru yang dipilih';
  }
}
</script>

<?php app_end(); ?>