<?php
// Logika pembayaran yang dipakai bersama: pembayaran pertama (first_payment.php) & bulanan (tenant/pay.php)

function pendingPayment(PDO $db, int $idUser): ?array {
    $s = $db->prepare("SELECT * FROM payments WHERE id_user=? AND payment_status='PENDING' ORDER BY id_payment DESC LIMIT 1");
    $s->execute([$idUser]);
    return $s->fetch() ?: null;
}
function nextDueDate(PDO $db, int $idUser): string {
    $s = $db->prepare("SELECT MAX(due_date) FROM payments WHERE id_user=? AND payment_status IN ('PAID','PENDING')");
    $s->execute([$idUser]);
    $d = $s->fetchColumn();
    return $d ? date('Y-m-d', strtotime($d . ' +1 month')) : date('Y-m-d');
}
function paymentHistory(PDO $db, int $idUser, int $limit = 100): array {
    $s = $db->prepare('SELECT * FROM payments WHERE id_user=? ORDER BY due_date DESC, id_payment DESC LIMIT ' . (int)$limit);
    $s->execute([$idUser]);
    return $s->fetchAll();
}

function handlePaymentPost(array $u, string $type, string $back): void {
    csrf_check();
    $db = db();
    $method = $_POST['method'] ?? '';
    if (!in_array($method, ['QRIS', 'CASH'], true)) { flash('error', 'Pilih metode pembayaran.'); redirect($back); }
    if (pendingPayment($db, (int)$u['id_user'])) { flash('error', 'Masih ada pembayaran yang menunggu verifikasi.'); redirect($back); }
    if (!$u['id_room']) { flash('error', 'Anda belum memiliki kamar.'); redirect($back); }
    $proof = null;
    try {
        if ($method === 'QRIS') {
            $f = normalizeFiles('proof');
            if (!$f) throw new RuntimeException('Unggah bukti pembayaran QRIS terlebih dahulu.');
            $proof = saveUpload($f[0], 'payments');
        }
    } catch (RuntimeException $ex) { flash('error', $ex->getMessage()); redirect($back); }
    $due = $type === 'FIRST_MONTH' ? date('Y-m-d') : nextDueDate($db, (int)$u['id_user']);
    $db->prepare('INSERT INTO payments(id_user,id_kosan,nomor_kamar,amount,payment_type,payment_method,payment_proof_path,due_date) VALUES (?,?,?,?,?,?,?,?)')
       ->execute([$u['id_user'], $u['id_kosan'], $u['nomor_kamar'], $u['harga_per_bulan'], $type, $method, $proof, $due]);
    if ($method === 'CASH') $_SESSION['cash_popup'] = 1;
    else flash('success', 'Bukti pembayaran terkirim. Mohon tunggu verifikasi dari pengelola.');
    redirect($back);
}

// Panel pilihan metode (QRIS / Tunai). $pre: metode terpilih awal.
function renderPaymentPanel(array $u, string $pre = 'QRIS'): void {
    $pre = $pre === 'CASH' ? 'CASH' : 'QRIS'; ?>
<form method="post" enctype="multipart/form-data" class="card" data-pay-form>
  <?= csrf_field() ?>
  <h3>Pilih metode pembayaran</h3>
  <div class="method-grid">
    <label class="method"><input type="radio" name="method" value="QRIS" <?= $pre === 'QRIS' ? 'checked' : '' ?>><span><b>QRIS</b><small>Scan lalu unggah bukti pembayaran</small></span></label>
    <label class="method"><input type="radio" name="method" value="CASH" <?= $pre === 'CASH' ? 'checked' : '' ?>><span><b>Tunai</b><small>Bayar langsung kepada pengelola</small></span></label>
  </div>
  <div data-panel="QRIS">
    <?php if (!empty($u['qris_path'])): ?>
      <div class="qris-box"><img src="<?= e(url($u['qris_path'])) ?>" alt="QRIS <?= e($u['nama_kos']) ?>"><p class="muted">Scan QRIS di atas, bayar <b><?= rupiah($u['harga_per_bulan']) ?></b>, lalu unggah bukti pembayaran.</p></div>
      <label class="field">Bukti pembayaran<input type="file" name="proof" accept="image/*"></label>
    <?php else: ?>
      <div class="flash flash-error">QRIS belum diunggah oleh pengelola. Silakan pilih pembayaran tunai atau hubungi pengelola.</div>
    <?php endif; ?>
  </div>
  <div data-panel="CASH" hidden><p class="muted">Pembayaran tunai diserahkan langsung kepada pengelola sebesar <b><?= rupiah($u['harga_per_bulan']) ?></b>. Pengelola akan memverifikasi pembayaran Anda.</p></div>
  <button class="btn" type="submit">Kirim pembayaran</button>
</form>
<?php }

function renderCashModal(): void {
    if (empty($_SESSION['cash_popup'])) return;
    unset($_SESSION['cash_popup']); ?>
<div class="modal" id="cashModal"><div class="modal-box">
  <p>Silakan lakukan pembayaran tunai kepada pengelola dan tunggu pembayaran Anda diverifikasi.</p>
  <button type="button" class="btn" data-close>OK</button>
</div></div>
<?php }

function renderPaymentTable(array $rows, bool $nota = true): void { ?>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Bulan</th><th>Metode</th><th>Nominal</th><th>Status</th><?php if ($nota): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($rows as $p): ?>
    <tr><td><?= e(bulanTahun($p['due_date'])) ?></td><td><?= $p['payment_method'] === 'CASH' ? 'Tunai' : 'QRIS' ?></td><td><?= rupiah($p['amount']) ?></td>
    <td><?= statusBadge('payment', $p['payment_status']) ?><?php if ($p['payment_status'] === 'REJECTED' && $p['catatan']): ?><div class="muted small">Alasan: <?= e($p['catatan']) ?></div><?php endif; ?></td>
    <?php if ($nota): ?><td class="right"><?php if ($p['payment_status'] === 'PAID'): ?><a class="link" href="<?= e(url('tenant/receipt.php?id=' . $p['id_payment'])) ?>" target="_blank">Unduh nota</a><?php endif; ?></td><?php endif; ?></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="muted">Belum ada riwayat pembayaran.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php }
