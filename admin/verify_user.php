<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = checkRole(['ADMIN']); $db = db(); $arr = pgArr(adminKosanIds());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id_user'] ?? 0); $act = $_POST['action'] ?? '';
    $s = $db->prepare("SELECT u.*, r.nomor_kamar, r.harga_per_bulan, k.nama_kos, k.qris_path
        FROM users u JOIN rooms r ON r.id_room=u.id_room JOIN kosan k ON k.id_kosan=r.id_kosan
        WHERE u.id_user=? AND u.user_status='PENDING_VERIFICATION' AND r.id_kosan=ANY(CAST(? AS int[]))");
    $s->execute([$id, $arr]); $cand = $s->fetch();
    $back = ($_POST['back'] ?? '') === 'dashboard' ? 'admin/dashboard.php' : 'admin/verify_user.php';
    if (!$cand) { flash('error', 'Data tidak ditemukan atau sudah diproses.'); redirect($back); }
    if ($act === 'approve') {
        $db->beginTransaction();
        $db->prepare("UPDATE user_documents SET status='APPROVED',catatan=NULL WHERE id_user=? AND status='PENDING'")->execute([$id]);
        $db->prepare("UPDATE users SET user_status='PAYMENT_PENDING' WHERE id_user=?")->execute([$id]);
        $db->commit();
        $qris = !empty($cand['qris_path'])
            ? '<p style="margin:20px 0 8px"><b>QRIS pembayaran</b></p>'
              . '<p style="margin:0 0 10px"><img src="' . e(absUrl($cand['qris_path'])) . '" alt="QRIS ' . e($cand['nama_kos']) . '" style="width:240px;max-width:100%;border:1px solid #e5e0d5;border-radius:12px;padding:8px;background:#fff"></p>'
              . '<p>Scan QRIS di atas dan bayar sebesar <b>' . rupiah($cand['harga_per_bulan']) . '</b>, lalu unggah bukti pembayaran pada halaman pembayaran.</p>'
            : '<p>QRIS pembayaran belum tersedia. Silakan hubungi pengelola atau pilih pembayaran tunai.</p>';
        sendMail($id, $cand['email'], 'Berkas disetujui — lakukan pembayaran pertama',
            '<p>Halo ' . e($cand['nama_lengkap']) . ',</p>'
            . '<p>Berkas identitas Anda telah <b>disetujui</b>. Silakan lakukan pembayaran bulan pertama untuk mengaktifkan akun penghuni Anda.</p>'
            . '<p>Kamar: <b>' . e($cand['nama_kos']) . ' · ' . e($cand['nomor_kamar']) . '</b><br>Tagihan bulan pertama: <b>' . rupiah($cand['harga_per_bulan']) . '</b></p>'
            . $qris,
            ['Bayar sekarang', absUrl('first_payment.php')]);
        flash('success', 'Berkas disetujui. Email pembayaran dikirim ke calon penghuni.');
    } elseif ($act === 'reject') {
        $note = clean($_POST['catatan'] ?? '');
        if ($note === '') { flash('error', 'Isi alasan penolakan.'); redirect('admin/verify_user.php#u' . $id); }
        $db->beginTransaction();
        $db->prepare("UPDATE user_documents SET status='REJECTED',catatan=? WHERE id_user=? AND status='PENDING'")->execute([$note, $id]);
        $db->prepare("UPDATE users SET user_status='GUEST' WHERE id_user=?")->execute([$id]);
        $db->commit();
        sendMail($id, $cand['email'], 'Berkas Anda ditolak',
            '<p>Halo ' . e($cand['nama_lengkap']) . ',</p><p>Mohon maaf, berkas identitas Anda <b>ditolak</b>.</p><p>Alasan: ' . e($note) . '</p><p>Silakan unggah ulang berkas yang sesuai.</p>',
            ['Unggah ulang berkas', absUrl('onboarding.php')]);
        flash('success', 'Berkas ditolak. Notifikasi dikirim ke calon penghuni.');
    }
    redirect($back);
}

