<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/layout.php';
$u = checkLogin();
if (!APP_DEBUG) redirect(homeFor($u));
$st = db()->prepare('SELECT * FROM email_outbox WHERE id_user=? ORDER BY id_email DESC LIMIT 30'); $st->execute([$u['id_user']]); $mails = $st->fetchAll();
public_header('Email'); ?>
<h1>Kotak email (simulasi)</h1><p class="sub">Mode debug: email yang dikirim sistem ke akun Anda tercatat di sini, sehingga alur dapat diuji tanpa server SMTP.</p>
<?php foreach ($mails as $m): ?><div class="card"><div class="row-between"><b><?= e($m['subject']) ?></b><span class="muted small"><?= e(tgl($m['created_at'])) ?> · <?= $m['sent'] ? 'terkirim via mail()' : 'hanya tercatat' ?></span></div><hr style="border:0;border-top:1px solid var(--line);margin:1rem 0"><?= $m['body_html'] ?></div>
<?php endforeach; if (!$mails): ?><p class="muted">Belum ada email.</p><?php endif; ?>
<?php public_footer();
