<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/layout.php';
$u = currentUser();
$db = db();

$kat = $_GET['kategori'] ?? '';
$max = (int)preg_replace('/\D/', '', $_GET['max'] ?? '');

$selectedFac = [];
if (isset($_GET['fasilitas'])) {
    if (is_array($_GET['fasilitas'])) {
        $selectedFac = array_values(array_unique(array_filter(array_map('intval', $_GET['fasilitas']))));
    } elseif ((int)$_GET['fasilitas'] > 0) {
        $selectedFac = [(int)$_GET['fasilitas']];
    }
}

$where = [];
$p = [];
if (in_array($kat, ['PUTRA', 'PUTRI', 'KELUARGA', 'CAMPUR'], true)) {
    $where[] = 'k.kategori=CAST(? AS room_category_enum)';
    $p[] = $kat;
}
foreach ($selectedFac as $fid) {
    $where[] = 'EXISTS(SELECT 1 FROM room_facilities rf WHERE rf.id_room=r.id_room AND rf.id_facility=?)';
    $p[] = $fid;
}
if ($max) {
    $where[] = 'r.harga_per_bulan<=?';
    $p[] = $max;
}

$st = $db->prepare("SELECT r.*,k.nama_kos,k.kategori,k.lokasi FROM rooms r JOIN kosan k ON k.id_kosan=r.id_kosan"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . " ORDER BY (r.status_kamar='AVAILABLE') DESC,k.nama_kos,r.nomor_kamar");
$st->execute($p);
$rooms = $st->fetchAll();

$facMap = [];
if ($rooms) {
    $fs = $db->prepare('SELECT rf.id_room,f.nama_fasilitas FROM room_facilities rf JOIN facilities f USING(id_facility) WHERE rf.id_room=ANY(CAST(? AS int[])) ORDER BY f.nama_fasilitas');
    $fs->execute([pgArr(array_column($rooms, 'id_room'))]);
    foreach ($fs->fetchAll() as $f) {
        $facMap[$f['id_room']][] = $f['nama_fasilitas'];
    }
}

$facs = allFacilities();
$hasUser = (bool)$u;
$isEligibleGuest = ($u && $u['role'] === 'USER' && $u['user_status'] === 'GUEST' && empty($u['id_room']));
$canBook = (!$hasUser or $isEligibleGuest);

public_header('Temukan kamar kos');
?>

