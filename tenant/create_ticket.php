<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = requireTenant();$db = db();
$id = (int)$u['id_user'];

// Ambil fasilitas kamar penghuni (fallback ke semua fasilitas jika kosong)
$roomFacs = [];
if (!empty($u['id_room'])) {
    $fq =$db->prepare('SELECT f.id_facility, f.nama_fasilitas FROM room_facilities rf JOIN facilities f USING(id_facility) WHERE rf.id_room=? ORDER BY f.nama_fasilitas');
    $fq->execute([(int)$u['id_room']]);
    $roomFacs =$fq->fetchAll();
}
if (!$roomFacs) {$roomFacs = allFacilities();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $titleInput = clean($_POST['subject'] ?? '');$selectedFacs = array_values(array_filter(array_map('clean', $_POST['facilities'] ?? [])));$useOther = !empty($_POST['use_other']);$otherText = clean($_POST['other_facility'] ?? '');$desc = clean($_POST['description'] ?? '');$paths = [];

    try {
        if ($titleInput === '' or strlen($titleInput) > 120) {
            throw new RuntimeException('Judul kendala wajib diisi (maks. 120 karakter).');
        }
        if ($useOther) {
            if ($otherText === '') {
                throw new RuntimeException('Tuliskan nama fasilitas pada kolom "Lainnya".');
            }
            $selectedFacs[] =$otherText;
        }
        if ($desc === '') {
            throw new RuntimeException('Jelaskan rincian kendala yang Anda alami.');
        }

        // Format penyimpanan terstruktur agar Judul & Fasilitas bisa dipisahkan di tabel
        $facPrefix = !empty($selectedFacs) ? '[FAC:' . implode(', ', $selectedFacs) . '] ' : '[FAC:-] ';$storedSubject = mb_substr($facPrefix .$titleInput, 0, 150);

        $files = normalizeFiles('photos');
        if (count($files) > 4) {
            throw new RuntimeException('Maksimal 4 foto.');
        }
        foreach ($files as$f) {
            $paths[] = saveUpload($f, 'tickets');
        }

        $db->prepare('INSERT INTO tickets(id_user,id_kosan,subject,description,ticket_proof_path) VALUES (?,?,?,?,?)')
           ->execute([$id, $u['id_kosan'],$storedSubject, $desc, json_encode($paths)]);
        flash('success', 'Tiket pengaduan terkirim.');
    } catch (RuntimeException $ex) {
        foreach ($paths as$p) {
            deleteFile($p);
        }
        flash('error', $ex->getMessage());
    }
    redirect('tenant/create_ticket.php');
}

$ts =$db->prepare('SELECT * FROM tickets WHERE id_user=? ORDER BY id_ticket DESC');
$ts->execute([$id]);
$tickets =$ts->fetchAll();

