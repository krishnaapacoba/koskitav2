<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = checkRole(['ADMIN']); $db = db();
$ids = adminKosanIds(); $arr = pgArr($ids);
$q = function ($sql) use ($db, $arr) { $s = $db->prepare($sql); $s->execute([':ids' => $arr]); return $s; };

$names = $q('SELECT nama_kos FROM kosan WHERE id_kosan=ANY(CAST(:ids AS int[])) ORDER BY nama_kos')->fetchAll(PDO::FETCH_COLUMN);
$berkas = $q("SELECT u.id_user,r.nomor_kamar,
    (SELECT string_agg(d.document_type::text, ', ') FROM user_documents d WHERE d.id_user=u.id_user AND d.status='PENDING') AS docs
    FROM users u JOIN rooms r ON r.id_room=u.id_room WHERE u.user_status='PENDING_VERIFICATION' AND r.id_kosan=ANY(CAST(:ids AS int[]))")->fetchAll();
$bayar = $q("SELECT p.*,u.nama_lengkap FROM payments p JOIN users u USING(id_user) WHERE p.payment_status='PENDING' AND p.id_kosan=ANY(CAST(:ids AS int[])) ORDER BY p.id_payment")->fetchAll();
$tiket = $q("SELECT t.*,u.nama_lengkap FROM tickets t JOIN users u USING(id_user) WHERE t.status='OPEN' AND t.id_kosan=ANY(CAST(:ids AS int[])) ORDER BY t.id_ticket")->fetchAll();
$kamar = $q("SELECT COUNT(*) FILTER (WHERE status_kamar='OCCUPIED') AS terisi, COUNT(*) AS total FROM rooms WHERE id_kosan=ANY(CAST(:ids AS int[]))")->fetch();

app_start('ADMIN', 'dashboard', 'Admin · ' . ($names ? implode(', ', $names) : 'Belum ada kosan'));
if (!$ids): ?><div class="flash flash-info">Akun Anda belum ditugaskan ke kosan mana pun. Hubungi Superadmin.</div><?php endif; ?>
<div class="grid g4" style="margin-bottom:1.25rem">
  <div class="stat"><span>Berkas menunggu</span><b><?= count($berkas) ?></b></div>
  <div class="stat"><span>Pembayaran menunggu</span><b><?= count($bayar) ?></b></div>
  <div class="stat"><span>Tiket terbuka</span><b><?= count($tiket) ?></b></div>
  <div class="stat"><span>Kamar terisi</span><b><?= (int)$kamar['terisi'] ?> / <?= (int)$kamar['total'] ?></b></div>
</div>
<div class="card"><h3>Perlu tindakan</h3>
<div class="table-wrap"><table class="table"><thead><tr><th>Jenis</th><th>Nama</th><th>Detail</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($berkas as $b): ?><tr><td><?= badge('Berkas ' . $b['docs'], 'amber') ?></td><td>Calon penghuni</td><td>Kamar <?= e($b['nomor_kamar']) ?> · ditahan</td>
  <td class="actions"><form method="post" action="<?= e(url('admin/verify_user.php')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id_user" value="<?= $b['id_user'] ?>"><input type="hidden" name="back" value="dashboard"><button class="btn btn-sm">Setujui</button></form>
  <a class="btn btn-outline btn-sm" href="<?= e(url('admin/verify_user.php#u' . $b['id_user'])) ?>">Tolak</a></td></tr>
<?php endforeach; foreach ($bayar as $p): ?><tr><td><?= badge('Bayar ' . ($p['payment_method'] === 'CASH' ? 'Tunai' : 'QRIS') . ($p['payment_type'] === 'FIRST_MONTH' ? ' (pertama)' : ''), 'amber') ?></td><td><?= e($p['nama_lengkap']) ?></td>
  <td><?= rupiah($p['amount']) ?> · <?= $p['payment_method'] === 'QRIS' ? 'bukti terlampir' : 'tunai' ?></td>
  <td class="actions"><form method="post" action="<?= e(url('admin/verify_payment.php')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id_payment" value="<?= $p['id_payment'] ?>"><input type="hidden" name="back" value="dashboard"><button class="btn btn-sm">Setujui</button></form>
  <a class="btn btn-outline btn-sm" href="<?= e(url('admin/verify_payment.php#p' . $p['id_payment'])) ?>">Tolak</a></td></tr>
<?php endforeach; foreach ($tiket as $t): ?><tr><td><?= badge('Tiket', 'amber') ?></td><td><?= e($t['nama_lengkap']) ?></td><td><?= e($t['subject']) ?> · terbuka</td>
  <td><form method="post" action="<?= e(url('admin/tickets.php')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id_ticket" value="<?= $t['id_ticket'] ?>"><input type="hidden" name="status" value="IN_PROGRESS"><input type="hidden" name="back" value="dashboard"><button class="btn btn-outline btn-sm">Proses</button></form></td></tr>
<?php endforeach; if (!$berkas && !$bayar && !$tiket): ?><tr><td colspan="4" class="muted">Tidak ada yang perlu ditindak. 🎉</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php app_end();
