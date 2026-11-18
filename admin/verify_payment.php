<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = checkRole(['ADMIN']); $db = db(); $ids = adminKosanIds(); $arr = pgArr($ids);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $act = $_POST['action'] ?? '';
    $back = ($_POST['back'] ?? '') === 'dashboard' ? 'admin/dashboard.php' : 'admin/verify_payment.php';
    if ($act === 'qris') {
        $k = (int)($_POST['id_kosan'] ?? 0);
        try {
            if (!in_array($k, $ids, true)) throw new RuntimeException('Kosan tidak valid.');
            $f = normalizeFiles('qris'); if (!$f) throw new RuntimeException('Pilih gambar QRIS.');
            $new = saveUpload($f[0], 'qris');
            $old = $db->prepare('SELECT qris_path FROM kosan WHERE id_kosan=?'); $old->execute([$k]); deleteFile($old->fetchColumn());
            $db->prepare('UPDATE kosan SET qris_path=? WHERE id_kosan=?')->execute([$new, $k]);
            flash('success', 'QRIS berhasil diperbarui.');
        } catch (RuntimeException $ex) { flash('error', $ex->getMessage()); }
        redirect($back);
    }
    $id = (int)($_POST['id_payment'] ?? 0);
    $s = $db->prepare("SELECT p.*,u.email,u.nama_lengkap,u.id_room FROM payments p JOIN users u USING(id_user) WHERE p.id_payment=? AND p.payment_status='PENDING' AND p.id_kosan=ANY(CAST(? AS int[]))");
    $s->execute([$id, $arr]); $p = $s->fetch();
    if (!$p) { flash('error', 'Pembayaran tidak ditemukan atau sudah diproses.'); redirect($back); }
    if ($act === 'approve') {
        $db->beginTransaction();
        $db->prepare("UPDATE payments SET payment_status='PAID',paid_at=NOW(),catatan=NULL WHERE id_payment=?")->execute([$id]);
        if ($p['payment_type'] === 'FIRST_MONTH') {
            $db->prepare("UPDATE users SET user_status='TENANT' WHERE id_user=?")->execute([$p['id_user']]);
            $db->prepare("UPDATE rooms SET status_kamar='OCCUPIED' WHERE id_room=?")->execute([$p['id_room']]);
        }
        $db->commit();
        if ($p['payment_type'] === 'FIRST_MONTH')
            sendMail((int)$p['id_user'], $p['email'], 'Pembayaran diverifikasi — selamat datang di KosKita',
                '<p>Halo ' . e($p['nama_lengkap']) . ',</p><p>Pembayaran pertama Anda sebesar <b>' . rupiah($p['amount']) . '</b> telah <b>diverifikasi</b>. Anda kini resmi menjadi penghuni dan dapat masuk dengan email & kata sandi yang sama.</p>', ['Masuk ke dashboard', absUrl('login.php')]);
        else sendMail((int)$p['id_user'], $p['email'], 'Pembayaran sewa diverifikasi', '<p>Pembayaran sewa ' . e(bulanTahun($p['due_date'])) . ' sebesar <b>' . rupiah($p['amount']) . '</b> telah diverifikasi. Terima kasih.</p>');
        flash('success', 'Pembayaran disetujui.');
    } elseif ($act === 'reject') {
        $note = clean($_POST['catatan'] ?? '');
        if ($note === '') { flash('error', 'Isi alasan penolakan.'); redirect('admin/verify_payment.php#p' . $id); }
        $db->prepare("UPDATE payments SET payment_status='REJECTED',catatan=? WHERE id_payment=?")->execute([$note, $id]);
        sendMail((int)$p['id_user'], $p['email'], 'Pembayaran ditolak', '<p>Pembayaran Anda sebesar <b>' . rupiah($p['amount']) . '</b> ditolak. Alasan: ' . e($note) . '</p><p>Silakan kirim ulang pembayaran.</p>',
            ['Kirim ulang', absUrl($p['payment_type'] === 'FIRST_MONTH' ? 'first_payment.php' : 'tenant/pay.php')]);
        flash('success', 'Pembayaran ditolak. Penghuni diberi tahu.');
    }
    redirect($back);
}

