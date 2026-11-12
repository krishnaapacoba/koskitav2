<?php
// Router untuk server bawaan PHP:  php -S localhost:8000 router.php
// Menutup akses langsung ke folder upload privat (di Apache sudah ditutup lewat .htaccess).
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#/uploads/(documents|payments|tickets)/#', $p)) { http_response_code(403); exit('Forbidden'); }
return false;
