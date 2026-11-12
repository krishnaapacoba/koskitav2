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
        $db->prepare("UPDATE users SET user_status='GUEST' WHERE id_user=?")->execute([$id]);   // kamar tetap ditahan agar bisa unggah ulang
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
foreach ($cands as $c): $ds->execute([$c['id_user']]); $docs = $ds->fetchAll(); ?>
<div class="card" id="u<?= $c['id_user'] ?>">
  <div class="row-between"><div><b style="font-size:1.1rem"><?= e($c['nama_lengkap']) ?></b><div class="muted small"><?= e($c['email']) ?> · <?= e($c['nama_kos']) ?> · Kamar <?= e($c['nomor_kamar']) ?> (ditahan)</div></div></div>
  <div class="thumbs" style="margin:1rem 0">
  <?php foreach ($docs as $d): $href = url('file.php?t=doc&id=' . $d['id_document']); ?>
    <a href="<?= e($href) ?>" target="_blank" style="text-align:center;font-size:.82rem">
      <?php if (isImage($d['file_path'])): ?><img src="<?= e($href) ?>" alt=""><?php else: ?><div class="thumb" style="display:flex;align-items:center;justify-content:center">PDF</div><?php endif; ?>
      <?= e(DOC_LABEL[$d['document_type']]) ?></a>
  <?php endforeach; ?></div>
  <div class="grid g2"><form method="post"><?= csrf_field() ?><input type="hidden" name="id_user" value="<?= $c['id_user'] ?>"><input type="hidden" name="action" value="approve"><button class="btn">Setujui berkas</button></form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id_user" value="<?= $c['id_user'] ?>"><input type="hidden" name="action" value="reject">
    <input type="text" name="catatan" placeholder="Alasan penolakan (wajib)" required style="margin:0 0 .5rem"><button class="btn btn-outline">Tolak berkas</button></form></div>
</div>
<?php endforeach; if (!$cands): ?><div class="card muted">Tidak ada berkas yang menunggu verifikasi.</div><?php endif;
app_end();
