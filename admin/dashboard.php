<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = checkRole(['ADMIN']);
$db = db();
$ids = adminKosanIds();
$arr = pgArr($ids);

$q = function ($sql) use ($db, $arr) {
    $s = $db->prepare($sql);
    $s->execute([':ids' => $arr]);
    return $s;
};

$names = $q('SELECT nama_kos FROM kosan WHERE id_kosan=ANY(CAST(:ids AS int[])) ORDER BY nama_kos')->fetchAll(PDO::FETCH_COLUMN);

$berkas = $q("SELECT u.id_user, u.nama_lengkap, r.nomor_kamar,
    (SELECT string_agg(d.document_type::text, ', ') FROM user_documents d WHERE d.id_user=u.id_user AND d.status='PENDING') AS docs
    FROM users u JOIN rooms r ON r.id_room=u.id_room 
    WHERE u.user_status='PENDING_VERIFICATION' AND r.id_kosan=ANY(CAST(:ids AS int[]))")->fetchAll();

$bayar = $q("SELECT p.*, u.nama_lengkap FROM payments p JOIN users u USING(id_user) 
    WHERE p.payment_status='PENDING' AND p.id_kosan=ANY(CAST(:ids AS int[])) ORDER BY p.id_payment")->fetchAll();

$tiketAktif = $q("SELECT t.*, u.nama_lengkap, r.nomor_kamar, k.nama_kos
    FROM tickets t
    JOIN users u ON u.id_user = t.id_user
    LEFT JOIN rooms r ON r.id_room = u.id_room
    LEFT JOIN kosan k ON k.id_kosan = t.id_kosan
    WHERE t.status IN ('OPEN', 'IN_PROGRESS') AND t.id_kosan=ANY(CAST(:ids AS int[]))
    ORDER BY CASE t.status WHEN 'OPEN' THEN 1 ELSE 2 END, t.created_at DESC")->fetchAll();

$tiketOpen = array_filter($tiketAktif, function ($t) {
    return $t['status'] === 'OPEN';
});

$allRooms = $q("SELECT r.id_room, r.id_kosan, r.nomor_kamar, r.status_kamar, r.harga_per_bulan,
    k.nama_kos, u.nama_lengkap AS penghuni
    FROM rooms r
    JOIN kosan k ON k.id_kosan = r.id_kosan
    LEFT JOIN users u ON u.id_room = r.id_room
    WHERE r.id_kosan=ANY(CAST(:ids AS int[]))
    ORDER BY k.nama_kos, r.nomor_kamar")->fetchAll();

$totalKamar = count($allRooms);
$countOccupied = 0;
$countAvailable = 0;
$countOnHold = 0;
$roomsByGroup = [];

foreach ($allRooms as $rm) {
    if ($rm['status_kamar'] === 'OCCUPIED') {
        $countOccupied++;
    } elseif ($rm['status_kamar'] === 'ON_HOLD') {
        $countOnHold++;
    } else {
        $countAvailable++;
    }

    $groupName = strtoupper($rm['nama_kos']);
    $roomsByGroup[$groupName][] = $rm;
}
$okupansiPersen = $totalKamar > 0 ? round(($countOccupied / $totalKamar) * 100) : 0;

app_start('ADMIN', 'dashboard', 'Admin · ' . ($names ? implode(', ', $names) : 'Belum ada kosan'));
?>

<style>
  .dash-bar { height: 6px; background: #ece8de; border-radius: 999px; overflow: hidden; margin-top: .55rem; }
  .dash-bar > div { height: 100%; background: #0f766e; border-radius: 999px; }

  .sk-wrap { background: #fff; border: 1px solid #e5e0d5; border-radius: 18px; padding: 1.5rem; margin-bottom: 1.25rem; }
  .sk-top { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.35rem; }
  .sk-head-left { display: flex; align-items: center; gap: .85rem; }
  .sk-badge-ico { width: 42px; height: 42px; border-radius: 12px; background: #eef2ff; color: #3b5bfd; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .sk-heading { font-size: 1.15rem; font-weight: 800; color: #111827; margin: 0; }
  .sk-desc { font-size: .84rem; color: #6b7280; margin-top: .1rem; }

  .sk-filter { display: inline-flex; background: #f3f4f6; padding: .28rem; border-radius: 12px; gap: .2rem; flex-wrap: wrap; }
  .sk-filter button { border: 0; background: transparent; padding: .4rem .9rem; border-radius: 9px; font: 600 .8rem Inter, sans-serif; color: #4b5563; cursor: pointer; }
  .sk-filter button.active { background: #fff; color: #2563eb; box-shadow: 0 1px 3px rgba(0,0,0,.08); }

  .sk-sec { margin-bottom: 1.25rem; }
  .sk-sec-head { display: flex; justify-content: space-between; align-items: center; font-size: .75rem; font-weight: 700; color: #6b7280; letter-spacing: .04em; margin-bottom: .65rem; }

  .sk-boxes { display: flex; flex-wrap: wrap; gap: .6rem; }
  .sk-item { width: 84px; height: 76px; border-radius: 13px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; transition: transform .12s ease, box-shadow .12s ease; }
  .sk-item:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.07); }
  .sk-item strong { font-size: 1rem; font-weight: 800; line-height: 1.2; }
  .sk-item span { font-size: .73rem; font-weight: 600; margin-top: .22rem; }

  .sk-item.is-terisi  { background: #d5f5e6; color: #0b6e4f; }
  .sk-item.is-kosong  { background: #e6ebfc; color: #2643c4; }
  .sk-item.is-ditahan { background: #fce6e6; color: #b91c1c; }

  .sk-foot { display: flex; align-items: center; gap: 1.35rem; flex-wrap: wrap; background: #f9fafb; border-radius: 12px; padding: .75rem 1.1rem; margin-top: .5rem; }
  .sk-foot-lbl { font-size: .75rem; font-weight: 700; color: #6b7280; letter-spacing: .04em; }
  .sk-leg { display: inline-flex; align-items: center; gap: .45rem; font-size: .82rem; font-weight: 600; color: #1f2937; }
  .sk-leg i { width: 11px; height: 11px; border-radius: 50%; display: inline-block; }

  .tk-card { background: #faf9f6; border: 1px solid #e5e0d5; border-radius: 14px; padding: 1rem 1.15rem; display: flex; flex-direction: column; justify-content: space-between; }
/* Modal Pratinjau Foto Bukti Tiket di Dashboard */
  .proof-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 999;
    background: rgba(25, 32, 26, .55);
    backdrop-filter: blur(3px);
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
  }
  .proof-modal.is-open { display: flex; }
  .proof-modal-box {
    background: #fff;
    border-radius: 20px;
    max-width: 680px;
    width: 100%;
    padding: 1.5rem;
    box-shadow: 0 20px 45px rgba(0, 0, 0, .18);
  }
  .proof-modal-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: .75rem;
    border-bottom: 1px solid #e5e0d5;
  }
  .proof-modal-head h4 { margin: 0; font-size: 1.05rem; }
  .proof-modal-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: .85rem;
    max-height: 70vh;
    overflow-y: auto;
  }
  .proof-modal-grid a { display: block; border-radius: 12px; overflow: hidden; border: 1px solid #e5e0d5; }
  .proof-modal-grid img { width: 100%; height: 220px; object-fit: cover; display: block; }
</style>

<div class="row-between" style="margin-bottom: 1.25rem;">
  <div class="muted small">Ringkasan operasional, status ketersediaan unit, dan pengaduan penghuni.</div>
  <?php if ($ids) { ?>
    <a href="<?= e(url('admin/manage_rooms.php')) ?>" class="btn btn-sm">+ Tambah Kamar Baru</a>
  <?php } ?>
</div>

<?php if (!$ids) { ?>
  <div class="flash flash-info">Akun Anda belum ditugaskan ke kosan mana pun. Hubungi Superadmin.</div>
<?php } ?>

<div class="grid g4" style="margin-bottom:1.25rem">
  <div class="stat">
    <div class="row-between">
      <span>Kamar terisi</span>
      <span class="badge badge-teal"><?= $okupansiPersen ?>%</span>
    </div>
    <b><?= $countOccupied ?> <small class="muted" style="font-size:1rem;font-weight:500">/ <?= $totalKamar ?></small></b>
    <div class="dash-bar"><div style="width:<?= $okupansiPersen ?>%"></div></div>
    <div class="small muted" style="margin-top:.35rem"><?= $countAvailable ?> kosong · <?= $countOnHold ?> ditahan</div>
  </div>
  <div class="stat">
    <span>Berkas menunggu</span>
    <b><?= count($berkas) ?></b>
    <div class="small muted" style="margin-top:.5rem">Verifikasi calon penghuni</div>
  </div>
  <div class="stat">
    <span>Pembayaran menunggu</span>
    <b><?= count($bayar) ?></b>
    <div class="small muted" style="margin-top:.5rem">Konfirmasi transfer / tunai</div>
  </div>
  <div class="stat">
    <span>Tiket pengaduan</span>
    <b><?= count($tiketAktif) ?></b>
    <div class="small muted" style="margin-top:.5rem"><?= count($tiketOpen) ?> baru · <?= count($tiketAktif) - count($tiketOpen) ?> diproses</div>
  </div>
</div>

<div class="sk-wrap">
  <div class="sk-top">
    <div class="sk-head-left">
      <div class="sk-badge-ico">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="18" height="18" rx="3"></rect>
          <line x1="3" y1="9" x2="21" y2="9"></line>
          <line x1="3" y1="15" x2="21" y2="15"></line>
          <line x1="9" y1="3" x2="9" y2="21"></line>
          <line x1="15" y1="3" x2="15" y2="21"></line>
        </svg>
      </div>
      <div>
        <h3 class="sk-heading">Status Kamar</h3>
        <div class="sk-desc">Denah keterisian unit (Total <?= $totalKamar ?> Kamar)</div>
      </div>
    </div>

    <?php if (count($roomsByGroup) > 1) { ?>
      <div class="sk-filter" id="sk-tabs">
        <button type="button" class="active" data-target="all">Semua Kamar</button>
        <?php foreach (array_keys($roomsByGroup) as $gName) { ?>
          <button type="button" data-target="<?= e(md5($gName)) ?>"><?= e(ucwords(strtolower($gName))) ?></button>
        <?php } ?>
      </div>
    <?php } ?>
  </div>

  <?php if (!$allRooms) { ?>
    <p class="muted small" style="margin:0">Belum ada unit kamar. <a href="<?= e(url('admin/manage_rooms.php')) ?>" class="link">Tambah kamar sekarang</a>.</p>
  <?php } else { ?>
    <?php foreach ($roomsByGroup as $gName => $gRooms) { ?>
      <div class="sk-sec" data-group="<?= e(md5($gName)) ?>">
        <?php if (count($roomsByGroup) > 1) { ?>
          <div class="sk-sec-head">
            <span><?= e($gName) ?></span>
            <span><?= count($gRooms) ?> Unit</span>
          </div>
        <?php } ?>
        <div class="sk-boxes">
          <?php foreach ($gRooms as $rm) {
            $st = $rm['status_kamar'];
            $cls = $st === 'OCCUPIED' ? 'is-terisi' : ($st === 'ON_HOLD' ? 'is-ditahan' : 'is-kosong');
            $label = $st === 'OCCUPIED' ? 'Terisi' : ($st === 'ON_HOLD' ? 'Ditahan' : 'Kosong');
            $tip = $rm['nomor_kamar'] . ' · ' . $label . ($rm['penghuni'] ? ' (' . $rm['penghuni'] . ')' : ' · ' . rupiah($rm['harga_per_bulan']));
          ?>
            <a href="<?= e(url('admin/manage_rooms.php?edit=' . $rm['id_room'])) ?>" class="sk-item <?= $cls ?>" title="<?= e($tip) ?>">
              <strong><?= e($rm['nomor_kamar']) ?></strong>
              <span><?= $label ?></span>
            </a>
          <?php } ?>
        </div>
      </div>
    <?php } ?>

    <div class="sk-foot">
      <span class="sk-foot-lbl">KETERANGAN:</span>
      <span class="sk-leg"><i style="background:#86efac"></i> Terisi (<?= $countOccupied ?>)</span>
      <span class="sk-leg"><i style="background:#c7d2fe"></i> Kosong (<?= $countAvailable ?>)</span>
      <span class="sk-leg"><i style="background:#fecaca"></i> Ditahan (<?= $countOnHold ?>)</span>
    </div>
  <?php } ?>
</div>

<!-- 1. PERLU TINDAKAN VERIFIKASI (DI ATAS) -->
<div class="card">
  <div class="row-between" style="margin-bottom: 1rem;">
    <div>
      <h3 style="margin-bottom:.15rem">Perlu tindakan verifikasi</h3>
      <div class="small muted">Periksa kelengkapan berkas identitas dan bukti pembayaran calon penghuni</div>
    </div>
    <div class="actions">
      <a href="<?= e(url('admin/verify_user.php')) ?>" class="link small">Menu Verifikasi Berkas &rarr;</a>
    </div>
  </div>

  <div class="table-wrap">
    <table class="table" style="min-width: 680px;">
      <thead>
        <tr>
          <th style="width: 18%;">Jenis</th>
          <th style="width: 20%;">Nama</th>
          <th style="width: 27%;">Detail</th>
          <th style="width: 35%; text-align: right;">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($berkas as $b) { ?>
          <tr>
            <td><?= badge('Berkas ' . $b['docs'], 'amber') ?></td>
            <td><b><?= e($b['nama_lengkap']) ?></b></td>
            <td>Kamar <?= e($b['nomor_kamar']) ?> · ditahan</td>
            <td>
              <div class="actions" style="justify-content: flex-end; flex-wrap: nowrap;">
                <a class="btn btn-outline btn-sm" style="white-space: nowrap;" href="<?= e(url('admin/verify_user.php#u' . $b['id_user'])) ?>">Lihat &amp; Periksa</a>
                <form method="post" action="<?= e(url('admin/verify_user.php')) ?>" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="id_user" value="<?= $b['id_user'] ?>">
                  <input type="hidden" name="back" value="dashboard">
                  <button class="btn btn-sm" style="white-space: nowrap;">Setujui</button>
                </form>
                <a class="btn btn-red btn-sm" style="white-space: nowrap;" href="<?= e(url('admin/verify_user.php#u' . $b['id_user'])) ?>">Tolak</a>
              </div>
            </td>
          </tr>
        <?php } ?>

        <?php foreach ($bayar as $p) { ?>
          <tr>
            <td><?= badge('Bayar ' . ($p['payment_method'] === 'CASH' ? 'Tunai' : 'QRIS') . ($p['payment_type'] === 'FIRST_MONTH' ? ' (pertama)' : ''), 'amber') ?></td>
            <td><b><?= e($p['nama_lengkap']) ?></b></td>
            <td><?= rupiah($p['amount']) ?> · <?= $p['payment_method'] === 'QRIS' ? 'bukti terlampir' : 'tunai' ?></td>
            <td>
              <div class="actions" style="justify-content: flex-end; flex-wrap: nowrap;">
                <a class="btn btn-outline btn-sm" style="white-space: nowrap;" href="<?= e(url('admin/verify_payment.php#p' . $p['id_payment'])) ?>">Lihat &amp; Periksa</a>
                <form method="post" action="<?= e(url('admin/verify_payment.php')) ?>" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="id_payment" value="<?= $p['id_payment'] ?>">
                  <input type="hidden" name="back" value="dashboard">
                  <button class="btn btn-sm" style="white-space: nowrap;">Setujui</button>
                </form>
                <a class="btn btn-red btn-sm" style="white-space: nowrap;" href="<?= e(url('admin/verify_payment.php#p' . $p['id_payment'])) ?>">Tolak</a>
              </div>
            </td>
          </tr>
        <?php } ?>

        <?php if (!$berkas && !$bayar) { ?>
          <tr><td colspan="4" class="muted">Tidak ada berkas atau pembayaran yang perlu diverifikasi.</td></tr>
        <?php } ?>
      </tbody>
    </table>
  </div>
</div>

<!-- 2. PENGADUAN FASILITAS TERKINI (DI BAWAH) -->
<div class="card">
  <div class="row-between" style="margin-bottom: 1rem;">
    <div>
      <h3 style="margin-bottom:.15rem">Pengaduan Fasilitas Terkini</h3>
      <div class="small muted">Penanganan keluhan kerusakan &amp; pemeliharaan kamar aktif</div>
    </div>
    <a href="<?= e(url('admin/tickets.php')) ?>" class="link small">Lihat Semua Tiket &rarr;</a>
  </div>

  <div class="table-wrap">
    <table class="table" style="min-width: 780px;">
      <thead>
        <tr>
          <th style="width: 16%;">Tiket &amp; Tanggal</th>
          <th style="width: 19%;">Penghuni &amp; Kamar</th>
          <th style="width: 28%;">Kendala &amp; Fasilitas</th>
          <th style="width: 11%; text-align: center;">Bukti</th>
          <th style="width: 12%;">Status</th>
          <th style="width: 14%; text-align: right;">Tindakan</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$tiketAktif) { ?>
          <tr><td colspan="6" class="muted">Tidak ada keluhan aktif saat ini. Semua fasilitas terpantau aman.</td></tr>
        <?php } else { ?>
          <?php foreach (array_slice($tiketAktif, 0, 5) as $t) {
            $rawSubj = $t['subject'];
            $tkTitle = '';
            $tkFacs = [];
            if (preg_match('/^\[FAC:(.*?)\]\s*(.*)$/s', $rawSubj, $m)) {
                $facStr = trim($m[1]);
                $tClean = trim($m[2]);
                $tkTitle = ($tClean !== '' && $tClean !== '-') ? $tClean : '';
                if ($facStr !== '' && $facStr !== '-') {
                    $tkFacs = array_map('trim', explode(',', $facStr));
                }
            } else {
                $tkFacs = trim($rawSubj) !== '' ? array_map('trim', explode(',', $rawSubj)) : [];
            }

            $ph = jsonList($t['ticket_proof_path']);
            $photoUrls = [];
            foreach ($ph as $i => $_) {
                $photoUrls[] = url('file.php?t=ticket&id=' . $t['id_ticket'] . '&i=' . $i);
            }
            $modalLabel = $tkTitle !== '' ? $tkTitle : implode(', ', $tkFacs);
          ?>
            <tr>
              <td style="white-space: nowrap;">
                <b style="font-size:.85rem;">#T-<?= str_pad((string)$t['id_ticket'], 3, '0', STR_PAD_LEFT) ?></b>
                <div class="small muted"><?= e(tgl($t['created_at'])) ?></div>
              </td>
              <td style="white-space: nowrap;">
                <b><?= e($t['nama_lengkap']) ?></b>
                <div class="small muted">
                  <?= $t['nomor_kamar'] ? 'Kamar ' . e($t['nomor_kamar']) : '-' ?>
                  <?= $t['nama_kos'] ? ' · ' . e($t['nama_kos']) : '' ?>
                </div>
              </td>
              <td>
                <?php if ($tkTitle !== '') { ?>
                  <div style="font-weight:700; margin-bottom:.25rem;"><?= e($tkTitle) ?></div>
                <?php } ?>
                <?php if ($tkFacs) { ?>
                  <div style="display:flex; flex-wrap:wrap; gap:.3rem;">
                    <?php foreach ($tkFacs as $fc) { ?>
                      <span style="background:#EEF4ED; color:#3F5141; font-size:.73rem; font-weight:600; padding:.14rem .55rem; border-radius:999px;"><?= e($fc) ?></span>
                    <?php } ?>
                  </div>
                <?php } ?>
              </td>
              <td style="text-align: center; white-space: nowrap;">
                <?php if ($photoUrls) { ?>
                  <button type="button"
                          class="btn btn-outline btn-sm"
                          data-title="<?= e($modalLabel) ?>"
                          data-photos="<?= e(json_encode($photoUrls)) ?>"
                          onclick="openDashProofModal(this)">
                    Lihat (<?= count($photoUrls) ?>)
                  </button>
                <?php } else { ?>
                  <span class="muted small">-</span>
                <?php } ?>
              </td>
              <td style="white-space: nowrap;">
                <?= statusBadge('ticket', $t['status']) ?>
              </td>
              <td style="text-align: right; white-space: nowrap;">
                <?php if ($t['status'] === 'OPEN') { ?>
                  <form method="post" action="<?= e(url('admin/tickets.php')) ?>" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id_ticket" value="<?= $t['id_ticket'] ?>">
                    <input type="hidden" name="status" value="IN_PROGRESS">
                    <button class="btn btn-sm" style="white-space: nowrap;">Proses Tiket &rarr;</button>
                  </form>
                <?php } else { ?>
                  <a href="<?= e(url('admin/tickets.php#t' . $t['id_ticket'])) ?>" class="btn btn-outline btn-sm" style="white-space: nowrap;">Kelola</a>
                <?php } ?>
              </td>
            </tr>
          <?php } ?>
        <?php } ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Lihat Foto Bukti -->
<div class="proof-modal" id="dashProofModal" onclick="if(event.target===this) closeDashProofModal()">
  <div class="proof-modal-box">
    <div class="proof-modal-head">
      <h4 id="dashProofModalTitle">Foto Bukti Pengaduan</h4>
      <button type="button" class="btn btn-outline btn-sm" onclick="closeDashProofModal()">Tutup</button>
    </div>
    <div class="proof-modal-grid" id="dashProofModalGrid"></div>
  </div>
</div>

<script>
function openDashProofModal(btn) {
  const title = btn.getAttribute('data-title') || 'Foto Bukti';
  const photos = JSON.parse(btn.getAttribute('data-photos') || '[]');
  const grid = document.getElementById('dashProofModalGrid');
  document.getElementById('dashProofModalTitle').textContent = 'Bukti: ' + title;
  grid.innerHTML = '';

  photos.forEach(src => {
    const a = document.createElement('a');
    a.href = src;
    a.target = '_blank';
    a.title = 'Klik untuk memperbesar';
    const img = document.createElement('img');
    img.src = src;
    img.alt = 'Foto Bukti';
    a.appendChild(img);
    grid.appendChild(a);
  });

  document.getElementById('dashProofModal').classList.add('is-open');
}

function closeDashProofModal() {
  document.getElementById('dashProofModal').classList.remove('is-open');
}
</script>

<?php if (count($roomsByGroup) > 1) { ?>
<script>
document.querySelectorAll('#sk-tabs button').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#sk-tabs button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const target = btn.dataset.target;
    document.querySelectorAll('.sk-sec').forEach(sec => {
      sec.style.display = (target === 'all' || sec.dataset.group === target) ? '' : 'none';
    });
  });
});
</script>
<?php } ?>

<?php app_end(); ?>