<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = checkRole(['SUPERADMIN']); $db = db();
$n = fn($sql) => (int)$db->query($sql)->fetchColumn();
$rows = $db->query("SELECT k.id_kosan,k.nama_kos,k.kategori,
   (SELECT COUNT(*) FROM rooms r WHERE r.id_kosan=k.id_kosan) AS total,
   (SELECT COUNT(*) FROM rooms r WHERE r.id_kosan=k.id_kosan AND r.status_kamar='OCCUPIED') AS terisi,
   (SELECT string_agg(u.nama_lengkap,', ') FROM admin_kosan_access a JOIN users u USING(id_user) WHERE a.id_kosan=k.id_kosan) AS admins
   FROM kosan k ORDER BY k.nama_kos")->fetchAll();
app_start('SUPERADMIN', 'dashboard', 'Dashboard', 'Ringkasan seluruh kosan pada sistem KosKita.'); ?>
<div class="grid g4" style="margin-bottom:1.25rem">
  <div class="stat"><span>Total kosan</span><b><?= $n('SELECT COUNT(*) FROM kosan') ?></b></div>
  <div class="stat"><span>Kamar terisi</span><b><?= $n("SELECT COUNT(*) FROM rooms WHERE status_kamar='OCCUPIED'") ?> / <?= $n('SELECT COUNT(*) FROM rooms') ?></b></div>
  <div class="stat"><span>Admin site</span><b><?= $n("SELECT COUNT(*) FROM users WHERE role='ADMIN'") ?></b></div>
  <div class="stat"><span>Penghuni aktif</span><b><?= $n("SELECT COUNT(*) FROM users WHERE user_status='TENANT' AND role='USER'") ?></b></div>
  <div class="stat"><span>Berkas menunggu</span><b><?= $n("SELECT COUNT(*) FROM users WHERE user_status='PENDING_VERIFICATION'") ?></b></div>
  <div class="stat"><span>Pembayaran menunggu</span><b><?= $n("SELECT COUNT(*) FROM payments WHERE payment_status='PENDING'") ?></b></div>
  <div class="stat"><span>Tiket terbuka</span><b><?= $n("SELECT COUNT(*) FROM tickets WHERE status<>'RESOLVED'") ?></b></div>
</div>
<div class="card"><h3>Ringkasan per kosan</h3><div class="table-wrap"><table class="table"><thead><tr><th>Kosan</th><th>Kategori</th><th>Kamar terisi</th><th>Admin penanggung jawab</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><b><?= e($r['nama_kos']) ?></b></td><td><?= badge(kategoriLabel($r['kategori'])) ?></td><td><?= (int)$r['terisi'] ?> / <?= (int)$r['total'] ?></td><td><?= e($r['admins'] ?: 'Belum ditugaskan') ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="4" class="muted">Belum ada kosan.</td></tr><?php endif; ?></tbody></table></div></div>
<?php app_end();
