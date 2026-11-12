<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = requireTenant(); $db = db(); $id = (int)$u['id_user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $subj = clean($_POST['subject'] ?? ''); $desc = clean($_POST['description'] ?? ''); $paths = [];
    try {
        if ($subj === '' || strlen($subj) > 150) throw new RuntimeException('Judul wajib diisi (maks. 150 karakter).');
        if ($desc === '') throw new RuntimeException('Jelaskan kendala yang Anda alami.');
        $files = normalizeFiles('photos'); if (count($files) > 4) throw new RuntimeException('Maksimal 4 foto.');
        foreach ($files as $f) $paths[] = saveUpload($f, 'tickets');
        $db->prepare('INSERT INTO tickets(id_user,id_kosan,subject,description,ticket_proof_path) VALUES (?,?,?,?,?)')
           ->execute([$id, $u['id_kosan'], $subj, $desc, json_encode($paths)]);
        flash('success', 'Tiket pengaduan terkirim.');
    } catch (RuntimeException $ex) { foreach ($paths as $p) deleteFile($p); flash('error', $ex->getMessage()); }
    redirect('tenant/create_ticket.php');
}
$ts = $db->prepare('SELECT * FROM tickets WHERE id_user=? ORDER BY id_ticket DESC'); $ts->execute([$id]); $tickets = $ts->fetchAll();

app_start('TENANT', 'ticket', 'Pengaduan', 'Laporkan kendala fasilitas kamar lengkap dengan foto bukti.'); ?>
<form class="card" id="form" method="post" enctype="multipart/form-data"><?= csrf_field() ?><h3>Buat tiket baru</h3>
  <label class="field">Judul kendala<input type="text" name="subject" maxlength="150" placeholder="Contoh: AC tidak dingin" required></label>
  <label class="field">Rincian kendala<textarea name="description" placeholder="Jelaskan kendala fasilitas yang Anda alami" required></textarea></label>
  <label class="field">Foto bukti (maks. 4)<input type="file" name="photos[]" accept="image/*" multiple></label>
  <button class="btn" type="submit">Kirim tiket</button></form>
<div class="card"><h3>Pengaduan saya</h3>
<?php foreach ($tickets as $t): $ph = jsonList($t['ticket_proof_path']); ?>
  <div style="border-top:1px solid var(--line);padding:1rem 0"><div class="row-between"><b><?= e($t['subject']) ?></b><?= statusBadge('ticket', $t['status']) ?></div>
  <p class="muted small" style="margin:.2rem 0"><?= e(tgl($t['created_at'])) ?></p><p style="margin:.3rem 0"><?= nl2br(e($t['description'])) ?></p>
  <?php if ($ph): ?><div class="thumbs"><?php foreach ($ph as $i => $_): $h = url('file.php?t=ticket&id=' . $t['id_ticket'] . '&i=' . $i); ?><a href="<?= e($h) ?>" target="_blank"><img src="<?= e($h) ?>" alt="Bukti"></a><?php endforeach; ?></div><?php endif; ?></div>
<?php endforeach; if (!$tickets): ?><p class="muted">Belum ada pengaduan.</p><?php endif; ?></div>
<?php app_end();
