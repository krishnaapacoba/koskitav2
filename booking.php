<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/layout.php';
$u = checkLogin();                                   // wajib login/daftar untuk memesan
$idRoom = (int)($_GET['room'] ?? $_POST['room'] ?? 0);
if ($u['role'] !== 'USER') { flash('error', 'Hanya calon penghuni yang dapat memesan kamar.'); redirect(homeFor($u)); }
if ($u['user_status'] !== 'GUEST' || $u['id_room']) {
    flash('info', $u['id_room'] ? 'Anda sudah memiliki pesanan kamar.' : 'Akun Anda tidak dapat memesan kamar.');
    if (!$u['id_room']) redirect('index.php');
    redirect(in_array($u['user_status'], ['GUEST', 'PENDING_VERIFICATION'], true) ? 'onboarding.php' : homeFor($u));
}
$db = db();
$st = $db->prepare('SELECT r.*,k.nama_kos,k.kategori,k.lokasi FROM rooms r JOIN kosan k USING(id_kosan) WHERE r.id_room=?');
$st->execute([$idRoom]); $room = $st->fetch();
if (!$room) { flash('error', 'Kamar tidak ditemukan.'); redirect('index.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $db->beginTransaction();
        $l = $db->prepare('SELECT status_kamar FROM rooms WHERE id_room=? FOR UPDATE'); $l->execute([$idRoom]);
        if ($l->fetchColumn() !== 'AVAILABLE') throw new RuntimeException('Maaf, kamar ini sudah tidak tersedia.');
        $db->prepare("UPDATE rooms SET status_kamar='ON_HOLD' WHERE id_room=?")->execute([$idRoom]);
        $db->prepare("UPDATE users SET id_room=?, user_status='GUEST' WHERE id_user=?")->execute([$idRoom, $u['id_user']]);
        $db->commit();
        flash('success', 'Kamar berhasil ditahan untuk Anda. Lengkapi berkas identitas agar pemesanan diproses.');
        redirect('onboarding.php');
    } catch (Throwable $t) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', $t instanceof RuntimeException ? $t->getMessage() : 'Pemesanan gagal. Coba lagi.');
        redirect('index.php#kamar');
    }
}
if ($room['status_kamar'] !== 'AVAILABLE') { flash('error', 'Kamar ini tidak tersedia untuk dipesan.'); redirect('index.php#kamar'); }
$photos = jsonList($room['foto_kamar']);
public_header('Konfirmasi pemesanan'); ?>
<div class="container narrow" style="padding:0">
<h1>Konfirmasi pemesanan</h1><p class="sub">Kamar akan ditahan untuk Anda selama berkas identitas diverifikasi.</p>
<div class="card">
  <?php if ($photos): ?><div class="room-photo" style="margin-bottom:1rem"><img src="<?= e(url($photos[0])) ?>" alt=""></div><?php endif; ?>
  <div class="room-title"><span style="font-size:1.2rem"><?= e($room['nama_kos']) ?> · <?= e($room['nomor_kamar']) ?></span><?= badge(kategoriLabel($room['kategori'])) ?></div>
  <p class="muted"><?= e($room['lokasi']) ?></p>
  <p class="price"><?= rupiah($room['harga_per_bulan']) ?> <small>/bulan</small></p>
  <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="room" value="<?= (int)$idRoom ?>">
    <button class="btn" type="submit">Pesan kamar ini</button><a class="btn btn-outline" href="<?= e(url('index.php#kamar')) ?>">Batal</a></form>
</div></div>
<?php public_footer();
