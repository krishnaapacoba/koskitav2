<?php
// ====== Konfigurasi KosKita (ubah sesuai lingkungan Anda) ======
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'koskitav2');
define('DB_USER', getenv('DB_USER') ?: 'postgres');
define('DB_PASS', getenv('DB_PASS') ?: 'ksd');

define('APP_DEBUG', true);                 // true: menu "Email" (kotak email simulasi) tampil
define('MAIL_FROM', 'no-reply@koskita.local');
define('MAX_UPLOAD', 5 * 1024 * 1024);     // 5 MB per file

date_default_timezone_set('Asia/Jakarta');

// BASE_URL terdeteksi otomatis (mis. "/koskita" di XAMPP, "" di php -S)
$__root = rtrim(str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
$__app  = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
define('BASE_URL', ($__root !== '' && strpos($__app, $__root) === 0) ? substr($__app, strlen($__root)) : '');
