<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = checkRole(['ADMIN']); $db = db(); $arr = pgArr(adminKosanIds());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $status = $_POST['status'] ?? '';
    if (in_array($status, ['OPEN', 'IN_PROGRESS', 'RESOLVED'], true)) {
        $s = $db->prepare('UPDATE tickets SET status=CAST(? AS ticket_status_enum) WHERE id_ticket=? AND id_kosan=ANY(CAST(? AS int[]))');
        $s->execute([$status, (int)($_POST['id_ticket'] ?? 0), $arr]);
        flash('success', 'Status tiket diperbarui.');
    }
    redirect(($_POST['back'] ?? '') === 'dashboard' ? 'admin/dashboard.php' : 'admin/tickets.php');
}
$s = $db->prepare("SELECT t.*,u.nama_lengkap,k.nama_kos FROM tickets t JOIN users u USING(id_user) LEFT JOIN kosan k ON k.id_kosan=t.id_kosan
    WHERE t.id_kosan=ANY(CAST(? AS int[])) ORDER BY (t.status='RESOLVED'), t.id_ticket DESC"); $s->execute([$arr]); $tickets = $s->fetchAll();

app_start('ADMIN', 'tickets', 'Tiket Pengaduan', 'Pantau dan perbarui status laporan kendala fasilitas.');
foreach ($tickets as $t): $photos = jsonList($t['ticket_proof_path']); ?>
<div class="card"><div class="row-between"><div><b style="font-size:1.05rem"><?= e($t['subject']) ?></b><div class="muted small"><?= e($t['nama_lengkap']) ?> · <?= e($t['nama_kos']) ?> · <?= e(tgl($t['created_at'])) ?></div></div><?= statusBadge('ticket', $t['status']) ?></div>
  <p><?= nl2br(e($t['description'])) ?></p>
  <?php if ($photos): ?><div class="thumbs" style="margin-bottom:1rem"><?php foreach ($photos as $i => $ph): $h = url('file.php?t=ticket&id=' . $t['id_ticket'] . '&i=' . $i); ?><a href="<?= e($h) ?>" target="_blank"><img src="<?= e($h) ?>" alt="Bukti"></a><?php endforeach; ?></div><?php endif; ?>
  <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="id_ticket" value="<?= $t['id_ticket'] ?>">
    <?php if ($t['status'] === 'OPEN'): ?><button name="status" value="IN_PROGRESS" class="btn btn-outline btn-sm">Proses</button><?php endif; ?>
    <?php if ($t['status'] !== 'RESOLVED'): ?><button name="status" value="RESOLVED" class="btn btn-sm">Tandai selesai</button><?php else: ?><button name="status" value="OPEN" class="btn btn-outline btn-sm">Buka kembali</button><?php endif; ?></form></div>
<?php endforeach; if (!$tickets): ?><div class="card muted">Belum ada tiket pengaduan.</div><?php endif;
app_end();
