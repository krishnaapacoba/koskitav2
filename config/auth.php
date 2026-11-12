<?php
require_once __DIR__ . '/helpers.php';
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function currentUser(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    if (empty($_SESSION['uid'])) return $cache = null;
    $st = db()->prepare('SELECT u.*, r.nomor_kamar, r.harga_per_bulan, r.status_kamar, r.id_kosan, k.nama_kos, k.qris_path
        FROM users u LEFT JOIN rooms r ON r.id_room=u.id_room LEFT JOIN kosan k ON k.id_kosan=r.id_kosan WHERE u.id_user=?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    if (!$u || $u['user_status'] === 'INACTIVE') { unset($_SESSION['uid']); return $cache = null; }
    return $cache = $u;
}

function homeFor(array $u): string {
    if ($u['role'] === 'SUPERADMIN') return 'superadmin/dashboard.php';
    if ($u['role'] === 'ADMIN') return 'admin/dashboard.php';
    if ($u['user_status'] === 'TENANT') return 'tenant/dashboard.php';
    if ($u['user_status'] === 'PAYMENT_PENDING') return 'first_payment.php';
    return 'index.php';
}

function checkLogin(): array {
    $u = currentUser();
    if (!$u) {
        $next = ltrim(substr($_SERVER['REQUEST_URI'] ?? '', strlen(BASE_URL)), '/');
        redirect('login.php' . (safeNext($next) ? '?next=' . urlencode($next) : ''));
    }
    return $u;
}
function checkRole(array $roles): array {
    $u = checkLogin();
    if (!in_array($u['role'], $roles, true)) { flash('error', 'Anda tidak memiliki akses ke halaman tersebut.'); redirect(homeFor($u)); }
    return $u;
}
function requireTenant(): array {
    $u = checkRole(['USER']);
    if ($u['user_status'] !== 'TENANT') { flash('error', 'Dashboard penghuni terbuka setelah pembayaran pertama diverifikasi admin.'); redirect(homeFor($u)); }
    return $u;
}
function adminKosanIds(): array {
    $u = currentUser();
    if (!$u || $u['role'] !== 'ADMIN') return [];
    $st = db()->prepare('SELECT id_kosan FROM admin_kosan_access WHERE id_user=?');
    $st->execute([$u['id_user']]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
function loginUser(int $id): void { session_regenerate_id(true); $_SESSION['uid'] = $id; }
