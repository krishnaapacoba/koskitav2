<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/layout.php';
if ($u = currentUser()) redirect(homeFor($u));
$next = safeNext($_GET['next'] ?? $_POST['next'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT * FROM users WHERE lower(email)=lower(?)');
    $st->execute([trim($_POST['email'] ?? '')]);
    $row = $st->fetch();
    if (!$row || !password_verify($_POST['password'] ?? '', $row['password_hash'])) flash('error', 'Email atau kata sandi salah.');
    elseif ($row['user_status'] === 'INACTIVE') flash('error', 'Akun tidak aktif. Hubungi pengelola.');
    else {
        loginUser((int)$row['id_user']);
        if ($row['role'] !== 'USER' || $row['user_status'] === 'TENANT') redirect(homeFor($row));
        redirect($next ?: homeFor($row));
    }
    redirect('login.php' . ($next ? '?next=' . urlencode($next) : ''));
}
auth_start('Masuk'); ?>
<h2>Masuk</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
  <label class="field">Email<input type="email" name="email" placeholder="nama@email.com" required autofocus></label>
  <label class="field">Kata sandi<input type="password" name="password" placeholder="Kata sandi" required></label>
  <button class="btn btn-block" type="submit">Masuk</button>
</form>
<p class="auth-alt">Belum punya akun? <a href="<?= e(url('register.php' . ($next ? '?next=' . urlencode($next) : ''))) ?>"><b>Daftar</b></a> · <a href="<?= e(url('index.php')) ?>">Kembali ke beranda</a></p>
<?php auth_end();
