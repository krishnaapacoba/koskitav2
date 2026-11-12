<?php
// Penyaji file privat (berkas identitas, bukti bayar, foto tiket) dengan pemeriksaan akses.
require_once __DIR__ . '/config/auth.php';
$u = checkLogin(); $db = db();
$t = $_GET['t'] ?? ''; $id = (int)($_GET['id'] ?? 0); $path = null; $ownerId = null; $kosanId = null;

if ($t === 'doc') {
    $s = $db->prepare('SELECT d.file_path,d.id_user,r.id_kosan FROM user_documents d JOIN users x ON x.id_user=d.id_user LEFT JOIN rooms r ON r.id_room=x.id_room WHERE d.id_document=?');
    $s->execute([$id]); if ($r = $s->fetch()) { $path = $r['file_path']; $ownerId = $r['id_user']; $kosanId = $r['id_kosan']; }
} elseif ($t === 'proof') {
    $s = $db->prepare('SELECT payment_proof_path,id_user,id_kosan FROM payments WHERE id_payment=?');
    $s->execute([$id]); if ($r = $s->fetch()) { $path = $r['payment_proof_path']; $ownerId = $r['id_user']; $kosanId = $r['id_kosan']; }
} elseif ($t === 'ticket') {
    $s = $db->prepare('SELECT ticket_proof_path,id_user,id_kosan FROM tickets WHERE id_ticket=?');
    $s->execute([$id]); if ($r = $s->fetch()) { $list = jsonList($r['ticket_proof_path']); $path = $list[(int)($_GET['i'] ?? 0)] ?? null; $ownerId = $r['id_user']; $kosanId = $r['id_kosan']; }
}
$ok = $path && ($u['role'] === 'SUPERADMIN' || (int)$u['id_user'] === (int)$ownerId || ($u['role'] === 'ADMIN' && $kosanId && in_array((int)$kosanId, adminKosanIds(), true)));
$full = $ok ? realpath(__DIR__ . '/' . $path) : false;
if (!$full || strpos($full, realpath(__DIR__ . '/uploads')) !== 0) { http_response_code(404); exit('File tidak ditemukan.'); }
header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($full));
header('Cache-Control: private, max-age=300'); header('X-Content-Type-Options: nosniff');
readfile($full);
