<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/layout.php';
$u = checkRole(['USER']); $db = db();
if ($u['user_status'] === 'TENANT') redirect('tenant/dashboard.php');
if ($u['user_status'] === 'PAYMENT_PENDING') redirect('first_payment.php');
if (!$u['id_room']) { flash('info', 'Pilih kamar terlebih dahulu.'); redirect('index.php#kamar'); }
$id = (int)$u['id_user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $u['user_status'] === 'GUEST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'cancel') {
        $db->beginTransaction();
        $docs = $db->prepare('SELECT file_path FROM user_documents WHERE id_user=?'); $docs->execute([$id]);
        foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $f) deleteFile($f);
        $db->prepare('DELETE FROM user_documents WHERE id_user=?')->execute([$id]);
        $db->prepare("UPDATE rooms SET status_kamar='AVAILABLE' WHERE id_room=?")->execute([$u['id_room']]);
        $db->prepare('UPDATE users SET id_room=NULL WHERE id_user=?')->execute([$id]);
        $db->commit();
        flash('success', 'Pesanan dibatalkan.'); redirect('index.php#kamar');
    }
    $hasKtp = $db->prepare("SELECT 1 FROM user_documents WHERE id_user=? AND document_type='KTP' AND status<>'REJECTED'");
    $hasKtp->execute([$id]);
    $saved = []; $err = null;
    try {
        foreach (array_keys(DOC_LABEL) as $t) {
            $f = normalizeFiles('doc_' . $t);
            if ($f) $saved[$t] = saveUpload($f[0], 'documents', ['jpg', 'png', 'webp', 'pdf']);
        }
        if (!$saved) throw new RuntimeException('Unggah minimal satu berkas identitas.');
        if (!isset($saved['KTP']) && !$hasKtp->fetchColumn()) throw new RuntimeException('KTP wajib dilampirkan.');
    } catch (RuntimeException $ex) { $err = $ex->getMessage(); foreach ($saved as $p) deleteFile($p); }
    if ($err) { flash('error', $err); redirect('onboarding.php'); }
    $db->beginTransaction();
    foreach ($saved as $t => $path) {
        $old = $db->prepare("SELECT id_document,file_path FROM user_documents WHERE id_user=? AND document_type=CAST(? AS doc_type_enum) AND status='REJECTED'");
        $old->execute([$id, $t]);
        foreach ($old->fetchAll() as $o) { deleteFile($o['file_path']); $db->prepare('DELETE FROM user_documents WHERE id_document=?')->execute([$o['id_document']]); }
        $db->prepare('INSERT INTO user_documents(id_user,document_type,file_path) VALUES (?,CAST(? AS doc_type_enum),?)')->execute([$id, $t, $path]);
    }
    $db->prepare("UPDATE users SET user_status='PENDING_VERIFICATION' WHERE id_user=?")->execute([$id]);
    $db->commit();
    flash('success', 'Berkas terkirim. Hasil verifikasi akan dikirim ke email Anda.');
    redirect('onboarding.php');
}

$docs = $db->prepare('SELECT * FROM user_documents WHERE id_user=? ORDER BY id_document'); $docs->execute([$id]); $docs = $docs->fetchAll();
$rejected = array_values(array_filter($docs, fn($d) => $d['status'] === 'REJECTED'));
$uploadedTypes = [];
foreach ($docs as $d) {
    if ($d['status'] !== 'REJECTED') $uploadedTypes[$d['document_type']] = $d['status'];
}

$isPending = ($u['user_status'] === 'PENDING_VERIFICATION');

public_header('Berkas identitas');
?>

