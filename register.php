<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/layout.php';
if ($u = currentUser()) redirect(homeFor($u));
$next = safeNext($_GET['next'] ?? $_POST['next'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nama = clean($_POST['nama'] ?? ''); $email = strtolower(trim($_POST['email'] ?? '')); $pw = $_POST['password'] ?? '';
    $err = null;
    if ($nama === '' || strlen($nama) > 100) $err = 'Nama lengkap wajib diisi.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Format email tidak valid.';
    elseif (strlen($pw) < 6) $err = 'Kata sandi minimal 6 karakter.';
    elseif ($pw !== ($_POST['password2'] ?? '')) $err = 'Konfirmasi kata sandi tidak cocok.';
    else {
        $db = db(); $c = $db->prepare('SELECT 1 FROM users WHERE lower(email)=?'); $c->execute([$email]);
        if ($c->fetchColumn()) $err = 'Email sudah terdaftar. Silakan masuk.';
        else {
            $ins = $db->prepare("INSERT INTO users(nama_lengkap,email,password_hash,role,user_status) VALUES (?,?,?,'USER','GUEST') RETURNING id_user");
            $ins->execute([$nama, $email, password_hash($pw, PASSWORD_BCRYPT)]);
            loginUser((int)$ins->fetchColumn());
            flash('success', 'Pendaftaran berhasil. Silakan pilih kamar yang ingin dipesan.');
            redirect('index.php#kamar');   // setelah daftar langsung ke landing page untuk memilih kamar
        }
    }
    flash('error', $err);
    redirect('register.php' . ($next ? '?next=' . urlencode($next) : ''));
}
auth_start('Daftar'); ?>
<h2>Daftar</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
  <label class="field">Nama lengkap<input type="text" name="nama" placeholder="Contoh: Budi Santoso" required maxlength="100"></label>
  <label class="field">Email<input type="email" name="email" placeholder="nama@email.com" required></label>
  <label class="field">Kata sandi<input type="password" name="password" placeholder="Minimal 6 karakter" required minlength="6"></label>
  <label class="field">Ulangi kata sandi<input type="password" name="password2" placeholder="Ulangi kata sandi" required></label>
  <button class="btn btn-block" type="submit">Buat akun</button>
</form>
<p class="auth-alt">Sudah punya akun? <a href="<?= e(url('login.php' . ($next ? '?next=' . urlencode($next) : ''))) ?>"><b>Masuk</b></a> · <a href="<?= e(url('index.php')) ?>">Kembali ke beranda</a></p>
<?php auth_end();
