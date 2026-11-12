<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = checkRole(['SUPERADMIN']); $db = db(); $cats = ['PUTRA', 'PUTRI', 'KELUARGA', 'CAMPUR'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $act = $_POST['action'] ?? ''; $id = (int)($_POST['id_kosan'] ?? 0);
    if ($act === 'delete') {
        $c = $db->prepare('SELECT COUNT(*) FROM users u JOIN rooms r ON r.id_room=u.id_room WHERE r.id_kosan=?'); $c->execute([$id]);
        if ($c->fetchColumn() > 0) flash('error', 'Kosan masih memiliki penghuni/pemesan aktif, tidak dapat dihapus.');
        else { $db->prepare('DELETE FROM kosan WHERE id_kosan=?')->execute([$id]); flash('success', 'Kosan dihapus.'); }
    } else {
        $nama = clean($_POST['nama'] ?? ''); $kat = $_POST['kategori'] ?? ''; $lok = clean($_POST['lokasi'] ?? ''); $des = clean($_POST['deskripsi'] ?? '');
        if ($nama === '' || $lok === '' || !in_array($kat, $cats, true)) { flash('error', 'Nama, kategori, dan lokasi wajib diisi.'); redirect('superadmin/manage_kosan.php' . ($id ? '?edit=' . $id : '')); }
        if ($id) $db->prepare('UPDATE kosan SET nama_kos=?,kategori=CAST(? AS room_category_enum),lokasi=?,deskripsi=? WHERE id_kosan=?')->execute([$nama, $kat, $lok, $des ?: null, $id]);
        else $db->prepare('INSERT INTO kosan(nama_kos,kategori,lokasi,deskripsi) VALUES (?,CAST(? AS room_category_enum),?,?)')->execute([$nama, $kat, $lok, $des ?: null]);
        flash('success', $id ? 'Data kosan diperbarui.' : 'Kosan ditambahkan.');
    }
    redirect('superadmin/manage_kosan.php');
}
$edit = null; if (isset($_GET['edit'])) { $s = $db->prepare('SELECT * FROM kosan WHERE id_kosan=?'); $s->execute([(int)$_GET['edit']]); $edit = $s->fetch() ?: null; }
$rows = $db->query('SELECT * FROM kosan ORDER BY id_kosan')->fetchAll();

app_start('SUPERADMIN', 'kosan', 'Kelola Properti Kos', 'Tambah, ubah, atau hapus data kosan pada sistem KosKita.'); ?>
<form class="card" method="post"><?= csrf_field() ?><input type="hidden" name="id_kosan" value="<?= (int)($edit['id_kosan'] ?? 0) ?>">
  <h3><?= $edit ? 'Ubah kosan #' . (int)$edit['id_kosan'] : 'Tambah kosan baru' ?></h3>
  <div class="grid g2"><label class="field">Nama kosan<input type="text" name="nama" placeholder="Contoh: Kos Executive" required maxlength="100" value="<?= e($edit['nama_kos'] ?? '') ?>"></label>
  <label class="field">Kategori<select name="kategori"><?php foreach ($cats as $c) echo '<option value="' . $c . '"' . (($edit['kategori'] ?? '') === $c ? ' selected' : '') . '>' . kategoriLabel($c) . '</option>'; ?></select></label></div>
  <label class="field">Lokasi / alamat lengkap<input type="text" name="lokasi" placeholder="Jl. Nama Jalan No. 1, Kota" required value="<?= e($edit['lokasi'] ?? '') ?>"></label>
  <label class="field">Deskripsi properti<textarea name="deskripsi" placeholder="Lokasi strategis dan fasilitas umum kosan"><?= e($edit['deskripsi'] ?? '') ?></textarea></label>
  <div class="actions"><button class="btn"><?= $edit ? 'Simpan perubahan' : 'Tambah kosan' ?></button><?php if ($edit): ?><a class="btn btn-outline" href="<?= e(url('superadmin/manage_kosan.php')) ?>">Batal</a><?php endif; ?></div></form>
<div class="card"><h3>Daftar kosan terdaftar</h3><div class="table-wrap"><table class="table tint"><thead><tr><th>ID</th><th>Nama kosan</th><th>Kategori</th><th>Lokasi</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><b>#<?= $r['id_kosan'] ?></b></td><td><?= e($r['nama_kos']) ?></td><td><?= badge(kategoriLabel($r['kategori'])) ?></td><td><?= e($r['lokasi']) ?></td>
  <td class="actions"><a class="btn btn-outline btn-sm" href="<?= e(url('superadmin/manage_kosan.php?edit=' . $r['id_kosan'])) ?>">Ubah</a>
  <form method="post" class="inline" data-confirm="Hapus kosan <?= e($r['nama_kos']) ?> beserta seluruh kamarnya?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id_kosan" value="<?= $r['id_kosan'] ?>"><button class="btn btn-red btn-sm">Hapus</button></form></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php app_end();
