<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = checkRole(['ADMIN']); $db = db(); $ids = adminKosanIds(); $arr = pgArr($ids);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $act = $_POST['action'] ?? '';
    $back = ($_POST['back'] ?? '') === 'dashboard' ? 'admin/dashboard.php' : 'admin/verify_payment.php';
    if ($act === 'qris') {                                         // unggah gambar QRIS per kosan
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
        if ($p['payment_type'] === 'FIRST_MONTH') {                // GUEST -> TENANT, kamar -> OCCUPIED
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

app_start('ADMIN', 'payment', 'Pembayaran', 'Kelola QRIS dan verifikasi pembayaran penghuni.');
?>
<div class="card"><h3>QRIS pembayaran</h3><p class="muted small">Gambar ini ditampilkan kepada penghuni saat memilih metode QRIS.</p>
<div class="grid g2">
<?php foreach ($kosans as $k): ?>
  <form method="post" enctype="multipart/form-data" class="card" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="qris"><input type="hidden" name="id_kosan" value="<?= $k['id_kosan'] ?>">
    <b><?= e($k['nama_kos']) ?></b>
    <div class="qris-box" style="margin:.8rem 0"><?php if ($k['qris_path']): ?><img src="<?= e(url($k['qris_path'])) ?>" alt="QRIS"><?php else: ?><p class="muted">Belum ada QRIS.</p><?php endif; ?></div>
    <label class="field">Unggah QRIS baru<input type="file" name="qris" accept="image/*" required></label><button class="btn btn-sm">Simpan QRIS</button></form>
<?php endforeach; if (!$kosans): ?><p class="muted">Belum ada kosan yang ditugaskan.</p><?php endif; ?></div></div>

<div class="card"><h3>Menunggu verifikasi (<?= count($pending) ?>)</h3>
<?php foreach ($pending as $p): ?>
  <div id="p<?= $p['id_payment'] ?>" style="border-top:1px solid var(--line);padding:1rem 0">
    <div class="row-between"><div><b><?= e($p['nama_lengkap']) ?></b> · <?= e($p['nama_kos']) ?> · Kamar <?= e($p['nomor_kamar']) ?><div class="muted small"><?= $p['payment_type'] === 'FIRST_MONTH' ? 'Pembayaran pertama' : 'Sewa ' . e(bulanTahun($p['due_date'])) ?> · <?= $p['payment_method'] === 'CASH' ? 'Tunai' : 'QRIS' ?> · <?= rupiah($p['amount']) ?></div></div>
    <?php if ($p['payment_proof_path']): ?><a class="link" target="_blank" href="<?= e(url('file.php?t=proof&id=' . $p['id_payment'])) ?>">Lihat bukti</a><?php else: ?><span class="muted small">Tunai — konfirmasi setelah uang diterima</span><?php endif; ?></div>
    <div class="actions" style="margin-top:.7rem">
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id_payment" value="<?= $p['id_payment'] ?>"><button class="btn btn-sm">Setujui</button></form>
      <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id_payment" value="<?= $p['id_payment'] ?>"><input type="text" name="catatan" placeholder="Alasan penolakan" style="margin:0;width:220px" required><button class="btn btn-outline btn-sm">Tolak</button></form></div>
  </div>
<?php endforeach; if (!$pending): ?><p class="muted">Tidak ada pembayaran yang menunggu.</p><?php endif; ?></div>

<div class="card"><h3>Riwayat pembayaran penghuni</h3><div class="table-wrap"><table class="table"><thead><tr><th>Penghuni</th><th>Kamar</th><th>Periode</th><th>Metode</th><th>Nominal</th><th>Status</th></tr></thead><tbody>
<?php foreach ($all as $p): ?><tr><td><?= e($p['nama_lengkap']) ?></td><td><?= e($p['nomor_kamar']) ?></td><td><?= e(bulanTahun($p['due_date'])) ?><?= $p['payment_type'] === 'FIRST_MONTH' ? ' <span class="muted small">(pertama)</span>' : '' ?></td>
  <td><?= $p['payment_method'] === 'CASH' ? 'Tunai' : 'QRIS' ?></td><td><?= rupiah($p['amount']) ?></td><td><?= statusBadge('payment', $p['payment_status']) ?></td></tr>
<?php endforeach; if (!$all): ?><tr><td colspan="6" class="muted">Belum ada transaksi.</td></tr><?php endif; ?></tbody></table></div></div>
<?php app_end();