$s = $db->prepare("SELECT u.id_user,u.nama_lengkap,u.email,r.nomor_kamar,k.nama_kos FROM users u JOIN rooms r ON r.id_room=u.id_room JOIN kosan k ON k.id_kosan=r.id_kosan
    WHERE u.user_status='PENDING_VERIFICATION' AND r.id_kosan=ANY(CAST(? AS int[])) ORDER BY u.id_user");
$s->execute([$arr]); $cands = $s->fetchAll();
$ds = $db->prepare("SELECT * FROM user_documents WHERE id_user=? AND status='PENDING' ORDER BY id_document");

app_start('ADMIN', 'verify', 'Verifikasi Berkas', 'Periksa KTP/KK calon penghuni lalu setujui atau tolak.');
?>

<style>
  .vu-card { padding: 1.65rem; border-radius: 18px; margin-bottom: 1.25rem; }
  .vu-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; padding-bottom: 1rem; border-bottom: 1px solid var(--line); }
  .vu-name { font-size: 1.15rem; font-weight: 800; color: var(--ink); }
  .vu-meta { font-size: .88rem; color: var(--muted); margin-top: .25rem; }

  /* Kartu Pratinjau Dokumen */
  .vu-docs-lbl { font-size: .84rem; font-weight: 700; color: var(--muted); margin: 1.15rem 0 .65rem; }
  .vu-docs { display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.35rem; }
  .vu-doc-item {
    display: flex;
    flex-direction: column;
    width: 190px;
    background: #faf9f6;
    border: 1.5px solid var(--line);
    border-radius: 14px;
    overflow: hidden;
    text-decoration: none;
    transition: transform .12s ease, border-color .15s ease, box-shadow .15s ease;
  }
  .vu-doc-item:hover { transform: translateY(-2px); border-color: var(--teal); box-shadow: 0 4px 12px rgba(0,0,0,.06); }
  .vu-doc-media { height: 120px; background: #eef2f0; display: flex; align-items: center; justify-content: center; overflow: hidden; }
  .vu-doc-media img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .vu-pdf-badge { display: flex; flex-direction: column; align-items: center; gap: .3rem; color: var(--teal-d); font-weight: 700; font-size: .82rem; }
  .vu-doc-foot { padding: .65rem .8rem; background: #fff; border-top: 1px solid var(--line); }
  .vu-doc-title { display: block; font-size: .86rem; font-weight: 700; color: var(--ink); }
  .vu-doc-hint { display: block; font-size: .74rem; color: var(--teal); font-weight: 600; margin-top: .1rem; }

  /* Baris Aksi Sejajar */
  .vu-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    padding-top: 1.1rem;
    border-top: 1px solid var(--line);
  }
  .vu-reject-form { display: flex; align-items: center; gap: .55rem; flex-wrap: wrap; flex: 1; justify-content: flex-end; }
  .vu-reject-input {
    margin: 0 !important;
    max-width: 320px;
    padding: .55rem .9rem !important;
    font-size: .88rem !important;
    border-radius: 11px !important;
  }
  @media (max-width: 700px) {
    .vu-reject-form { justify-content: flex-start; width: 100%; }
    .vu-reject-input { max-width: 100%; flex: 1; }
  }
</style>

<?php if (!$cands): ?>
  <div class="card" style="text-align:center; padding: 2.5rem 1rem; border-radius:18px;">
    <div style="font-weight:700; font-size:1.05rem; margin-bottom:.25rem">Tidak ada berkas yang menunggu verifikasi</div>
    <div class="muted small">Semua dokumen identitas calon penghuni telah diperiksa.</div>
  </div>
<?php else: ?>
  <?php foreach ($cands as $c): $ds->execute([$c['id_user']]); $docs = $ds->fetchAll(); ?>
    <div class="card vu-card" id="u<?= $c['id_user'] ?>">
      <div class="vu-top">
        <div>
          <div class="vu-name"><?= e($c['nama_lengkap']) ?></div>
          <div class="vu-meta">
            <?= e($c['email']) ?> · <b><?= e($c['nama_kos']) ?></b> · Kamar <b><?= e($c['nomor_kamar']) ?></b>
          </div>
        </div>
        <?= badge('Kamar Ditahan · Menunggu Verifikasi', 'amber') ?>
      </div>

      <div class="vu-docs-lbl">Dokumen Identitas Terlampir (<?= count($docs) ?> berkas)</div>
      <div class="vu-docs">
        <?php foreach ($docs as $d): $href = url('file.php?t=doc&id=' . $d['id_document']); ?>
          <a href="<?= e($href) ?>" target="_blank" class="vu-doc-item" title="Buka berkas di tab baru">
            <div class="vu-doc-media">
              <?php if (isImage($d['file_path'])): ?>
                <img src="<?= e($href) ?>" alt="<?= e(DOC_LABEL[$d['document_type']]) ?>">
              <?php else: ?>
                <div class="vu-pdf-badge">
                  <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                  </svg>
                  <span>Dokumen PDF</span>
                </div>
              <?php endif; ?>
            </div>
            <div class="vu-doc-foot">
              <span class="vu-doc-title"><?= e(DOC_LABEL[$d['document_type']]) ?></span>
              <span class="vu-doc-hint">Klik untuk perbesar &nearr;</span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="vu-actions">
        <form method="post" class="inline">
          <?= csrf_field() ?>
          <input type="hidden" name="id_user" value="<?= $c['id_user'] ?>">
          <input type="hidden" name="action" value="approve">
          <button class="btn" type="submit">Setujui Berkas</button>
        </form>

        <form method="post" class="vu-reject-form">
          <?= csrf_field() ?>
          <input type="hidden" name="id_user" value="<?= $c['id_user'] ?>">
          <input type="hidden" name="action" value="reject">
          <input type="text" name="catatan" class="vu-reject-input" placeholder="Alasan penolakan (wajib jika ditolak)" required>
          <button class="btn btn-red" type="submit">Tolak Berkas</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php app_end(); ?>