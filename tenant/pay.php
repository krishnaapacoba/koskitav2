<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
require_once __DIR__ . '/../config/payment.php';
$u = requireTenant(); $db = db(); $id = (int)$u['id_user'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') handlePaymentPost($u, 'MONTHLY', 'tenant/pay.php');

$pending = pendingPayment($db, $id); $due = nextDueDate($db, $id);
$days = (int)floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400);
app_start('TENANT', 'pay', 'Pembayaran', 'Bayar sewa bulanan dan pantau riwayat pembayaran Anda.'); renderCashModal(); ?>
<?php if ($pending): ?>
  <div class="banner">Pembayaran <?= $pending['payment_method'] === 'CASH' ? 'tunai' : 'QRIS' ?> untuk <?= e(bulanTahun($pending['due_date'])) ?> menunggu verifikasi pengelola.</div>
<?php else: ?>
  <div class="banner <?= $days > 7 ? 'banner-ok' : '' ?>">Pengingat: sewa <b><?= e(bulanTahun($due)) ?></b> sebesar <b><?= rupiah($u['harga_per_bulan']) ?></b> jatuh tempo <b><?= e(tgl($due)) ?></b>
    (<?= $days < 0 ? 'terlambat ' . abs($days) . ' hari' : ($days === 0 ? 'hari ini' : $days . ' hari lagi') ?>).</div>
  <?php renderPaymentPanel($u, $_GET['m'] ?? 'QRIS'); ?>
<?php endif; ?>
<div class="card"><h3>Riwayat pembayaran</h3><?php renderPaymentTable(paymentHistory($db, $id)); ?></div>
<?php app_end();
