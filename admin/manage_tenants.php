<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = checkRole(['ADMIN']); $db = db(); $arr = pgArr(adminKosanIds());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $id = (int)($_POST['id_user'] ?? 0); $act = $_POST['action'] ?? '';
    $s = $db->prepare("SELECT u.*,r.id_room AS rid FROM users u JOIN rooms r ON r.id_room=u.id_room WHERE u.id_user=? AND u.user_status='TENANT' AND r.id_kosan=ANY(CAST(? AS int[]))");
    $s->execute([$id, $arr]); $t = $s->fetch();
    if (!$t) flash('error', 'Penghuni tidak ditemukan.');
    elseif ($act === 'reset') {
        $new = substr(bin2hex(random_bytes(4)), 0, 8);
        $db->prepare('UPDATE users SET password_hash=? WHERE id_user=?')->execute([password_hash($new, PASSWORD_BCRYPT), $id]);
        flash('success', 'Password ' . $t['nama_lengkap'] . ' direset menjadi: ' . $new . ' — catat dan berikan kepada penghuni.');
    } elseif ($act === 'checkout') {                // soft deactivation: riwayat pembayaran & tiket tetap tersimpan
        $db->beginTransaction();
        $db->prepare("UPDATE users SET user_status='INACTIVE',id_room=NULL WHERE id_user=?")->execute([$id]);
        $db->prepare("UPDATE rooms SET status_kamar='AVAILABLE' WHERE id_room=?")->execute([$t['rid']]);
        $db->commit();
        flash('success', $t['nama_lengkap'] . ' telah checkout. Kamar kembali tersedia.');
    }
    redirect('admin/manage_tenants.php');
}
$s = $db->prepare("SELECT u.id_user,u.nama_lengkap,u.email,r.nomor_kamar,k.nama_kos,
    (SELECT MAX(due_date) FROM payments p WHERE p.id_user=u.id_user AND p.payment_status='PAID') AS last_paid
    FROM users u JOIN rooms r ON r.id_room=u.id_room JOIN kosan k ON k.id_kosan=r.id_kosan
    WHERE u.user_status='TENANT' AND r.id_kosan=ANY(CAST(? AS int[])) ORDER BY k.nama_kos,r.nomor_kamar");
$s->execute([$arr]); $rows = $s->fetchAll();

app_start('ADMIN', 'tenants', 'Data Penghuni', 'Penghuni aktif pada kosan yang Anda kelola.'); ?>
<div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>Nama</th><th>Email</th><th>Kosan / Kamar</th><th>Periode lunas terakhir</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><b><?= e($r['nama_lengkap']) ?></b></td><td><?= e($r['email']) ?></td><td><?= e($r['nama_kos']) ?> · <?= e($r['nomor_kamar']) ?></td><td><?= $r['last_paid'] ? e(bulanTahun($r['last_paid'])) : '-' ?></td>
  <td class="actions">
   <form method="post" class="inline" data-confirm="Reset password <?= e($r['nama_lengkap']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id_user" value="<?= $r['id_user'] ?>"><button class="btn btn-outline btn-sm">Reset password</button></form>
   <form method="post" class="inline" data-confirm="Checkout <?= e($r['nama_lengkap']) ?>? Akun akan dinonaktifkan dan kamar dikosongkan."><?= csrf_field() ?><input type="hidden" name="action" value="checkout"><input type="hidden" name="id_user" value="<?= $r['id_user'] ?>"><button class="btn btn-red btn-sm">Checkout</button></form></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="5" class="muted">Belum ada penghuni aktif.</td></tr><?php endif; ?></tbody></table></div></div>
<?php app_end();
