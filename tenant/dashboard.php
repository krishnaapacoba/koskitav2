<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
require_once __DIR__ . '/../config/payment.php';
$u = requireTenant(); $db = db(); $id = (int)$u['id_user'];
$pending = pendingPayment($db, $id); $due = nextDueDate($db, $id);
$hist = paymentHistory($db, $id, 5);
$lastPaid = null; foreach ($hist as $h) if ($h['payment_status'] === 'PAID') { $lastPaid = $h; break; }
$ts = $db->prepare('SELECT * FROM tickets WHERE id_user=? ORDER BY id_ticket DESC LIMIT 5'); $ts->execute([$id]); $tickets = $ts->fetchAll();
$days = (int)floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400);

app_start('TENANT', 'dashboard', 'Halo, ' . $u['nama_lengkap']);
if (!$pending && $days <= 7): ?><div class="banner"><?= $days < 0 ? 'Tagihan Anda terlambat ' . abs($days) . ' hari.' : ($days === 0 ? 'Tagihan sewa jatuh tempo hari ini.' : 'Tagihan sewa jatuh tempo ' . $days . ' hari lagi.') ?> <a class="link" href="<?= e(url('tenant/pay.php')) ?>">Bayar sekarang</a></div><?php endif; ?>
<div class="grid g2">
  <div class="card"><span class="muted">Kamar Anda</span><div style="font-size:1.4rem;font-weight:800;margin:.1rem 0 .6rem"><?= e($u['nama_kos']) ?> · <?= e($u['nomor_kamar']) ?></div><?= badge('Sewa aktif') ?></div>
  <div class="card card-hl"><span class="muted">Tagihan bulan ini</span><div style="font-size:1.9rem;font-weight:800"><?= rupiah($u['harga_per_bulan']) ?></div>
    <?php if ($pending): ?><div class="muted" style="margin:.3rem 0 .8rem">Pembayaran <?= bulanTahun($pending['due_date']) ?> sedang diverifikasi</div><?= statusBadge('payment', 'PENDING') ?>
    <?php else: ?><div class="muted" style="margin:.2rem 0 .8rem">Jatuh tempo: <?= e(tgl($due)) ?></div>
      <div class="actions"><a class="btn" href="<?= e(url('tenant/pay.php?m=QRIS')) ?>">Bayar via QRIS</a><a class="btn btn-outline" href="<?= e(url('tenant/pay.php?m=CASH')) ?>">Tunai</a></div><?php endif; ?></div>
</div>
<div class="card"><div class="row-between"><h3 style="margin:0">Riwayat pembayaran</h3>
  <?php if ($lastPaid): ?><a class="link" target="_blank" href="<?= e(url('tenant/receipt.php?id=' . $lastPaid['id_payment'])) ?>">Unduh nota</a><?php endif; ?></div>
  <div style="margin-top:.6rem"><?php renderPaymentTable($hist); ?></div><a class="link small" href="<?= e(url('tenant/pay.php')) ?>">Lihat semua</a></div>
<div class="card"><div class="row-between"><h3 style="margin:0">Pengaduan saya</h3><a class="btn btn-sm" href="<?= e(url('tenant/create_ticket.php#form')) ?>">Buat tiket</a></div>
  <div class="table-wrap" style="margin-top:.6rem"><table class="table"><thead><tr><th>Judul</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($tickets as $t): ?><tr><td><?= e($t['subject']) ?></td><td><?= statusBadge('ticket', $t['status']) ?></td></tr><?php endforeach; if (!$tickets): ?><tr><td colspan="2" class="muted">Belum ada pengaduan.</td></tr><?php endif; ?></tbody></table></div></div>
<?php app_end();
