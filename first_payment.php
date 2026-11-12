<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/layout.php';
require_once __DIR__ . '/config/payment.php';
$u = checkRole(['USER']); $db = db();
if ($u['user_status'] === 'TENANT') redirect('tenant/pay.php');
if ($u['user_status'] !== 'PAYMENT_PENDING') { flash('info', 'Pembayaran pertama dibuka setelah berkas Anda disetujui.'); redirect(homeFor($u)); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') handlePaymentPost($u, 'FIRST_MONTH', 'first_payment.php');

$pending = pendingPayment($db, (int)$u['id_user']);
$last = $db->prepare("SELECT * FROM payments WHERE id_user=? AND payment_status='REJECTED' ORDER BY id_payment DESC LIMIT 1"); $last->execute([$u['id_user']]); $rej = $last->fetch();
$hist = paymentHistory($db, (int)$u['id_user']);
public_header('Pembayaran pertama'); renderCashModal(); ?>
<div class="container narrow" style="padding:0">
<h1>Pembayaran pertama</h1><p class="sub">Berkas Anda sudah disetujui. Selesaikan pembayaran bulan pertama untuk mengaktifkan akun penghuni.</p>
<div class="grid g2">
  <div class="card"><span class="muted">Kamar Anda</span><div style="font-size:1.25rem;font-weight:800"><?= e($u['nama_kos']) ?> · <?= e($u['nomor_kamar']) ?></div></div>
  <div class="card card-hl"><span class="muted">Tagihan bulan pertama</span><div style="font-size:1.8rem;font-weight:800"><?= rupiah($u['harga_per_bulan']) ?></div></div>
</div>
<?php if ($pending): ?>
  <div class="banner">Pembayaran <?= $pending['payment_method'] === 'CASH' ? 'tunai' : 'QRIS' ?> Anda menunggu verifikasi pengelola. Setelah disetujui, Anda dapat masuk ke dashboard penghuni dengan akun yang sama.</div>
<?php else: ?>
  <?php if ($rej): ?><div class="flash flash-error">Pembayaran sebelumnya ditolak<?= $rej['catatan'] ? ': ' . e($rej['catatan']) : '' ?>. Silakan kirim ulang.</div><?php endif; ?>
  <?php renderPaymentPanel($u, $_GET['m'] ?? 'QRIS'); ?>
<?php endif; ?>
<div class="card"><h3>Riwayat pembayaran</h3><?php renderPaymentTable($hist, false); ?></div>
</div>
<?php public_footer();
