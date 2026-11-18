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

  /* Tabel Riwayat Pengaduan Full-Width */
  .tbl-wrap { width: 100%; overflow-x: auto; }
  .clean-tbl { width: 100%; border-collapse: collapse; text-align: left; }
  .clean-tbl thead th {
    background: #F4F6F2;
    color: #4A584B;
    font-size: .8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: .9rem 1.1rem;
    border-bottom: 1.5px solid var(--line);
    text-align: left;
    white-space: nowrap;
  }
  .clean-tbl thead th:first-child { border-top-left-radius: 12px; }
  .clean-tbl thead th:last-child { border-top-right-radius: 12px; }
  .clean-tbl tbody td {
    padding: 1rem 1.1rem;
    border-bottom: 1px solid var(--line);
    vertical-align: middle;
    font-size: .92rem;
    color: var(--ink);
  }
  .clean-tbl tbody tr:last-child td { border-bottom: 0; }
  .clean-tbl tbody tr:hover td { background: #FAFBF9; }

  .col-date { width: 165px; white-space: nowrap; color: var(--muted); font-size: .86rem; }
  .col-title { width: 20%; font-weight: 700; }
  .col-fac { width: 20%; }
  .fac-tags { display: flex; flex-wrap: wrap; gap: .35rem; }
  .fac-pill {
    display: inline-block;
    padding: .22rem .65rem;
    border-radius: 999px;
    font-size: .78rem;
    font-weight: 600;
    background: #EEF4ED;
    color: #3F5141;
  }
  .col-desc { width: auto; color: #4A584B; line-height: 1.5; }
  .col-proof { width: 115px; text-align: center; white-space: nowrap; }
  .col-status { width: 125px; text-align: right; white-space: nowrap; }

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

  .tk-empty { text-align: center; padding: 2.5rem 1rem; color: var(--muted); }
  .tk-empty p { margin: 0; font-size: .94rem; font-weight: 500; }

  @media (max-width: 900px) {
    .tk-card { padding: 1.35rem; }
    .tk-form-grid { grid-template-columns: 1fr; }
    .upload-zone { min-height: 160px; }
    .tk-form-footer .btn { width: 100%; }
  }
</style>

<!-- Card 1: Form Buat Tiket Baru -->
<form class="tk-card" id="form" method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="tk-card-head">
    <h3>Buat Tiket Baru</h3>
  </div>

  <div class="tk-form-grid">
    <!-- Kiri: Judul Kendala -> Fasilitas -> Rincian -->
    <div class="tk-left-col">
      <label class="field">Judul kendala
        <input type="text" name="subject" maxlength="120" placeholder="Contoh: AC tidak dingin atau keran bocor" required>
      </label>

      <div>
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

<!-- Card 2: Riwayat Pengaduan Saya -->
<div class="tk-card">
  <div class="tk-card-head">
    <h3>Pengaduan Saya</h3>
    <span class="badge badge-gray"><?= count($tickets) ?> tiket</span>
  </div>

  <?php if ($tickets): ?>
    <div class="tbl-wrap">
      <table class="clean-tbl">
        <thead>
          <tr>
            <th class="col-date">Tanggal</th>
            <th class="col-title">Judul</th>
            <th class="col-fac">Fasilitas</th>
            <th class="col-desc">Rincian Kendala</th>
            <th class="col-proof" style="text-align:center">Bukti</th>
            <th class="col-status" style="text-align:right">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tickets as $t):
              $parsed = parseTicketSubject($t['subject']);
              $ph = jsonList($t['ticket_proof_path']);
              $photoUrls = [];
              foreach ($ph as $i => $_) {
                  $photoUrls[] = url('file.php?t=ticket&id=' . $t['id_ticket'] . '&i=' . $i);
              }
              $modalTitle = $parsed['title'] !== '-' ? $parsed['title'] : implode(', ', $parsed['facs']);
          ?>
            <tr>
              <td class="col-date"><?= e(tgl($t['created_at'])) ?></td>
              <td class="col-title"><?= e($parsed['title']) ?></td>
              <td class="col-fac">
                <?php if ($parsed['facs']): ?>
                  <div class="fac-tags">
                    <?php foreach ($parsed['facs'] as $fc): ?>
                      <span class="fac-pill"><?= e($fc) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <span class="muted small">-</span>
                <?php endif; ?>
              </td>
              <td class="col-desc"><?= nl2br(e($t['description'])) ?></td>
              <td class="col-proof">
                <?php if ($photoUrls): ?>
                  <button type="button"
                          class="btn btn-outline btn-sm"
                          data-title="<?= e($modalTitle) ?>"
                          data-photos="<?= e(json_encode($photoUrls)) ?>"
                          onclick="openProofModal(this)">
                    Lihat (<?= count($photoUrls) ?>)
                  </button>
                <?php else: ?>
                  <span class="muted small">-</span>
                <?php endif; ?>
              </td>
              <td class="col-status"><?= statusBadge('ticket', $t['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="tk-empty">
      <p>Belum ada pengaduan yang dikirim.</p>
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
</script>

<?php app_end(); ?>