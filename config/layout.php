<?php
function page_head(string $title, string $bodyClass = ''): void { ?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · KosKita</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>"></head>
<body class="<?= e($bodyClass) ?>">
<?php }
function page_foot(): void { echo '<script src="' . e(url('assets/js/app.js')) . '"></script></body></html>'; }

// ---------- Header publik (landing, booking, onboarding, pembayaran pertama) ----------
function public_header(string $title): void {
    $u = currentUser(); page_head($title, 'public'); ?>
<header class="topbar"><a class="brand" href="<?= e(url('index.php')) ?>">KosKita</a>
<nav class="topnav">
<?php if (!$u): ?>
  <a class="btn btn-outline" href="<?= e(url('login.php')) ?>">Masuk</a>
  <a class="btn" href="<?= e(url('register.php')) ?>">Daftar</a>
<?php else: ?>
  <a href="<?= e(url('index.php#kamar')) ?>">Kamar</a>
  <a href="<?= e(url('index.php#fasilitas')) ?>">Fasilitas</a>
  <?php if ($u['role'] !== 'USER' || $u['user_status'] === 'TENANT'): ?><a href="<?= e(url(homeFor($u))) ?>">Dashboard</a>
  <?php elseif ($u['user_status'] === 'PAYMENT_PENDING'): ?><a href="<?= e(url('first_payment.php')) ?>">Pembayaran</a>
  <?php elseif ($u['id_room']): ?><a href="<?= e(url('onboarding.php')) ?>">Pesanan saya</a><?php endif; ?>
  <?php if (APP_DEBUG && $u['role'] === 'USER'): ?><a href="<?= e(url('mailbox.php')) ?>">Email</a><?php endif; ?>
  <span class="chip"><?= e($u['nama_lengkap']) ?></span>
  <a class="btn btn-outline" href="<?= e(url('logout.php')) ?>">Keluar</a>
<?php endif; ?>
</nav></header>
<main class="container"><?= flash_html() ?>
<?php }
function public_footer(): void { echo '</main><footer class="footer">© ' . date('Y') . ' KosKita · Hunian nyaman, urusan kos jadi mudah.</footer>'; page_foot(); }

// ---------- Halaman login / daftar (split) ----------
function auth_start(string $title): void { page_head($title, 'auth'); ?>
<div class="auth-split">
  <aside class="auth-side"><a class="brand" href="<?= e(url('index.php')) ?>">KosKita</a>
    <div><h1>Hunian nyaman, urusan kos jadi mudah.</h1><p>Pesan kamar, bayar sewa, dan lapor kendala dalam satu tempat.</p></div>
    <small>Satu halaman masuk untuk semua peran</small></aside>
  <section class="auth-main"><div class="auth-box"><?= flash_html() ?>
<?php }
function auth_end(): void { echo '</div></section></div>'; page_foot(); }

// ---------- Shell dashboard (sidebar) ----------
function app_start(string $role, string $active, string $title, string $sub = ''): void {
    $menus = [
        'SUPERADMIN' => ['dashboard' => ['Dashboard', 'superadmin/dashboard.php'], 'kosan' => ['Kelola Kosan', 'superadmin/manage_kosan.php'], 'admin' => ['Kelola Admin', 'superadmin/manage_admin.php'], 'fasilitas' => ['Kelola Fasilitas', 'superadmin/manage_facilities.php']],
        'ADMIN' => ['dashboard' => ['Dashboard', 'admin/dashboard.php'], 'rooms' => ['Kamar', 'admin/manage_rooms.php'], 'tenants' => ['Penghuni', 'admin/manage_tenants.php'], 'verify' => ['Verifikasi', 'admin/verify_user.php'], 'payment' => ['Pembayaran', 'admin/verify_payment.php'], 'tickets' => ['Tiket', 'admin/tickets.php']],
        'TENANT' => ['dashboard' => ['Beranda', 'tenant/dashboard.php'], 'pay' => ['Pembayaran', 'tenant/pay.php'], 'ticket' => ['Pengaduan', 'tenant/create_ticket.php']],
    ];
    page_head($title, 'app-page'); ?>
<div class="app">
  <aside class="sidebar"><a class="brand" href="<?= e(url($menus[$role]['dashboard'][1])) ?>">KosKita</a>
    <?php if ($role === 'SUPERADMIN'): ?><div class="role-label">Superadmin</div><?php elseif ($role === 'ADMIN'): ?><div class="role-label">Admin Site</div><?php endif; ?>
    <nav>
      <?php foreach ($menus[$role] as $k => [$label, $href]): ?><a href="<?= e(url($href)) ?>" class="<?= $k === $active ? 'active' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
      <a href="<?= e(url('logout.php')) ?>">Keluar</a>
    </nav></aside>
  <main class="app-main">
    <h1><?= e($title) ?></h1><?php if ($sub): ?><p class="sub"><?= e($sub) ?></p><?php endif; ?>
    <?= flash_html() ?>
<?php }
function app_end(): void { echo '</main></div>'; page_foot(); }

// Banner status untuk calon penghuni di landing page
function candidate_banner(array $u): void {
    if ($u['role'] !== 'USER' || $u['user_status'] === 'TENANT') return;
    if ($u['user_status'] === 'GUEST' && $u['id_room']) echo '<div class="banner">Anda memesan <b>' . e($u['nama_kos']) . ' · ' . e($u['nomor_kamar']) . '</b>. <a class="link" href="' . e(url('onboarding.php')) . '">Lengkapi berkas identitas</a></div>';
    elseif ($u['user_status'] === 'PENDING_VERIFICATION') echo '<div class="banner">Berkas Anda sedang diverifikasi pengelola. Hasilnya akan dikirim lewat email.</div>';
    elseif ($u['user_status'] === 'PAYMENT_PENDING') echo '<div class="banner banner-ok">Berkas disetujui. <a class="link" href="' . e(url('first_payment.php')) . '">Lakukan pembayaran pertama</a></div>';
}