<style>
  /* Navbar Atas Interaktif */
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

  /* Stepper Progres Pemesanan */
  .ob-steps {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: .75rem;
    margin-bottom: 1.35rem;
    background: #fff;
    border: 1px solid var(--line);
    padding: .85rem 1.25rem;
    border-radius: 16px;
  }
  .ob-step { display: flex; align-items: center; gap: .65rem; font-size: .88rem; font-weight: 600; color: var(--muted); }
  .ob-step-num { width: 28px; height: 28px; border-radius: 50%; background: #f1eee5; color: var(--muted); display: flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 800; flex-shrink: 0; }
  .ob-step.done { color: var(--teal-d); }
  .ob-step.done .ob-step-num { background: var(--soft); color: var(--teal-d); }
  .ob-step.active { color: var(--ink); font-weight: 700; }
  .ob-step.active .ob-step-num { background: var(--teal); color: #fff; }
  @media (max-width: 640px) { .ob-steps { grid-template-columns: 1fr; } }

  /* Kartu Ringkasan Kamar Full-Width */
  .ob-room-card {
    padding: 1.6rem 1.85rem;
    border-radius: 20px;
    border: 1.5px solid var(--teal);
    background: #fff;
    margin-bottom: 1.35rem;
  }
  .ob-room-meta {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: .95rem;
    padding-top: .95rem;
    border-top: 1px solid var(--line);
  }

  /* Grid Upload Berkas Modern */
  .doc-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin: 1.25rem 0 1.5rem; }
  @media (max-width: 720px) { .doc-grid { grid-template-columns: 1fr; } }

  .doc-drop {
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 1.15rem 1.25rem;
    border-radius: 15px;
    border: 1.5px dashed #cbd5e1;
    background: #faf9f6;
    cursor: pointer;
    transition: all .15s ease;
  }
  .doc-drop:hover { border-color: var(--teal); background: #EEF4ED; }
  .doc-drop.has-file { border-style: solid; border-color: var(--teal); background: var(--soft); }
  .doc-drop input[type="file"] { display: none; }

  .doc-drop-top { display: flex; justify-content: space-between; align-items: center; gap: .5rem; margin-bottom: .75rem; }
  .doc-drop-title { font-weight: 700; font-size: .95rem; color: var(--ink); }

  .doc-drop-body { display: flex; align-items: center; gap: .75rem; }
  .doc-ico { width: 38px; height: 38px; border-radius: 10px; background: #fff; border: 1px solid var(--line); color: var(--teal); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .doc-drop.has-file .doc-ico { background: var(--teal); color: #fff; border-color: var(--teal); }
  .doc-file-info { min-width: 0; }
  .doc-file-name { display: block; font-size: .86rem; font-weight: 600; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .doc-file-hint { display: block; font-size: .78rem; color: var(--muted); }

  /* Status Verifikasi Banner */
  .verify-wait-box { background: #fff; border: 1.5px solid #fde68a; border-left: 5px solid #f59e0b; border-radius: 18px; padding: 1.4rem 1.75rem; margin-bottom: 1.35rem; }
  .verify-wait-box h3 { margin: 0 0 .25rem; color: var(--amber); font-size: 1.05rem; }
</style>

<div style="width: 100%;">
  <div style="margin-bottom: 1.25rem;">
    <h1 style="margin-bottom: .25rem;">Pesanan saya</h1>
    <p class="muted small" style="margin:0">
      <?= $isPending 
          ? 'Berkas identitas sudah terkirim dan sedang menunggu persetujuan pengelola.' 
          : 'Selesaikan kelengkapan identitas untuk melanjutkan proses masuk kamar.' ?>
    </p>
  </div>

  <!-- Stepper Progres (Tanpa Angka Ganda) -->
  <div class="ob-steps">
    <div class="ob-step done">
      <span class="ob-step-num">&#10003;</span>
      <span>Pilih Unit Kamar</span>
    </div>
    <div class="ob-step active">
      <span class="ob-step-num">2</span>
      <span><?= $isPending ? 'Verifikasi Berkas' : 'Unggah Berkas' ?></span>
    </div>
    <div class="ob-step">
      <span class="ob-step-num">3</span>
      <span>Pembayaran Pertama</span>
    </div>
  </div>

  <!-- Kartu Kamar Dipesan -->
  <div class="ob-room-card">
    <div class="row-between">
      <span class="small muted" style="font-weight:600; text-transform:uppercase; letter-spacing:.04em;">Unit Kamar Dipesan</span>
      <?= statusBadge('room', $u['status_kamar']) ?>
    </div>
    <div style="font-size: 1.45rem; font-weight: 800; color: var(--ink); margin-top: .3rem;">
      <?= e($u['nama_kos']) ?> <span style="color:var(--teal)">· Kamar <?= e($u['nomor_kamar']) ?></span>
    </div>
    <div class="ob-room-meta">
      <div class="small muted">Status unit ditahan sementara khusus untuk pesanan Anda</div>
      <div class="price" style="color:var(--teal-d)"><?= rupiah($u['harga_per_bulan']) ?> <small>/bulan</small></div>
    </div>
  </div>

  <?php if ($isPending): ?>
    <div class="verify-wait-box">
      <h3>Berkas Sedang Diverifikasi Pengelola</h3>
      <p class="small muted" style="margin:0; line-height:1.55;">
        Terima kasih telah melampirkan berkas identitas. Pengelola kos sedang memeriksa dokumen Anda dan akan mengirimkan notifikasi ke email <b><?= e($u['email']) ?></b> setelah disetujui.
      </p>
    </div>
  <?php else: ?>

    <?php if ($rejected): ?>
      <div class="flash flash-error" style="border-radius:16px; padding:1.1rem 1.35rem; margin-bottom:1.25rem;">
        <b style="display:block; margin-bottom:.3rem;">Berkas sebelumnya perlu diperbaiki:</b>
        <?php foreach ($rejected as $d): ?>
          <div class="small">• <b><?= e(DOC_LABEL[$d['document_type']]) ?></b>: <?= e($d['catatan'] ?: 'Tanpa keterangan') ?></div>
        <?php endforeach; ?>
        <div class="small" style="margin-top:.4rem;">Silakan unggah ulang berkas yang sesuai di bawah ini.</div>
      </div>
    <?php endif; ?>

    <!-- Form Unggah Berkas -->
    <form class="card" style="padding:1.75rem 1.85rem; border-radius:20px;" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <h3 style="margin-bottom:.25rem">Lampirkan berkas identitas</h3>
      <p class="muted small" style="margin:0">Format JPG, PNG, WEBP, atau PDF, maksimal 5 MB per file.</p>

      <div class="doc-grid">
        <?php foreach (DOC_LABEL as $t => $l):
          $alreadyUploaded = isset($uploadedTypes[$t]);
        ?>
          <label class="doc-drop <?= $alreadyUploaded ? 'has-file' : '' ?>" id="drop-<?= $t ?>">
            <div class="doc-drop-top">
              <span class="doc-drop-title"><?= e($l) ?></span>
              <?php if ($alreadyUploaded): ?>
                <?= badge('Tersimpan', 'teal') ?>
              <?php elseif ($t === 'KTP'): ?>
                <?= badge('Wajib', 'amber') ?>
              <?php else: ?>
                <?= badge('Opsional', 'gray') ?>
              <?php endif; ?>
            </div>

            <div class="doc-drop-body">
              <div class="doc-ico">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                  <polyline points="14 2 14 8 20 8"></polyline>
                  <line x1="12" y1="18" x2="12" y2="12"></line>
                  <line x1="9" y1="15" x2="15" y2="15"></line>
                </svg>
              </div>
              <div class="doc-file-info">
                <span class="doc-file-name" id="fname-<?= $t ?>">
                  <?= $alreadyUploaded ? 'Berkas sudah terlampir' : 'Pilih file ' . e($l) ?>
                </span>
                <span class="doc-file-hint" id="fhint-<?= $t ?>">
                  <?= $alreadyUploaded ? 'Klik jika ingin mengganti file' : 'Klik untuk memilih dokumen' ?>
                </span>
              </div>
            </div>

            <input type="file" name="doc_<?= $t ?>" accept="image/*,application/pdf" onchange="onDocSelected(this, '<?= $t ?>')">
          </label>
        <?php endforeach; ?>
      </div>

      <div class="row-between" style="padding-top:1.1rem; border-top:1px solid var(--line);">
        <span class="small muted">Pastikan foto berkas terlihat jelas &amp; tidak buram</span>
        <button class="btn" type="submit">Kirim Berkas Identitas</button>
      </div>
    </form>

    <!-- Tombol Batalkan Pesanan -->
    <div class="row-between" style="padding: .65rem .25rem 1.25rem;">
      <span class="small muted">Ingin mengganti unit kamar lain?</span>
      <form method="post" class="inline" data-confirm="Batalkan pesanan dan lepas kamar ini?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <button class="btn btn-red btn-sm" type="submit">Batalkan Pesanan</button>
      </form>
    </div>
  <?php endif; ?>

  <!-- Riwayat Berkas Terunggah -->
  <?php if ($docs): ?>
    <div class="card" style="padding:1.65rem 1.85rem; border-radius:20px; margin-top:.5rem;">
      <div class="row-between" style="margin-bottom:.95rem;">
        <h3 style="margin:0">Berkas terunggah</h3>
        <span class="badge badge-gray"><?= count($docs) ?> dokumen</span>
      </div>
      <div class="table-wrap">
        <table class="table tint" style="width:100%;">
          <thead>
            <tr>
              <th>Jenis Dokumen</th>
              <th>Status</th>
              <th>Waktu Unggah</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($docs as $d): ?>
              <tr>
                <td><b><?= e(DOC_LABEL[$d['document_type']]) ?></b></td>
                <td><?= statusBadge('doc', $d['status']) ?></td>
                <td class="muted small"><?= e(tgl($d['uploaded_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
function onDocSelected(input, key) {
  const box = document.getElementById('drop-' + key);
  const nameEl = document.getElementById('fname-' + key);
  const hintEl = document.getElementById('fhint-' + key);
  if (input.files && input.files.length > 0) {
    box.classList.add('has-file');
    nameEl.textContent = input.files[0].name;
    const sizeKB = Math.round(input.files[0].size / 1024);
    hintEl.textContent = (sizeKB > 1024 ? (sizeKB / 1024).toFixed(1) + ' MB' : sizeKB + ' KB') + ' · Siap dikirim';
  }
}

// Ubah pil nama user di navbar atas menjadi chip profil ber-ikon
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

<?php public_footer(); ?>