$progressByTicket = [];
if ($tickets) {
    $pst = $db->prepare('SELECT p.*, u.nama_lengkap, u.role FROM ticket_progress p
        LEFT JOIN users u ON u.id_user = p.id_user
        WHERE p.id_ticket=ANY(CAST(? AS int[]))
        ORDER BY p.id_progress ASC');
    $pst->execute([pgArr(array_map(fn($t) => (int)$t['id_ticket'], $tickets))]);
    foreach ($pst->fetchAll() as $p) {
        $progressByTicket[(int)$p['id_ticket']][] = $p;
    }
}

$cntOpen = $cntProg = $cntRes = 0;
foreach ($tickets as $t) {
    if ($t['status'] === 'OPEN') $cntOpen++;
    elseif ($t['status'] === 'IN_PROGRESS') $cntProg++;
    else $cntRes++;
}

// Helper untuk memisahkan Judul & Fasilitas (termasuk kompatibilitas tiket lama)
function parseTicketSubject(string $raw): array
{
    if (preg_match('/^\[FAC:(.*?)\]\s*(.*)$/s', $raw,$m)) {
        $facRaw = trim($m[1]);
        $title = trim($m[2]);
        $facs = ($facRaw === '' || $facRaw === '-') ? [] : array_map('trim', explode(',',$facRaw));
        return [
            'title' => $title !== '' ?$title : '-',
            'facs'  => $facs,
        ];
    }
    // Tiket lama sebelum adanya kolom judul: seluruh teks dianggap fasilitas, judul = "-"
    $facs = trim($raw) !== '' ? array_map('trim', explode(',', $raw)) : [];
    return [
        'title' => '-',
        'facs'  => $facs,
    ];
}

app_start('TENANT', 'ticket', 'Pengaduan', 'Laporkan kendala fasilitas kamar lengkap dengan foto bukti.');
?>

<style>
  .tk-card {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 20px;
    padding: 1.75rem 2rem;
    margin-bottom: 1.5rem;
  }

  .tk-card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .75rem;
    margin-bottom: 1.35rem;
    padding-bottom: .9rem;
    border-bottom: 1px solid var(--line);
  }
  .tk-card-head h3 { margin: 0; font-size: 1.1rem; font-weight: 700; }

  .tk-form-grid {
    display: grid;
    grid-template-columns: 1.3fr 1fr;
    gap: 1.75rem;
    align-items: stretch;
  }

  .tk-left-col {
    display: flex;
    flex-direction: column;
    gap: 1.15rem;
  }
  .tk-left-col .field { margin: 0; }
  .field-lbl {
    display: block;
    font-weight: 600;
    font-size: .88rem;
    margin-bottom: .45rem;
  }
  .field-lbl small { font-weight: 400; color: var(--muted); }

  .tk-left-col input[type="text"],
  .tk-left-col textarea {
    background: #FAF9F5;
    border: 1.5px solid #E2DFD5;
    transition: border-color .15s ease, background .15s ease;
  }
  .tk-left-col input[type="text"]:focus,
  .tk-left-col textarea:focus {
    background: #fff;
    border-color: var(--teal);
  }
  .tk-left-col textarea { min-height: 110px; }

  /* Custom Checkbox Fasilitas */
  .fac-check-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: .55rem;
  }
  .fac-check-item {
    display: flex;
    align-items: center;
    gap: .6rem;
    padding: .62rem .85rem;
    border-radius: 12px;
    border: 1.5px solid #E2DFD5;
    background: #FAF9F5;
    color: var(--ink);
    font-size: .88rem;
    font-weight: 500;
    cursor: pointer;
    user-select: none;
    transition: all .15s ease;
  }
  .fac-check-item:hover { border-color: #B4C89F; background: #EEF4ED; }
  .fac-check-item input[type="checkbox"] {
    appearance: none;
    -webkit-appearance: none;
    width: 18px;
    height: 18px;
    margin: 0;
    border-radius: 5px;
    border: 1.8px solid #CBD5E1;
    background: #fff;
    display: inline-grid;
    place-content: center;
    flex-shrink: 0;
    cursor: pointer;
    transition: all .15s ease;
  }
  .fac-check-item input[type="checkbox"]::before {
    content: "";
    width: 9px;
    height: 9px;
    transform: scale(0);
    transition: transform .12s ease-in-out;
    box-shadow: inset 1em 1em #fff;
    clip-path: polygon(14% 44%, 0 65%, 50% 100%, 100% 16%, 80% 0%, 43% 62%);
  }
  .fac-check-item input[type="checkbox"]:checked {
    background: var(--teal);
    border-color: var(--teal);
  }
  .fac-check-item input[type="checkbox"]:checked::before { transform: scale(1); }
  .fac-check-item:has(input:checked) {
    background: var(--soft);
    border-color: var(--teal);
    color: var(--teal-d);
    font-weight: 700;
  }

  .other-input-wrap { display: none; margin-top: .6rem; }
  .other-input-wrap.is-visible { display: block; }

  .tk-right-col { display: flex; flex-direction: column; }

  /* Area Upload Foto */
  .upload-zone {
    flex: 1;
    min-height: 240px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    gap: .4rem;
    padding: 1.5rem 1.25rem;
    border: 1.5px dashed #C7D2C3;
    border-radius: 16px;
    background: #FAFBF9;
    cursor: pointer;
    transition: all .15s ease;
  }
  .upload-zone:hover { border-color: var(--teal); background: #EEF4ED; }
  .upload-zone input[type="file"] { display: none; }
  .upload-zone-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: #fff;
    color: var(--teal);
    border: 1px solid #DCE5D6;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: .2rem;
  }
  .upload-zone strong { font-size: .92rem; color: var(--ink); font-weight: 600; }
  .upload-zone span { font-size: .8rem; color: var(--muted); }

  .preview-list { display: flex; flex-wrap: wrap; gap: .55rem; margin-top: .75rem; }
  .preview-list img { width: 68px; height: 68px; object-fit: cover; border-radius: 10px; border: 1px solid var(--line); }

  .tk-form-footer {
    display: flex;
    justify-content: flex-end;
    margin-top: 1.4rem;
    padding-top: 1.15rem;
    border-top: 1px solid var(--line);
  }
  .tk-form-footer .btn { min-width: 210px; }

  /* Ringkasan status tiket */
  .tk-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: .9rem;
    margin-bottom: 1.4rem;
  }
  .tk-stat {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: .95rem 1.05rem;
    display: flex;
    align-items: center;
    gap: .8rem;
  }
  .tk-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }
  .tk-stat b { display: block; font-size: 1.4rem; line-height: 1.1; }
  .tk-stat span { font-size: .79rem; color: var(--muted); }
  .tk-stat-total .tk-stat-icon { background: var(--soft); color: var(--teal); }
  .tk-stat-open .tk-stat-icon { background: #fee2e2; color: #b91c1c; }
  .tk-stat-prog .tk-stat-icon { background: var(--amber-bg); color: var(--amber); }
  .tk-stat-res .tk-stat-icon { background: #dcfce7; color: #15803d; }

  /* Header form & eyebrow */
  .tk-eyebrow {
    display: inline-block;
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--teal);
    background: var(--soft);
    padding: .22rem .6rem;
    border-radius: 999px;
    margin-bottom: .5rem;
  }
  .tk-card-head p { margin: .3rem 0 0; color: var(--muted); font-size: .88rem; }
  .tk-head-row { display: flex; align-items: center; gap: 1rem; }

  .tk-fac-section {
    background: #FBFCFA;
    border: 1px solid #EDF1EA;
    border-radius: 14px;
    padding: .9rem 1rem;
  }
  .tk-fac-section .field-lbl { margin-bottom: .6rem; }

  /* Riwayat tiket berbentuk kartu */
  .tk-history-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 1rem;
    margin-bottom: 1rem;
  }
  .tk-history-head h3 { margin: 0 0 .2rem; font-size: 1.1rem; font-weight: 700; }
  .tk-history-head p { margin: 0; }
  .ticket-list { display: flex; flex-direction: column; gap: .9rem; }
  .ticket-card {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 18px;
    padding: 1.1rem 1.3rem 1.1rem 1.45rem;
    position: relative;
    overflow: hidden;
    transition: box-shadow .18s ease, transform .18s ease;
  }
  .ticket-card::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 4px;
    background: #cbd5e1;
  }
  .ticket-card.st-OPEN::before { background: #ef4444; }
  .ticket-card.st-IN_PROGRESS::before { background: #f59e0b; }
  .ticket-card.st-RESOLVED::before { background: var(--teal); }
  .ticket-card:hover { box-shadow: 0 8px 24px rgba(31, 41, 55, .07); transform: translateY(-1px); }

  .ticket-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .75rem;
    margin-bottom: .5rem;
  }
  .ticket-id { font-weight: 800; font-size: .82rem; letter-spacing: .02em; }
  .ticket-date { font-size: .78rem; color: var(--muted); margin-left: .55rem; }
  .ticket-title { margin: 0 0 .45rem; font-size: 1.02rem; font-weight: 700; color: var(--ink); }
  .fac-tags { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: .5rem; }
  .fac-pill {
    display: inline-block;
    padding: .22rem .68rem;
    border-radius: 999px;
    font-size: .76rem;
    font-weight: 600;
    background: #EEF4ED;
    color: #3F5141;
  }
  .ticket-desc { margin: 0; color: #4A584B; font-size: .9rem; line-height: 1.55; }

  .ticket-latest {
    display: flex;
    gap: .6rem;
    align-items: flex-start;
    background: #F7FAF8;
    border: 1px dashed #D3E0D4;
    border-radius: 12px;
    padding: .65rem .85rem;
    margin-top: .8rem;
  }
  .ticket-latest-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--teal);
    margin-top: .38rem;
    flex-shrink: 0;
  }
  .ticket-latest strong { font-size: .77rem; color: var(--teal-d); }
  .ticket-latest p {
    margin: .12rem 0 0;
    font-size: .84rem;
    color: #4A584B;
    line-height: 1.45;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .ticket-latest time { font-size: .72rem; color: var(--muted); margin-left: .35rem; font-weight: 400; }

  .ticket-foot {
    display: flex;
    justify-content: flex-end;
    gap: .5rem;
    flex-wrap: wrap;
    margin-top: .9rem;
    padding-top: .85rem;
    border-top: 1px solid #F0EDE5;
  }

  /* Modal Pratinjau Foto Bukti */
  .proof-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 999;
    background: rgba(25, 32, 26, .55);
    backdrop-filter: blur(3px);
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
  }
  .proof-modal.is-open { display: flex; }
  .proof-modal-box {
    background: #fff;
    border-radius: 20px;
    max-width: 680px;
    width: 100%;
    padding: 1.5rem;
    box-shadow: 0 20px 45px rgba(0, 0, 0, .18);
  }
  .proof-modal-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: .75rem;
    border-bottom: 1px solid var(--line);
  }
  .proof-modal-head h4 { margin: 0; font-size: 1.05rem; }
  .proof-modal-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: .85rem;
    max-height: 70vh;
    overflow-y: auto;
  }
  .proof-modal-grid a { display: block; border-radius: 12px; overflow: hidden; border: 1px solid var(--line); }
  .proof-modal-grid img { width: 100%; height: 220px; object-fit: cover; display: block; }

  /* Timeline Progres Tiket */
  .progress-timeline {
    max-height: 65vh;
    overflow-y: auto;
    padding-right: .3rem;
  }
  .progress-item {
    border-left: 3px solid var(--teal);
    background: #FAFBF9;
    border-radius: 0 10px 10px 0;
    padding: .65rem .9rem;
    margin-bottom: .6rem;
  }
  .progress-item:last-child { margin-bottom: 0; }
  .progress-meta {
    font-size: .74rem;
    color: var(--muted);
    margin-bottom: .25rem;
  }
  .progress-note {
    font-size: .89rem;
    color: var(--ink);
    line-height: 1.5;
    white-space: pre-wrap;
  }
  .progress-empty {
    color: var(--muted);
    font-size: .88rem;
    text-align: center;
    padding: 1.25rem 0;
  }

  .tk-empty { text-align: center; padding: 2.5rem 1rem; color: var(--muted); }
  .tk-empty p { margin: 0; font-size: .94rem; font-weight: 500; }

  @media (max-width: 900px) {
    .tk-card { padding: 1.35rem; }
    .tk-form-grid { grid-template-columns: 1fr; }
    .upload-zone { min-height: 160px; }
    .tk-form-footer .btn { width: 100%; }
    .tk-stats { grid-template-columns: repeat(2, 1fr); }
    .ticket-foot .btn { flex: 1; }
  }
  @media (max-width: 520px) {
    .tk-stats { grid-template-columns: 1fr; }
    .tk-history-head { align-items: flex-start; }
    .ticket-card { padding: 1rem 1.1rem 1rem 1.25rem; }
  }
