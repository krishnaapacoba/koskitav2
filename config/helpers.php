<?php
require_once __DIR__ . '/database.php';

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function rupiah($n): string { return 'Rp ' . number_format((float)$n, 0, ',', '.'); }
function clean($s): string { return trim(strip_tags((string)$s)); }
function url(string $p = ''): string { return BASE_URL . '/' . ltrim($p, '/'); }
function absUrl(string $p = ''): string {
    $s = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $s . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url($p);
}
function redirect(string $p) {
    header('Location: ' . (preg_match('#^https?://#', $p) ? $p : url($p)));
    exit;
}
function safeNext(?string $n): ?string {
    if (!$n || !preg_match('#^[A-Za-z0-9_\-/.?=&%\#]+$#', $n) || strpos($n, '..') !== false || strpos($n, '//') !== false || $n[0] === '/') return null;
    return $n;
}
function pgArr(array $ids): string { return '{' . implode(',', array_map('intval', $ids)) . '}'; }

// ---------- flash & CSRF ----------
function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function flash_html(): string {
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$t, $m]) $out .= '<div class="flash flash-' . e($t) . '">' . e($m) . '</div>';
    unset($_SESSION['flash']);
    return $out;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Sesi tidak valid. Muat ulang halaman.'); }
}

// ---------- tanggal ----------
const BULAN = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
function tgl($d): string { if (!$d) return '-'; $t = strtotime($d); return date('j', $t) . ' ' . BULAN[(int)date('n', $t)] . ' ' . date('Y', $t); }
function bulanTahun($d): string { $t = strtotime($d); return BULAN[(int)date('n', $t)] . ' ' . date('Y', $t); }

// ---------- badge ----------
function badge(string $text, string $cls = 'teal'): string { return '<span class="badge badge-' . $cls . '">' . e($text) . '</span>'; }
function statusBadge(string $kind, string $v): string {
    $m = [
        'payment' => ['PENDING' => ['Menunggu verifikasi', 'amber'], 'PAID' => ['Lunas', 'teal'], 'REJECTED' => ['Ditolak', 'red']],
        'doc'     => ['PENDING' => ['Menunggu', 'amber'], 'APPROVED' => ['Disetujui', 'teal'], 'REJECTED' => ['Ditolak', 'red']],
        'ticket'  => ['OPEN' => ['Terbuka', 'amber'], 'IN_PROGRESS' => ['Diproses', 'amber'], 'RESOLVED' => ['Selesai', 'teal']],
        'room'    => ['AVAILABLE' => ['Tersedia', 'teal'], 'ON_HOLD' => ['Ditahan', 'amber'], 'OCCUPIED' => ['Terisi', 'gray']],
    ];
    [$t, $c] = $m[$kind][$v] ?? [$v, 'gray'];
    return badge($t, $c);
}
function kategoriLabel(string $k): string { return ucfirst(strtolower($k)); }
const DOC_LABEL = ['KTP' => 'KTP', 'KK' => 'Kartu Keluarga (KK)', 'BUKU_NIKAH' => 'Buku Nikah', 'LAINNYA' => 'Dokumen lainnya'];

// ---------- upload ----------
function normalizeFiles(string $key): array {
    if (empty($_FILES[$key])) return [];
    $f = $_FILES[$key]; $out = [];
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $n) {
            if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }
    } elseif ($f['error'] !== UPLOAD_ERR_NO_FILE) $out[] = $f;
    return $out;
}
function saveUpload(array $f, string $sub, array $allow = ['jpg', 'png', 'webp']): string {
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Gagal mengunggah file.');
    if ($f['size'] > MAX_UPLOAD) throw new RuntimeException('Ukuran file maksimal 5 MB.');
    $mimes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
    $ext = array_search((new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']), $mimes, true);
    if (!$ext || !in_array($ext, $allow, true)) throw new RuntimeException('Format file tidak didukung (' . strtoupper(implode('/', $allow)) . ').');
    $dir = __DIR__ . '/../uploads/' . $sub;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) throw new RuntimeException('File tidak dapat disimpan. Periksa izin folder uploads.');
    return "uploads/$sub/$name";
}
function deleteFile(?string $rel): void {
    if (!$rel || strpos($rel, 'uploads/') !== 0 || strpos($rel, '..') !== false) return;
    $p = __DIR__ . '/../' . $rel;
    if (is_file($p)) @unlink($p);
}
function jsonList($t): array { $a = json_decode((string)$t, true); return is_array($a) ? $a : []; }
function isImage(string $path): bool { return (bool)preg_match('/\.(jpe?g|png|webp)$/i', $path); }

// ---------- fasilitas ----------
function allFacilities(): array { return db()->query('SELECT * FROM facilities ORDER BY nama_fasilitas')->fetchAll(); }

// ---------- email (dicatat ke email_outbox + mail()) ----------
function sendMail(int $idUser, string $to, string $subject, string $bodyHtml, ?array $button = null): void {
    $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;padding:24px;border:1px solid #e5e0d5;border-radius:12px;color:#1f2937">'
          . '<h2 style="color:#0f766e;margin:0 0 16px">KosKita</h2>' . $bodyHtml;
    if ($button) $html .= '<p style="margin-top:24px"><a href="' . e($button[1]) . '" style="background:#0f766e;color:#fff;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:bold;display:inline-block">' . e($button[0]) . '</a></p>';
    $html .= '</div>';
    $ok = false;
    try { $ok = @mail($to, $subject, $html, "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: KosKita <" . MAIL_FROM . ">"); } catch (Throwable $t) {}
    db()->prepare('INSERT INTO email_outbox(id_user,to_email,subject,body_html,sent) VALUES (?,?,?,?,?)')
        ->execute([$idUser, $to, $subject, $html, $ok ? 'true' : 'false']);
}