<style>
  body.public { background: #F6F8F4; }
  body.public .topbar { background: #ffffff; padding: 1rem 3.5vw; }
  body.public main.container { max-width: 1480px; width: 100%; padding: 2.2rem 3.5vw 4rem; }

  /* Hero Banner Gradien Sage Lembut */
  .landing-hero {
    background: linear-gradient(135deg, #EEF4ED 0%, #E2F1DB 55%, #D5E8CE 100%);
    border: 1.5px solid #DCE5D6;
    border-radius: 24px;
    padding: 2.4rem 2.8rem;
    margin-bottom: 1.75rem;
  }
  .landing-hero h1 { font-size: clamp(1.9rem, 3vw, 2.6rem); color: #2A322B; line-height: 1.18; margin: 0 0 .55rem; }
  .landing-hero p { font-size: 1.04rem; color: #4A584B; margin: 0; max-width: 720px; }

  /* Card Filter Terpadu */
  .filter-box { background: #ffffff; border: 1px solid #E5E2D9; border-radius: 22px; padding: 1.5rem 1.75rem; margin-bottom: 2.1rem; box-shadow: 0 4px 20px rgba(42, 50, 43, .03); }
  .filter-top-grid {
    display: grid;
    grid-template-columns: 1fr 1fr auto auto;
    gap: 1rem;
    align-items: end;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid #EFECE4;
  }
  .filter-top-grid .field { margin: 0; }
  .filter-top-grid select,
  .filter-top-grid input { background: #FAF9F5; }

  .filter-sub-lbl { display: block; font-size: .85rem; font-weight: 700; color: var(--ink); margin-bottom: .6rem; }
  .filter-sub-lbl span { font-weight: 400; color: var(--muted); font-size: .8rem; margin-left: .35rem; }

  /* Baris 2: Custom Checkbox Fasilitas Multi-Select */
  .filter-fac-row { padding-top: 1.2rem; }
  .fac-check-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(195px, 1fr)); gap: .65rem; }
  .fac-check-item { position: relative; display: flex; align-items: center; gap: .65rem; padding: .72rem .95rem; border-radius: 13px; border: 1.5px solid #E2DFD5; background: #FAF9F5; color: var(--ink); font-size: .9rem; font-weight: 500; cursor: pointer; user-select: none; transition: all .15s ease; }
  .fac-check-item:hover { border-color: #B4C89F; background: #EEF4ED; }
  .fac-check-item input[type="checkbox"] { appearance: none; -webkit-appearance: none; width: 19px; height: 19px; margin: 0; border-radius: 6px; border: 1.8px solid #CBD5E1; background: #fff; display: inline-grid; place-content: center; flex-shrink: 0; cursor: pointer; transition: all .15s ease; }
  .fac-check-item input[type="checkbox"]::before { content: ""; width: 10px; height: 10px; transform: scale(0); transition: transform .12s ease-in-out; box-shadow: inset 1em 1em #fff; clip-path: polygon(14% 44%, 0 65%, 50% 100%, 100% 16%, 80% 0%, 43% 62%); }
  .fac-check-item input[type="checkbox"]:checked { background: var(--teal); border-color: var(--teal); }
  .fac-check-item input[type="checkbox"]:checked::before { transform: scale(1); }
  .fac-check-item:has(input:checked) { background: var(--soft); border-color: var(--teal); color: var(--teal-d); font-weight: 700; }

  /* Grid Kamar */
  .rooms-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap: 1.5rem; }
  .room-card { border-radius: 20px; padding: 1.25rem; transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
  .room-card:not(.is-unavailable):hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(42, 50, 43, .06); border-color: #B4C89F; }
  .room-photo { height: 190px; background: #EEF4ED; color: #5B7457; font-weight: 600; }
  .room-photo-empty { display: flex; flex-direction: column; align-items: center; gap: .4rem; opacity: .8; }
  .room-card .tags .badge { background: #EEF4ED; color: #3F5141; }
  .room-foot .btn { white-space: nowrap; }

  @media (max-width: 820px) {
    .landing-hero { padding: 1.8rem 1.5rem; }
    .filter-top-grid { grid-template-columns: 1fr 1fr; }
  }
  @media (max-width: 560px) {
    .filter-box { padding: 1.2rem; }
    .filter-top-grid { grid-template-columns: 1fr; }
  }
</style>

<section class="landing-hero">
  <h1>Temukan kamar kos yang cocok untuk aktivitasmu</h1>
  <p><?= $u ? 'Pilih unit kamar yang tersedia di bawah ini, lalu ajukan pemesanan dengan mudah.' : 'Cek ketersediaan unit, fasilitas, dan harga secara langsung tanpa perlu login. Masuk hanya saat siap memesan.' ?></p>
</section>

<?php if ($u) candidate_banner($u); ?>

<form class="filter-box" method="get" action="<?= e(url('index.php')) ?>#kamar" id="filter">
  <div class="filter-top-grid">
    <label class="field">Kategori
      <select name="kategori">
        <option value="" <?= $kat === '' ? 'selected' : '' ?>>Semua Unit</option>
        <?php foreach (['PUTRA', 'PUTRI', 'KELUARGA', 'CAMPUR'] as $c): ?>
          <option value="<?= $c ?>" <?= $kat === $c ? 'selected' : '' ?>><?= kategoriLabel($c) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="field">Harga maks.
      <input type="text" name="max" inputmode="numeric" placeholder="Rp 2.000.000" value="<?= $max ? e($max) : '' ?>">
    </label>

    <button class="btn" type="submit">Cari kamar</button>

    <?php if ($kat !== '' or !empty($selectedFac) or $max > 0): ?>
      <a href="<?= e(url('index.php')) ?>#kamar" class="btn btn-outline">Reset</a>
    <?php endif; ?>
  </div>

  <?php if ($facs): ?>
    <div class="filter-fac-row" id="fasilitas">
      <span class="filter-sub-lbl">Filter Fasilitas <span>(bisa pilih lebih dari satu)</span></span>
      <div class="fac-check-grid">
        <?php foreach ($facs as $f): ?>
          <label class="fac-check-item">
            <input type="checkbox" name="fasilitas[]" value="<?= $f['id_facility'] ?>" <?= in_array((int)$f['id_facility'], $selectedFac, true) ? 'checked' : '' ?>>
            <span><?= e($f['nama_fasilitas']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</form>

<div class="row-between" id="kamar" style="margin-bottom:1.15rem">
  <div>
    <h2 style="margin:0 0 .15rem">Daftar Unit Kamar</h2>
    <span class="muted small">Menampilkan <?= count($rooms) ?> unit kamar</span>
  </div>
  <span class="muted small">Unit berstatus <b>Ditahan</b> atau <b>Terisi</b> tidak dapat dipesan</span>
</div>

<div class="rooms-grid">
<?php foreach ($rooms as $r):
    $avail = ($r['status_kamar'] === 'AVAILABLE');
    $photos = jsonList($r['foto_kamar']);
?>
  <article class="room-card <?= $avail ? '' : 'is-unavailable' ?>">
    <div class="room-photo">
      <?php if ($photos): ?>
        <img src="<?= e(url($photos[0])) ?>" alt="Kamar <?= e($r['nomor_kamar']) ?>">
      <?php else: ?>
        <div class="room-photo-empty">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
          <span>Foto belum tersedia</span>
        </div>
      <?php endif; ?>
      <?php if (!$avail): ?><span class="ribbon"><?= statusBadge('room', $r['status_kamar']) ?></span><?php endif; ?>
    </div>
    <div class="room-title"><span><?= e($r['nama_kos']) ?></span><?= badge(kategoriLabel($r['kategori']), 'teal') ?></div>
    <div class="room-meta">Kamar <b><?= e($r['nomor_kamar']) ?></b> · <?= e($r['lokasi']) ?></div>
    <div class="tags"><?php foreach ($facMap[$r['id_room']] ?? [] as $f) echo badge($f); ?></div>
    <div class="room-foot">
      <div class="price"><?= rupiah($r['harga_per_bulan']) ?> <small>/bulan</small></div>
      <?php if ($avail and $canBook): ?>
        <a class="btn" href="<?= e(url('booking.php?room=' . $r['id_room'])) ?>">Pesan Kamar</a>
      <?php else: ?>
        <span class="btn disabled" title="<?= $avail ? 'Anda tidak dapat memesan saat ini' : 'Kamar tidak tersedia' ?>"><?= $avail ? 'Pesan' : ($r['status_kamar'] === 'ON_HOLD' ? 'Ditahan' : 'Terisi') ?></span>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
<?php if (!$rooms): ?>
  <div class="card" style="grid-column: 1 / -1; text-align:center; padding: 2.5rem;">
    <p class="muted" style="margin:0 0 .75rem">Tidak ada kamar yang cocok dengan kriteria pencarian Anda.</p>
    <a href="<?= e(url('index.php')) ?>#kamar" class="btn btn-outline btn-sm">Tampilkan Semua Kamar</a>
  </div>
<?php endif; ?>
</div>

<?php public_footer(); ?>