</style>

<!-- Ringkasan Tiket -->
<div class="tk-stats">
  <div class="tk-stat tk-stat-total">
    <div class="tk-stat-icon">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v4a2 2 0 0 0 0 4v4H4v-4a2 2 0 0 0 0-4V4z"></path><path d="M14 4v16"></path></svg>
    </div>
    <div><b><?= count($tickets) ?></b><span>Total tiket</span></div>
  </div>
  <div class="tk-stat tk-stat-open">
    <div class="tk-stat-icon">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v4"></path><path d="M12 16h.01"></path></svg>
    </div>
    <div><b><?= $cntOpen ?></b><span>Menunggu</span></div>
  </div>
  <div class="tk-stat tk-stat-prog">
    <div class="tk-stat-icon">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>
    </div>
    <div><b><?= $cntProg ?></b><span>Diproses</span></div>
  </div>
  <div class="tk-stat tk-stat-res">
    <div class="tk-stat-icon">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
    </div>
    <div><b><?= $cntRes ?></b><span>Selesai</span></div>
  </div>
</div>

<!-- Form Buat Tiket Baru -->
<form class="tk-card" id="form" method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="tk-card-head">
    <div class="tk-head-text">
      <span class="tk-eyebrow">Laporkan kendala</span>
      <h3>Buat Tiket Baru</h3>
      <p>Ceritakan kendala yang Anda alami, tim pengelola akan menindaklanjuti secepatnya.</p>
    </div>
  </div>

  <div class="tk-form-grid">
    <!-- Kiri: Judul Kendala -> Fasilitas -> Rincian -->
    <div class="tk-left-col">
      <label class="field">Judul kendala
        <input type="text" name="subject" maxlength="120" placeholder="Contoh: AC tidak dingin atau keran bocor" required>
      </label>

      <div class="tk-fac-section">
        <span class="field-lbl">Fasilitas yang bermasalah <small>(opsional, bisa pilih lebih dari satu)</small></span>
        <div class="fac-check-grid">
          <?php foreach ($roomFacs as $f): ?>
            <label class="fac-check-item">
              <input type="checkbox" name="facilities[]" value="<?= e($f['nama_fasilitas']) ?>">
              <span><?= e($f['nama_fasilitas']) ?></span>
            </label>
          <?php endforeach; ?>

          <label class="fac-check-item">
            <input type="checkbox" id="chkOther" name="use_other" value="1">
            <span>Lainnya</span>
          </label>
        </div>

        <div class="other-input-wrap" id="otherInputWrap">
          <input type="text" id="otherFacilityInput" name="other_facility" maxlength="100" placeholder="Tuliskan fasilitas / bagian kamar yang bermasalah">
        </div>
      </div>

      <label class="field">Rincian kendala
        <textarea name="description" placeholder="Jelaskan detail kerusakan yang Anda alami..." required></textarea>
      </label>
    </div>

    <!-- Kanan: Upload Foto Bukti -->
    <div class="tk-right-col">
      <span class="field-lbl">Foto bukti <small>(opsional, maks. 4 foto)</small></span>
      <label class="upload-zone" for="photoInput">
        <input id="photoInput" type="file" name="photos[]" accept="image/*" multiple>
        <div class="upload-zone-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
            <polyline points="17 8 12 3 7 8"></polyline>
            <line x1="12" y1="3" x2="12" y2="15"></line>
          </svg>
        </div>
        <strong id="uploadLabel">Pilih foto bukti kerusakan</strong>
        <span>Klik untuk memilih gambar JPG, PNG, atau WEBP (maks. 4 foto)</span>
      </label>
      <div class="preview-list" id="previewList"></div>
    </div>
  </div>

  <div class="tk-form-footer">
    <button class="btn" type="submit">Kirim Tiket Pengaduan</button>
  </div>