$ks = $db->prepare('SELECT id_kosan,nama_kos,qris_path FROM kosan WHERE id_kosan=ANY(CAST(? AS int[])) ORDER BY nama_kos'); $ks->execute([$arr]); $kosans = $ks->fetchAll();
$ps = $db->prepare("SELECT p.*,u.nama_lengkap,k.nama_kos FROM payments p JOIN users u USING(id_user) LEFT JOIN kosan k ON k.id_kosan=p.id_kosan
    WHERE p.id_kosan=ANY(CAST(? AS int[])) ORDER BY (p.payment_status='PENDING') DESC, p.id_payment DESC LIMIT 200");
$ps->execute([$arr]); $all = $ps->fetchAll();
$pending = array_filter($all, fn($p) => $p['payment_status'] === 'PENDING');
$paidList = array_filter($all, fn($p) => $p['payment_status'] === 'PAID');
$totalPaid = array_sum(array_column($paidList, 'amount'));

app_start('ADMIN', 'payment', 'Pembayaran', 'Kelola QRIS dan verifikasi pembayaran penghuni.');
?>

<style>
  .pay-card { padding: 1.65rem; border-radius: 18px; }
  .pay-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.25rem; padding-bottom: .95rem; border-bottom: 1px solid var(--line); }
  .pay-head h3 { margin: 0; font-size: 1.15rem; font-weight: 800; }

  /* Kartu QRIS per Kosan */
  .qris-card { background: #faf9f6; border: 1.5px solid var(--line); border-radius: 16px; padding: 1.35rem; display: flex; flex-direction: column; justify-content: space-between; }
  .qris-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
  .qris-preview { background: #fff; border: 1px solid var(--line); border-radius: 14px; min-height: 180px; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 1.25rem; margin-bottom: 1.15rem; text-align: center; }
  .qris-preview img { max-width: 210px; max-height: 210px; border-radius: 10px; }
  .qris-empty-ico { width: 46px; height: 46px; border-radius: 12px; background: var(--soft); color: var(--teal); display: flex; align-items: center; justify-content: center; margin-bottom: .65rem; }

  /* Custom File Upload */
  .qris-drop { display: flex; align-items: center; gap: .85rem; padding: .8rem 1rem; border: 1.5px dashed #cbd5e1; border-radius: 12px; background: #fff; cursor: pointer; margin-bottom: .9rem; transition: border-color .15s, background .15s; }
  .qris-drop:hover { border-color: var(--teal); background: #f2f8f7; }
  .qris-drop input[type="file"] { display: none; }
  .qris-drop-ico { width: 36px; height: 36px; border-radius: 10px; background: var(--soft); color: var(--teal); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .qris-drop-txt strong { display: block; font-size: .88rem; color: var(--ink); }
  .qris-drop-txt span { font-size: .78rem; color: var(--muted); }

  /* Antrean Verifikasi Pembayaran */
  .verify-item { background: #faf9f6; border: 1.5px solid var(--line); border-radius: 15px; padding: 1.2rem 1.35rem; margin-bottom: .9rem; transition: border-color .15s; }
  .verify-item:hover { border-color: #cbd5e1; }
  .verify-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; }
  .verify-amount { font-size: 1.25rem; font-weight: 800; color: var(--teal-d); }
  .verify-meta { display: flex; flex-wrap: wrap; gap: .45rem; align-items: center; margin-top: .35rem; }
  .verify-actions { display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap; margin-top: 1rem; padding-top: .9rem; border-top: 1px solid var(--line); }
  .reject-group { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
  .reject-group input[type="text"] { margin: 0; padding: .45rem .8rem; font-size: .85rem; border-radius: 10px; width: 240px; }
</style>

<!-- Ringkasan Statistik Pembayaran -->
<div class="grid g3" style="margin-bottom: 1.25rem;">
  <div class="stat">
    <span>Menunggu verifikasi</span>
    <b><?= count($pending) ?></b>
    <div class="small muted" style="margin-top:.35rem">Transaksi perlu konfirmasi</div>
  </div>
  <div class="stat">
    <span>Pembayaran terverifikasi</span>
    <b><?= count($paidList) ?></b>
    <div class="small muted" style="margin-top:.35rem">Total transaksi berstatus lunas</div>
  </div>
  <div class="stat">
    <span>Total pemasukan lunas</span>
    <b style="font-size:1.65rem"><?= rupiah($totalPaid) ?></b>
    <div class="small muted" style="margin-top:.35rem">Dari 200 transaksi terakhir</div>
  </div>
</div>

<!-- Kelola QRIS Pembayaran -->
<div class="card pay-card">
  <div class="pay-head">
    <div>
      <h3>QRIS Pembayaran</h3>
      <div class="small muted" style="margin-top:.2rem">Gambar QRIS ini ditampilkan kepada penghuni saat memilih metode pembayaran QRIS.</div>
    </div>
  </div>

  <div class="grid g2">
    <?php foreach ($kosans as $k): ?>
      <form method="post" enctype="multipart/form-data" class="qris-card">
        <div>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="qris">
          <input type="hidden" name="id_kosan" value="<?= $k['id_kosan'] ?>">

          <div class="qris-card-head">
            <strong style="font-size:1.05rem"><?= e($k['nama_kos']) ?></strong>
            <?= $k['qris_path'] ? badge('QRIS Aktif', 'teal') : badge('Belum Diatur', 'amber') ?>
          </div>

          <div class="qris-preview">
            <?php if ($k['qris_path']): ?>
              <img src="<?= e(url($k['qris_path'])) ?>" alt="QRIS <?= e($k['nama_kos']) ?>">
            <?php else: ?>
              <div class="qris-empty-ico">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect>
                  <rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect>
                </svg>
              </div>
              <strong style="font-size:.92rem; color:var(--ink)">Belum ada kode QRIS</strong>
              <span class="small muted">Unggah gambar QRIS agar penghuni dapat membayar non-tunai</span>
            <?php endif; ?>
          </div>
        </div>

        <div>
          <label class="qris-drop">
            <div class="qris-drop-ico">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line>
              </svg>
            </div>
            <div class="qris-drop-txt">
              <strong id="qris-lbl-<?= $k['id_kosan'] ?>">Pilih gambar QRIS baru</strong>
              <span id="qris-sub-<?= $k['id_kosan'] ?>">Format JPG, PNG, atau WebP</span>
            </div>
            <input type="file" name="qris" accept="image/*" required onchange="updateQrisName(this, <?= $k['id_kosan'] ?>)">
          </label>
          <button class="btn btn-sm btn-block" type="submit">Simpan QRIS</button>
        </div>
      </form>
    <?php endforeach; if (!$kosans): ?>
      <p class="muted">Belum ada kosan yang ditugaskan.</p>
    <?php endif; ?>
  </div>
</div>

<!-- Antrean Menunggu Verifikasi -->
<div class="card pay-card">
  <div class="pay-head">
    <div>
      <h3>Menunggu Verifikasi (<?= count($pending) ?>)</h3>
      <div class="small muted" style="margin-top:.2rem">Periksa bukti transfer atau konfirmasi penerimaan uang tunai penghuni.</div>
    </div>
  </div>

  <?php if (!$pending): ?>
    <div style="text-align:center; padding: 1.75rem 1rem; background:#faf9f6; border-radius:14px; border:1px dashed var(--line);">
      <div style="font-weight:700; color:var(--ink); margin-bottom:.25rem">Semua pembayaran sudah diproses</div>
      <div class="small muted">Tidak ada antrean pembayaran yang menunggu verifikasi saat ini.</div>
    </div>
  <?php else: ?>
    <?php foreach ($pending as $p): ?>
      <div id="p<?= $p['id_payment'] ?>" class="verify-item">
        <div class="verify-top">
          <div>
            <div style="font-size:1.05rem; font-weight:800;">
              <?= e($p['nama_lengkap']) ?>
              <span class="muted" style="font-weight:500;">· <?= e($p['nama_kos']) ?> · Kamar <?= e($p['nomor_kamar']) ?></span>
            </div>
            <div class="verify-meta">
              <?= badge($p['payment_type'] === 'FIRST_MONTH' ? 'Pembayaran Pertama' : 'Sewa ' . bulanTahun($p['due_date']), 'teal') ?>
              <?= badge($p['payment_method'] === 'CASH' ? 'Tunai (Cash)' : 'Transfer QRIS', 'amber') ?>
              <span class="small muted">Jatuh tempo: <?= e(tgl($p['due_date'])) ?></span>
            </div>
          </div>

          <div style="text-align:right;">
            <div class="verify-amount"><?= rupiah($p['amount']) ?></div>
            <?php if ($p['payment_proof_path']): ?>
              <a class="link small" target="_blank" href="<?= e(url('file.php?t=proof&id=' . $p['id_payment'])) ?>">Lihat Bukti Transfer &nearr;</a>
            <?php else: ?>
              <span class="muted small">Konfirmasi tunai langsung</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="verify-actions">
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="id_payment" value="<?= $p['id_payment'] ?>">
            <button class="btn btn-sm">Setujui Pembayaran</button>
          </form>

          <form method="post" class="reject-group">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id_payment" value="<?= $p['id_payment'] ?>">
            <input type="text" name="catatan" placeholder="Tulis alasan penolakan..." required>
            <button class="btn btn-red btn-sm">Tolak</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Riwayat Pembayaran Penghuni -->
<div class="card pay-card">
  <div class="pay-head">
    <div>
      <h3>Riwayat Pembayaran Penghuni</h3>
      <div class="small muted" style="margin-top:.2rem">Catatan seluruh transaksi sewa yang masuk.</div>
    </div>
    <span class="small muted"><?= count($all) ?> transaksi</span>
  </div>

  <div class="table-wrap">
    <table class="table tint">
      <thead>
        <tr>
          <th>Penghuni</th>
          <th>Kamar</th>
          <th>Periode</th>
          <th>Metode</th>
          <th>Nominal</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($all as $p): ?>
          <tr>
            <td><b><?= e($p['nama_lengkap']) ?></b></td>
            <td><?= e($p['nomor_kamar']) ?></td>
            <td>
              <?= e(bulanTahun($p['due_date'])) ?>
              <?= $p['payment_type'] === 'FIRST_MONTH' ? '<span class="badge badge-gray" style="margin-left:.3rem">Pertama</span>' : '' ?>
            </td>
            <td><?= $p['payment_method'] === 'CASH' ? 'Tunai' : 'QRIS' ?></td>
            <td><b><?= rupiah($p['amount']) ?></b></td>
            <td><?= statusBadge('payment', $p['payment_status']) ?></td>
          </tr>
        <?php endforeach; if (!$all): ?>
          <tr><td colspan="6" class="muted">Belum ada transaksi.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function updateQrisName(input, id) {
  const lbl = document.getElementById('qris-lbl-' + id);
  const sub = document.getElementById('qris-sub-' + id);
  if (input.files && input.files.length > 0) {
    lbl.textContent = input.files[0].name;
    sub.textContent = 'File siap diunggah — klik Simpan QRIS';
  }
}
</script>

<?php app_end(); ?>