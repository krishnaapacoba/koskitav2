<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/layout.php';
$u = checkLogin();
if (!APP_DEBUG) redirect(homeFor($u));
$st = db()->prepare('SELECT * FROM email_outbox WHERE id_user=? ORDER BY id_email DESC LIMIT 30'); $st->execute([$u['id_user']]); $mails = $st->fetchAll();
public_header('Email'); ?>

<style>
  /* Navbar Atas Interaktif (Sama dengan onboarding.php) */
  header nav, .topbar nav, .nav-links {
    display: flex;
    align-items: center;
    gap: .5rem;
  }

  /* Link Menu (Kamar, Fasilitas, Pesanan saya, Email) */
  header nav a:not(.btn):not(.badge),
  .topbar nav a:not(.btn):not(.badge),
  .nav-links a:not(.btn):not(.badge) {
    position: relative;
    padding: .48rem .9rem;
    border-radius: 11px;
    font-weight: 600;
    color: var(--ink);
    text-decoration: none;
    white-space: nowrap;
    transition: background .18s ease, color .18s ease, transform .18s ease;
  }

  header nav a:not(.btn):not(.badge)::after,
  .topbar nav a:not(.btn):not(.badge)::after,
  .nav-links a:not(.btn):not(.badge)::after {
    content: "";
    position: absolute;
    left: 50%;
    bottom: 5px;
    width: 0;
    height: 2px;
    border-radius: 999px;
    background: var(--teal);
    transform: translateX(-50%);
    transition: width .2s ease;
  }

  header nav a:not(.btn):not(.badge):hover,
  .topbar nav a:not(.btn):not(.badge):hover,
  .nav-links a:not(.btn):not(.badge):hover {
    background: #EEF4ED;
    color: var(--teal-d);
    transform: translateY(-1px);
  }

  header nav a:not(.btn):not(.badge):hover::after,
  .topbar nav a:not(.btn):not(.badge):hover::after,
  .nav-links a:not(.btn):not(.badge):hover::after {
    width: 45%;
  }

  /* Chip Profil Akun di Navbar Atas */
  .user-profile-chip {
    display: inline-flex !important;
    align-items: center !important;
    gap: .5rem !important;
    padding: .3rem .85rem .3rem .38rem !important;
    margin-left: .45rem !important;
    background: #FFFFFF !important;
    border: 1.5px solid #D3DFD0 !important;
    border-radius: 999px !important;
    color: var(--ink) !important;
    font-size: .84rem !important;
    font-weight: 700 !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, .04) !important;
    cursor: default !important;
    user-select: none;
    transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease !important;
  }
  .user-profile-chip:hover {
    border-color: var(--teal) !important;
    box-shadow: 0 4px 12px rgba(125, 150, 120, .2) !important;
    transform: translateY(-1px);
  }
  .user-chip-avatar {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--teal);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  /* Ikon Profil Bulat di Sisi Kiri Nama User */
  header nav .badge::before,
  .topbar nav .badge::before,
  .nav-links .badge::before {
    content: "";
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background-color: var(--teal);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23ffffff' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2'/%3E%3Ccircle cx='12' cy='7' r='4'/%3E%3C/svg%3E");
    background-size: 13px 13px;
    background-repeat: no-repeat;
    background-position: center;
    flex-shrink: 0;
  }

  header nav .badge:hover,
  .topbar nav .badge:hover,
  .nav-links .badge:hover {
    background: #fff !important;
    border-color: var(--teal) !important;
    box-shadow: 0 3px 10px rgba(125, 150, 120, .18);
  }

  /* Tombol Keluar */
  header nav a.btn,
  header nav button.btn,
  .topbar nav a.btn,
  .topbar nav button.btn,
  .nav-links a.btn {
    border-radius: 12px;
    transition: background .18s ease, color .18s ease, transform .18s ease, box-shadow .18s ease;
  }
  header nav a.btn:hover,
  header nav button.btn:hover,
  .topbar nav a.btn:hover,
  .topbar nav button.btn:hover,
  .nav-links a.btn:hover {
    background: #dc2626 !important;
    color: #fff !important;
    transform: translateY(-1px);
    box-shadow: 0 5px 14px rgba(220, 38, 38, .22);
  }
  header nav a.btn:active,
  header nav button.btn:active,
  .topbar nav a.btn:active {
    transform: translateY(0);
  }
</style>

<h1>Kotak email (simulasi)</h1><p class="sub">Mode debug: email yang dikirim sistem ke akun Anda tercatat di sini, sehingga alur dapat diuji tanpa server SMTP.</p>
<?php foreach ($mails as $m): ?><div class="card"><div class="row-between"><b><?= e($m['subject']) ?></b><span class="muted small"><?= e(tgl($m['created_at'])) ?> · <?= $m['sent'] ? 'terkirim via mail()' : 'hanya tercatat' ?></span></div><hr style="border:0;border-top:1px solid var(--line);margin:1rem 0"><?= $m['body_html'] ?></div>
<?php endforeach; if (!$mails): ?><p class="muted">Belum ada email.</p><?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const userName = <?= json_encode($u['nama_lengkap'] ?? '') ?>;
  if (!userName) return;

  const topContainer = document.querySelector('header, .topbar, .navbar, nav') || document.body;
  const candidates = topContainer.querySelectorAll('span, div, a, b, strong');

  for (const el of candidates) {
    if (el.children.length === 0 && el.textContent.trim() === userName) {
      el.classList.add('user-profile-chip');
      el.innerHTML = `
        <span class="user-chip-avatar" aria-hidden="true">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
            <circle cx="12" cy="7" r="4"></circle>
          </svg>
        </span>
        <span>${el.textContent.trim()}</span>
      `;
      break;
    }
  }
});
</script>

<?php public_footer();