</form>

<!-- Riwayat Pengaduan Saya -->
<div class="tk-card" style="background:transparent;border:0;padding:0">
  <div class="tk-history-head">
    <div>
      <h3>Pengaduan Saya</h3>
      <p class="muted small">Pantau status dan progres penanganan setiap tiket Anda.</p>
    </div>
    <span class="badge badge-gray"><?= count($tickets) ?> tiket</span>
  </div>

  <?php if ($tickets): ?>
    <div class="ticket-list">
      <?php foreach ($tickets as $t):
          $parsed = parseTicketSubject($t['subject']);
          $ph = jsonList($t['ticket_proof_path']);
          $photoUrls = [];
          foreach ($ph as $i => $_) {
              $photoUrls[] = url('file.php?t=ticket&id=' . $t['id_ticket'] . '&i=' . $i);
          }
          $modalTitle = $parsed['title'] !== '-' ? $parsed['title'] : implode(', ', $parsed['facs']);
          $progRows = $progressByTicket[(int)$t['id_ticket']] ?? [];
          $progData = [];
          foreach ($progRows as $p) {
              $progData[] = [
                  'catatan' => $p['catatan'],
                  'at'      => tgl($p['created_at']) . ' ' . date('H:i', strtotime($p['created_at'])),
                  'by'      => $p['nama_lengkap'] ?: 'Admin',
                  'role'    => $p['role'] === 'SUPERADMIN' ? 'Superadmin' : 'Admin',
              ];
          }
          $latest = $progData ? $progData[count($progData) - 1] : null;
          $displayTitle = $parsed['title'] !== '-' ? $parsed['title'] : 'Kendala fasilitas';
      ?>
        <article class="ticket-card st-<?= e($t['status']) ?>">
          <div class="ticket-top">
            <div>
              <span class="ticket-id">#T-<?= str_pad((string)$t['id_ticket'], 3, '0', STR_PAD_LEFT) ?></span>
              <span class="ticket-date"><?= e(tgl($t['created_at'])) ?></span>
            </div>
            <?= statusBadge('ticket', $t['status']) ?>
          </div>

          <h4 class="ticket-title"><?= e($displayTitle) ?></h4>

          <?php if ($parsed['facs']): ?>
            <div class="fac-tags">
              <?php foreach ($parsed['facs'] as $fc): ?>
                <span class="fac-pill"><?= e($fc) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <p class="ticket-desc"><?= nl2br(e($t['description'])) ?></p>

          <?php if ($latest): ?>
            <div class="ticket-latest">
              <span class="ticket-latest-dot"></span>
              <div style="min-width:0">
                <strong>Progres terbaru <time><?= e($latest['at']) ?></time></strong>
                <p><?= e($latest['catatan']) ?></p>
              </div>
            </div>
          <?php endif; ?>

          <div class="ticket-foot">
            <?php if ($photoUrls): ?>
              <button type="button"
                      class="btn btn-outline btn-sm"
                      data-title="<?= e($modalTitle) ?>"
                      data-photos="<?= e(json_encode($photoUrls)) ?>"
                      onclick="openProofModal(this)">
                Lihat Bukti (<?= count($photoUrls) ?>)
              </button>
            <?php endif; ?>
            <button type="button"
                    class="btn btn-outline btn-sm"
                    data-label="#T-<?= str_pad((string)$t['id_ticket'], 3, '0', STR_PAD_LEFT) ?> · <?= e($modalTitle) ?>"
                    data-progress="<?= e(json_encode($progData)) ?>"
                    onclick="openProgressModal(this)">
              Riwayat Progres (<?= count($progData) ?>)
            </button>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="tk-card tk-empty">
      <p>Belum ada pengaduan yang dikirim.</p>
      <span class="muted small">Kendala yang Anda laporkan akan muncul di sini.</span>
    </div>
  <?php endif; ?>
