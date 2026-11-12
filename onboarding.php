<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/layout.php';
$u = checkRole(['USER']); $db = db();
if ($u['user_status'] === 'TENANT') redirect('tenant/dashboard.php');
if ($u['user_status'] === 'PAYMENT_PENDING') redirect('first_payment.php');
if (!$u['id_room']) { flash('info', 'Pilih kamar terlebih dahulu.'); redirect('index.php#kamar'); }
$id = (int)$u['id_user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $u['user_status'] === 'GUEST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'cancel') {          // batalkan pesanan -> kamar tersedia kembali
        $db->beginTransaction();
        $docs = $db->prepare('SELECT file_path FROM user_documents WHERE id_user=?'); $docs->execute([$id]);
        foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $f) deleteFile($f);
        $db->prepare('DELETE FROM user_documents WHERE id_user=?')->execute([$id]);
        $db->prepare("UPDATE rooms SET status_kamar='AVAILABLE' WHERE id_room=?")->execute([$u['id_room']]);
        $db->prepare('UPDATE users SET id_room=NULL WHERE id_user=?')->execute([$id]);
        $db->commit();
        flash('success', 'Pesanan dibatalkan.'); redirect('index.php#kamar');
    }
    // unggah berkas (boleh lebih dari satu jenis)
    $hasKtp = $db->prepare("SELECT 1 FROM user_documents WHERE id_user=? AND document_type='KTP' AND status<>'REJECTED'");
    $hasKtp->execute([$id]);
    $saved = []; $err = null;
    try {
        foreach (array_keys(DOC_LABEL) as $t) {
            $f = normalizeFiles('doc_' . $t);
            if ($f) $saved[$t] = saveUpload($f[0], 'documents', ['jpg', 'png', 'webp', 'pdf']);
        }
        if (!$saved) throw new RuntimeException('Unggah minimal satu berkas identitas.');
        if (!isset($saved['KTP']) && !$hasKtp->fetchColumn()) throw new RuntimeException('KTP wajib dilampirkan.');
    } catch (RuntimeException $ex) { $err = $ex->getMessage(); foreach ($saved as $p) deleteFile($p); }
    if ($err) { flash('error', $err); redirect('onboarding.php'); }
    $db->beginTransaction();
    foreach ($saved as $t => $path) {
        $old = $db->prepare("SELECT id_document,file_path FROM user_documents WHERE id_user=? AND document_type=CAST(? AS doc_type_enum) AND status='REJECTED'");
        $old->execute([$id, $t]);
        foreach ($old->fetchAll() as $o) { deleteFile($o['file_path']); $db->prepare('DELETE FROM user_documents WHERE id_document=?')->execute([$o['id_document']]); }
        $db->prepare('INSERT INTO user_documents(id_user,document_type,file_path) VALUES (?,CAST(? AS doc_type_enum),?)')->execute([$id, $t, $path]);
    }
    $db->prepare("UPDATE users SET user_status='PENDING_VERIFICATION' WHERE id_user=?")->execute([$id]);
    $db->commit();
    flash('success', 'Berkas terkirim. Hasil verifikasi akan dikirim ke email Anda.');
    redirect('onboarding.php');
}

$docs = $db->prepare('SELECT * FROM user_documents WHERE id_user=? ORDER BY id_document'); $docs->execute([$id]); $docs = $docs->fetchAll();
$rejected = array_values(array_filter($docs, fn($d) => $d['status'] === 'REJECTED'));
public_header('Berkas identitas'); ?>
<div class="container narrow" style="padding:0">
<h1>Pesanan saya</h1>
<div class="card"><span class="muted">Kamar dipesan</span>
  <div class="row-between"><b style="font-size:1.25rem"><?= e($u['nama_kos']) ?> · <?= e($u['nomor_kamar']) ?></b><?= statusBadge('room', $u['status_kamar']) ?></div>
  <div class="price"><?= rupiah($u['harga_per_bulan']) ?> <small>/bulan</small></div></div>

<?php if ($u['user_status'] === 'PENDING_VERIFICATION'): ?>
  <div class="banner">Berkas Anda sedang diverifikasi pengelola. Hasilnya akan dikirim lewat email.</div>
<?php else: ?>
  <?php if ($rejected): ?><div class="flash flash-error"><b>Berkas sebelumnya ditolak.</b>
    <?php foreach ($rejected as $d) echo '<div>' . e(DOC_LABEL[$d['document_type']]) . ': ' . e($d['catatan'] ?: 'tanpa keterangan') . '</div>'; ?>Silakan unggah ulang berkas yang benar.</div><?php endif; ?>
  <form class="card" method="post" enctype="multipart/form-data"><?= csrf_field() ?>
    <h3>Lampirkan berkas identitas</h3><p class="muted small">Format JPG, PNG, WEBP, atau PDF, maksimal 5 MB per file. Anda dapat mengirim lebih dari satu berkas.</p>
    <?php foreach (DOC_LABEL as $t => $l): ?><label class="field"><?= e($l) ?><?= $t === 'KTP' ? ' (wajib)' : ' (opsional)' ?><input type="file" name="doc_<?= $t ?>" accept="image/*,application/pdf"></label><?php endforeach; ?>
    <button class="btn" type="submit">Kirim berkas</button>
  </form>
  <form method="post" data-confirm="Batalkan pesanan dan lepas kamar ini?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-red btn-sm" type="submit">Batalkan pesanan</button></form>
<?php endif; ?>

<?php if ($docs): ?><div class="card" style="margin-top:1.25rem"><h3>Berkas terunggah</h3>
  <table class="table"><tbody><?php foreach ($docs as $d): ?><tr><td><?= e(DOC_LABEL[$d['document_type']]) ?></td><td><?= statusBadge('doc', $d['status']) ?></td><td class="muted small"><?= e(tgl($d['uploaded_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<?php public_footer();
