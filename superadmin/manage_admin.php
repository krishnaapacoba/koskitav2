<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = checkRole(['SUPERADMIN']); $db = db();
$kosans = $db->query('SELECT id_kosan,nama_kos FROM kosan ORDER BY nama_kos')->fetchAll(); $valid = array_map('intval', array_column($kosans, 'id_kosan'));

function assignKosan(PDO $db, int $adminId, int $kosanId): void {
    $db->prepare('DELETE FROM admin_kosan_access WHERE id_user=?')->execute([$adminId]);
    if ($kosanId) $db->prepare('INSERT INTO admin_kosan_access(id_user,id_kosan) VALUES (?,?)')->execute([$adminId, $kosanId]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $act = $_POST['action'] ?? ''; $k = (int)($_POST['id_kosan'] ?? 0); if ($k && !in_array($k, $valid, true)) $k = 0;
    if ($act === 'create') {
        $nama = clean($_POST['nama'] ?? ''); $email = strtolower(trim($_POST['email'] ?? '')); $pw = $_POST['password'] ?? '';
        $c = $db->prepare('SELECT 1 FROM users WHERE lower(email)=?'); $c->execute([$email]);
        if ($nama === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('error', 'Nama dan email valid wajib diisi.');
        elseif (strlen($pw) < 6) flash('error', 'Password minimal 6 karakter.');
        elseif ($c->fetchColumn()) flash('error', 'Email sudah terdaftar.');
        else {
            $db->beginTransaction();
            $i = $db->prepare("INSERT INTO users(nama_lengkap,email,password_hash,role) VALUES (?,?,?,'ADMIN') RETURNING id_user");
            $i->execute([$nama, $email, password_hash($pw, PASSWORD_BCRYPT)]); assignKosan($db, (int)$i->fetchColumn(), $k);
            $db->commit(); flash('success', 'Akun admin dibuat.');
        }
    } elseif ($act === 'set') { assignKosan($db, (int)($_POST['id_user'] ?? 0), $k); flash('success', 'Penugasan diperbarui.'); }
    elseif ($act === 'delete') { $db->prepare("DELETE FROM users WHERE id_user=? AND role='ADMIN'")->execute([(int)($_POST['id_user'] ?? 0)]); flash('success', 'Akun admin dihapus.'); }
    redirect('superadmin/manage_admin.php');
}
$admins = $db->query("SELECT u.id_user,u.nama_lengkap,u.email,(SELECT id_kosan FROM admin_kosan_access WHERE id_user=u.id_user LIMIT 1) AS kos FROM users u WHERE u.role='ADMIN' ORDER BY u.id_user")->fetchAll();
$opts = function ($sel) use ($kosans) { $h = '<option value="0">— Belum ditugaskan —</option>'; foreach ($kosans as $k) $h .= '<option value="' . $k['id_kosan'] . '"' . ((int)$sel === (int)$k['id_kosan'] ? ' selected' : '') . '>' . e($k['nama_kos']) . '</option>'; return $h; };

app_start('SUPERADMIN', 'admin', 'Kelola Pengelola Site (Admin)', 'Buat akun Admin site dan tentukan kosan mana yang dikelola.'); ?>
<form class="card" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create"><h3>Buat akun Admin site baru</h3>
  <div class="grid g3"><label class="field">Nama lengkap admin<input type="text" name="nama" placeholder="Contoh: Budi Santoso" required></label>
  <label class="field">Email admin<input type="email" name="email" placeholder="admin@email.com" required></label>
  <label class="field">Password akun<input type="password" name="password" placeholder="Minimal 6 karakter" minlength="6" required></label></div>
  <label class="field">Tugaskan ke site kosan<select name="id_kosan"><?= $opts(0) ?></select></label><button class="btn">Buat akun admin</button></form>
<div class="card"><h3>Daftar admin dan site yang dikelola</h3><div class="table-wrap"><table class="table tint"><thead><tr><th>ID</th><th>Nama admin</th><th>Email</th><th>Site tanggung jawab</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($admins as $a): ?><tr><td><b>#<?= $a['id_user'] ?></b></td><td><?= e($a['nama_lengkap']) ?></td><td><?= e($a['email']) ?></td>
  <td><form method="post" class="actions" style="flex-wrap:nowrap"><?= csrf_field() ?><input type="hidden" name="action" value="set"><input type="hidden" name="id_user" value="<?= $a['id_user'] ?>"><select name="id_kosan" style="margin:0"><?= $opts($a['kos']) ?></select><button class="btn btn-sm">Set</button></form></td>
  <td><form method="post" class="inline" data-confirm="Hapus akun admin <?= e($a['nama_lengkap']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id_user" value="<?= $a['id_user'] ?>"><button class="btn btn-red btn-sm">Hapus</button></form></td></tr>
<?php endforeach; if (!$admins): ?><tr><td colspan="5" class="muted">Belum ada admin.</td></tr><?php endif; ?></tbody></table></div></div>
<?php app_end();