</div>

<!-- Modal Lihat Foto Bukti -->
<div class="proof-modal" id="proofModal" onclick="if(event.target===this) closeProofModal()">
  <div class="proof-modal-box">
    <div class="proof-modal-head">
      <h4 id="proofModalTitle">Foto Bukti Pengaduan</h4>
      <button type="button" class="btn btn-outline btn-sm" onclick="closeProofModal()">Tutup</button>
    </div>
    <div class="proof-modal-grid" id="proofModalGrid"></div>
  </div>
</div>

<!-- Modal Riwayat Progres Tiket -->
<div class="proof-modal" id="progressModal" onclick="if(event.target===this) closeProgressModal()">
  <div class="proof-modal-box" style="max-width:620px">
    <div class="proof-modal-head">
      <h4 id="progressModalTitle">Progres Tiket</h4>
      <button type="button" class="btn btn-outline btn-sm" onclick="closeProgressModal()">Tutup</button>
    </div>
    <div class="progress-timeline" id="progressTimeline"></div>
  </div>
</div>

<script>
  const chkOther = document.getElementById('chkOther');
  const otherWrap = document.getElementById('otherInputWrap');
  const otherInput = document.getElementById('otherFacilityInput');

  if (chkOther) {
    chkOther.addEventListener('change', function () {
      if (this.checked) {
        otherWrap.classList.add('is-visible');
        otherInput.required = true;
        otherInput.focus();
      } else {
        otherWrap.classList.remove('is-visible');
        otherInput.required = false;
        otherInput.value = '';
      }
    });
  }

  const photoInput = document.getElementById('photoInput');
  const previewList = document.getElementById('previewList');
  const uploadLabel = document.getElementById('uploadLabel');

  if (photoInput) {
    photoInput.addEventListener('change', function () {
      previewList.innerHTML = '';
      const files = Array.from(this.files || []).slice(0, 4);
      uploadLabel.textContent = files.length ? files.length + ' foto dipilih' : 'Pilih foto bukti kerusakan';
      files.forEach(file => {
        const reader = new FileReader();
        reader.onload = e => {
          const img = document.createElement('img');
          img.src = e.target.result;
          previewList.appendChild(img);
        };
        reader.readAsDataURL(file);
      });
    });
  }

  function openProofModal(btn) {
    const title = btn.getAttribute('data-title') || 'Foto Bukti';
    const photos = JSON.parse(btn.getAttribute('data-photos') || '[]');
    const grid = document.getElementById('proofModalGrid');
    document.getElementById('proofModalTitle').textContent = 'Bukti: ' + title;
    grid.innerHTML = '';

    photos.forEach(src => {
      const a = document.createElement('a');
      a.href = src;
      a.target = '_blank';
      a.title = 'Klik untuk memperbesar';
      const img = document.createElement('img');
      img.src = src;
      img.alt = 'Foto Bukti';
      a.appendChild(img);
      grid.appendChild(a);
    });

    document.getElementById('proofModal').classList.add('is-open');
  }

  function closeProofModal() {
    document.getElementById('proofModal').classList.remove('is-open');
  }

  function openProgressModal(btn) {
    const label = btn.getAttribute('data-label') || 'Tiket';
    const items = JSON.parse(btn.getAttribute('data-progress') || '[]');

    document.getElementById('progressModalTitle').textContent = 'Progres ' + label;

    const tl = document.getElementById('progressTimeline');
    tl.innerHTML = '';

    if (!items.length) {
      const empty = document.createElement('div');
      empty.className = 'progress-empty';
      empty.textContent = 'Belum ada catatan progres dari admin.';
      tl.appendChild(empty);
    } else {
      items.forEach(it => {
        const item = document.createElement('div');
        item.className = 'progress-item';

        const meta = document.createElement('div');
        meta.className = 'progress-meta';
        meta.textContent = it.at + ' · ' + it.by + ' (' + it.role + ')';

        const note = document.createElement('div');
        note.className = 'progress-note';
        note.textContent = it.catatan;

        item.appendChild(meta);
        item.appendChild(note);
        tl.appendChild(item);
      });
    }

    document.getElementById('progressModal').classList.add('is-open');
  }

  function closeProgressModal() {
    document.getElementById('progressModal').classList.remove('is-open');
  }
</script>

<?php app_end(); ?>