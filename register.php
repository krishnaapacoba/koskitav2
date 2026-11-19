<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/layout.php';
if ($u = currentUser()) redirect(homeFor($u));

$next = safeNext($_GET['next'] ?? $_POST['next'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nama = clean($_POST['nama_lengkap'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';

    if ($nama === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6) {
        flash('error', 'Lengkapi data dengan benar (kata sandi minimal 6 karakter).');
        redirect('register.php' . ($next ? '?next=' . urlencode($next) : ''));
    }

    $db = db();
    $cek = $db->prepare('SELECT 1 FROM users WHERE lower(email)=lower(?)');
    $cek->execute([$email]);
    if ($cek->fetchColumn()) {
        flash('error', 'Email sudah terdaftar. Silakan masuk.');
        redirect('login.php' . ($next ? '?next=' . urlencode($next) : ''));
    }

    $db->prepare("INSERT INTO users (nama_lengkap, email, password_hash, role, user_status) VALUES (?, ?, ?, 'USER', 'GUEST')")
       ->execute([$nama, $email, password_hash($pass, PASSWORD_DEFAULT)]);

    flash('success', 'Pendaftaran berhasil. Silakan masuk untuk melanjutkan pemesanan.');
    redirect('login.php' . ($next ? '?next=' . urlencode($next) : ''));
}

auth_start('Daftar'); ?>
<h2>Daftar</h2>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= e($next) ?>">
  <label class="field">Nama lengkap<input type="text" name="nama_lengkap" placeholder="Nama lengkap" required autofocus></label>
  <label class="field">Email<input type="email" name="email" placeholder="nama@email.com" required></label>
  <label class="field">Kata sandi<input type="password" name="password" placeholder="Minimal 6 karakter" minlength="6" required></label>
  <button class="btn btn-block" type="submit">Daftar</button>
</form>
<p class="auth-alt">Sudah punya akun? <a href="<?= e(url('login.php' . ($next ? '?next=' . urlencode($next) : ''))) ?>"><b>Masuk</b></a> · <a href="<?= e(url('index.php')) ?>">Kembali ke beranda</a></p>
<?php auth_end();