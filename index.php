<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/layout.php';
$u = currentUser(); $db = db();

$kat = $_GET['kategori'] ?? ''; $fas = (int)($_GET['fasilitas'] ?? 0); $max = (int)preg_replace('/\D/', '', $_GET['max'] ?? '');
$where = []; $p = [];
if (in_array($kat, ['PUTRA', 'PUTRI', 'KELUARGA', 'CAMPUR'], true)) { $where[] = 'k.kategori=CAST(? AS room_category_enum)'; $p[] = $kat; }
if ($fas) { $where[] = 'EXISTS(SELECT 1 FROM room_facilities rf WHERE rf.id_room=r.id_room AND rf.id_facility=?)'; $p[] = $fas; }
if ($max) { $where[] = 'r.harga_per_bulan<=?'; $p[] = $max; }
$st = $db->prepare("SELECT r.*,k.nama_kos,k.kategori,k.lokasi FROM rooms r JOIN kosan k ON k.id_kosan=r.id_kosan"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY (r.status_kamar='AVAILABLE') DESC,k.nama_kos,r.nomor_kamar");
$st->execute($p); $rooms = $st->fetchAll();

$facMap = [];
if ($rooms) {
    $fs = $db->prepare('SELECT rf.id_room,f.nama_fasilitas FROM room_facilities rf JOIN facilities f USING(id_facility) WHERE rf.id_room=ANY(CAST(? AS int[])) ORDER BY f.nama_fasilitas');
    $fs->execute([pgArr(array_column($rooms, 'id_room'))]);
    foreach ($fs->fetchAll() as $f) $facMap[$f['id_room']][] = $f['nama_fasilitas'];
}
$facs = allFacilities();
$canBook = !$u || ($u['role'] === 'USER' && $u['user_status'] === 'GUEST' && !$u['id_room']);

public_header('Temukan kamar kos');
?>
<section class="hero">
  <h1>Temukan kamar kos yang cocok</h1>
  <p><?= $u ? 'Pilih kamar yang tersedia, lalu ajukan pemesanan.' : 'Lihat kamar tersedia tanpa perlu login. Masuk hanya saat siap memesan.' ?></p>
</section>
<?php if ($u) candidate_banner($u); ?>

<form class="filter" method="get" action="<?= e(url('index.php')) ?>#kamar" id="filter">
  <label class="field">Kategori
    <select name="kategori"><option value="">Semua</option>
    <?php foreach (['PUTRA', 'PUTRI', 'KELUARGA', 'CAMPUR'] as $c): ?><option value="<?= $c ?>" <?= $kat === $c ? 'selected' : '' ?>><?= kategoriLabel($c) ?></option><?php endforeach; ?></select></label>
  <label class="field">Fasilitas
    <select name="fasilitas"><option value="">Semua fasilitas</option>
    <?php foreach ($facs as $f): ?><option value="<?= $f['id_facility'] ?>" <?= $fas == $f['id_facility'] ? 'selected' : '' ?>><?= e($f['nama_fasilitas']) ?></option><?php endforeach; ?></select></label>
  <label class="field">Harga maks.<input type="text" name="max" inputmode="numeric" placeholder="Rp 2.000.000" value="<?= $max ? e($max) : '' ?>"></label>
  <button class="btn" type="submit">Cari kamar</button>
</form>

<div class="row-between" id="kamar" style="margin-bottom:1rem">
  <h2 style="margin:0">Kamar</h2><span class="muted small">Kamar ditahan atau terisi ditampilkan abu-abu dan tidak dapat dipesan</span>
</div>
<div class="rooms">
<?php foreach ($rooms as $r):
    $avail = $r['status_kamar'] === 'AVAILABLE'; $photos = jsonList($r['foto_kamar']); ?>
  <article class="room-card <?= $avail ? '' : 'is-unavailable' ?>">
    <div class="room-photo">
      <?php if ($photos): ?><img src="<?= e(url($photos[0])) ?>" alt="Kamar <?= e($r['nomor_kamar']) ?>"><?php else: ?>Foto kamar<?php endif; ?>
      <?php if (!$avail): ?><span class="ribbon"><?= statusBadge('room', $r['status_kamar']) ?></span><?php endif; ?>
    </div>
    <div class="room-title"><span><?= e($r['nama_kos']) ?></span><?= badge(kategoriLabel($r['kategori'])) ?></div>
    <div class="room-meta">Kamar <?= e($r['nomor_kamar']) ?> · <?= e($r['lokasi']) ?></div>
    <div class="tags"><?php foreach ($facMap[$r['id_room']] ?? [] as $f) echo badge($f); ?></div>
    <div class="room-foot">
      <div class="price"><?= rupiah($r['harga_per_bulan']) ?> <small>/bulan</small></div>
      <?php if ($avail && $canBook): ?>
        <a class="btn" href="<?= e(url('booking.php?room=' . $r['id_room'])) ?>">Pesan</a>
      <?php else: ?>
        <span class="btn disabled" title="<?= $avail ? 'Anda tidak dapat memesan saat ini' : 'Kamar tidak tersedia' ?>"><?= $avail ? 'Pesan' : ($r['status_kamar'] === 'ON_HOLD' ? 'Ditahan' : 'Terisi') ?></span>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; if (!$rooms): ?><p class="muted">Tidak ada kamar yang cocok dengan filter Anda.</p><?php endif; ?>
</div>

<section id="fasilitas" style="margin-top:3rem">
  <h2>Fasilitas kamar</h2>
  <div class="tags"><?php foreach ($facs as $f): ?><a href="<?= e(url('index.php?fasilitas=' . $f['id_facility'] . '#kamar')) ?>"><?= badge($f['nama_fasilitas']) ?></a><?php endforeach; ?></div>
</section>
<?php public_footer();
