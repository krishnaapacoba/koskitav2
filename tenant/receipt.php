<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = requireTenant();
$s = db()->prepare("SELECT p.*,k.nama_kos FROM payments p LEFT JOIN kosan k ON k.id_kosan=p.id_kosan WHERE p.id_payment=? AND p.id_user=? AND p.payment_status='PAID'");
$s->execute([(int)($_GET['id'] ?? 0), $u['id_user']]); $p = $s->fetch();
if (!$p) { flash('error', 'Nota tidak ditemukan.'); redirect('tenant/dashboard.php'); }
page_head('Nota pembayaran'); ?>
<div class="receipt"><div class="brand">KosKita</div><h2 style="margin-top:.5rem">Nota Pembayaran Sewa</h2>
<table class="table"><tbody>
<tr><td class="muted">No. nota</td><td>KK-<?= str_pad($p['id_payment'], 6, '0', STR_PAD_LEFT) ?></td></tr>
<tr><td class="muted">Penghuni</td><td><?= e($u['nama_lengkap']) ?></td></tr>
<tr><td class="muted">Kamar</td><td><?= e($p['nama_kos']) ?> · <?= e($p['nomor_kamar']) ?></td></tr>
<tr><td class="muted">Periode</td><td><?= e(bulanTahun($p['due_date'])) ?></td></tr>
<tr><td class="muted">Metode</td><td><?= $p['payment_method'] === 'CASH' ? 'Tunai' : 'QRIS' ?></td></tr>
<tr><td class="muted">Tanggal lunas</td><td><?= e(tgl($p['paid_at'])) ?></td></tr>
<tr><td><b>Total</b></td><td><b><?= rupiah($p['amount']) ?></b></td></tr></tbody></table>
<p class="muted small">Pembayaran telah diverifikasi pengelola. Gunakan tombol di bawah lalu pilih “Simpan sebagai PDF”.</p>
<button class="btn no-print" data-print>Cetak / Simpan PDF</button></div>
<?php page_foot();
