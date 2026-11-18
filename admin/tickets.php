<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/layout.php';
$u = checkRole(['ADMIN']);
$db = db();
$ids = adminKosanIds();
$arr = pgArr($ids);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tid = (int)($_POST['id_ticket'] ?? 0);
    $st = $_POST['status'] ?? '';
    if (in_array($st, ['OPEN', 'IN_PROGRESS', 'RESOLVED'], true)) {
        $q = $db->prepare('UPDATE tickets SET status=CAST(? AS ticket_status_enum) WHERE id_ticket=? AND id_kosan=ANY(CAST(? AS int[]))');
        $q->execute([$st, $tid, $arr]);
        if ($q->rowCount()) {
            flash('success', 'Status tiket berhasil diperbarui.');
        }
    }
    if (($_POST['back'] ?? '') === 'dashboard') {
        redirect('admin/dashboard.php');
    }
    redirect('admin/tickets.php#t' . $tid);
}

$s = $db->prepare("SELECT t.*, u.nama_lengkap, r.nomor_kamar, k.nama_kos
    FROM tickets t
    JOIN users u USING(id_user)
    LEFT JOIN rooms r ON r.id_room = u.id_room
    LEFT JOIN kosan k ON k.id_kosan = t.id_kosan
    WHERE t.id_kosan=ANY(CAST(? AS int[]))
    ORDER BY CASE t.status WHEN 'OPEN' THEN 1 WHEN 'IN_PROGRESS' THEN 2 ELSE 3 END, t.id_ticket DESC");
$s->execute([$arr]);
$tickets = $s->fetchAll();

$cntOpen = 0;
$cntProg = 0;
$cntRes  = 0;
foreach ($tickets as $tk) {
    if ($tk['status'] === 'OPEN') $cntOpen++;
    elseif ($tk['status'] === 'IN_PROGRESS') $cntProg++;
    else $cntRes++;
}

function parseAdminTicketSubject(string $raw): array
{
    if (preg_match('/^\[FAC:(.*?)\]\s*(.*)$/s', $raw, $m)) {
        $facRaw = trim($m[1]);
        $title = trim($m[2]);
        $facs = ($facRaw === '' || $facRaw === '-') ? [] : array_map('trim', explode(',', $facRaw));
        return [
            'title' => ($title !== '' && $title !== '-') ? $title : '',
            'facs'  => $facs,
        ];
    }
    $facs = trim($raw) !== '' ? array_map('trim', explode(',', $raw)) : [];
    return [
        'title' => '',
        'facs'  => $facs,
    ];
}

app_start('ADMIN', 'tickets', 'Tiket Pengaduan', 'Pantau dan perbarui progres laporan kendala fasilitas.');
?>

<style>
  .tk-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1.15rem;
  }
  .tk-tabs {
    display: inline-flex;
    background: #fff;
    border: 1px solid var(--line);
    padding: .28rem;
    border-radius: 13px;
    gap: .2rem;
    flex-wrap: wrap;
  }
  .tk-tabs button {
    border: 0;
    background: transparent;
    padding: .42rem .95rem;
    border-radius: 9px;
    font: 600 .83rem Inter, sans-serif;
    color: var(--muted);
    cursor: pointer;
    transition: all .15s ease;
  }
  .tk-tabs button:hover { color: var(--ink); }
  .tk-tabs button.active {
    background: var(--soft);
    color: var(--teal-d);
  }

  .tk-table-card {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 20px;
    padding: 1.5rem 1.75rem;
  }
  .tbl-wrap { width: 100%; overflow-x: auto; }
  .clean-tbl { width: 100%; border-collapse: collapse; text-align: left; }
  .clean-tbl thead th {
    background: #F4F6F2;
    color: #4A584B;
    font-size: .78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: .85rem 1rem;
    border-bottom: 1.5px solid var(--line);
    white-space: nowrap;
  }
  .clean-tbl thead th:first-child { border-top-left-radius: 12px; }
  .clean-tbl thead th:last-child { border-top-right-radius: 12px; }

  .clean-tbl tbody tr { transition: background .12s ease; }
  .clean-tbl tbody tr:hover td { background: #FAFBF9; }
  .clean-tbl tbody td {
    padding: .95rem 1rem;
    border-bottom: 1px solid var(--line);
    vertical-align: middle;
    font-size: .9rem;
    color: var(--ink);
  }
  .clean-tbl tbody tr:last-child td { border-bottom: 0; }

  /* Aksen status tipis di sisi kiri baris */
  .clean-tbl tbody tr.st-OPEN td:first-child { box-shadow: inset 3.5px 0 0 #ef4444; }
  .clean-tbl tbody tr.st-IN_PROGRESS td:first-child { box-shadow: inset 3.5px 0 0 #f59e0b; }
  .clean-tbl tbody tr.st-RESOLVED td:first-child { box-shadow: inset 3.5px 0 0 var(--teal); }

  .tk-id { font-weight: 700; font-size: .82rem; color: var(--ink); display: block; }
  .tk-date { font-size: .78rem; color: var(--muted); white-space: nowrap; }
  .tk-user { font-weight: 700; display: block; }
  .tk-room { font-size: .8rem; color: var(--muted); }

  .tk-subj { font-weight: 700; color: var(--ink); display: block; margin-bottom: .3rem; }
  .tk-facs { display: flex; flex-wrap: wrap; gap: .3rem; }
  .tk-pill {
    background: #EEF4ED;
    color: #3F5141;
    font-size: .75rem;
    font-weight: 600;
    padding: .16rem .58rem;
    border-radius: 999px;
  }

  .tk-desc { color: #4A584B; line-height: 1.48; max-width: 320px; }

  .tk-act-form {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    justify-content: flex-end;
  }
  .tk-act-form select {
    padding: .4rem .6rem;
    font-size: .83rem;
    background: #FAF9F5;
    border-radius: 10px;
    min-width: 138px;
  }
  .tk-act-form .btn { padding: .42rem .8rem; white-space: nowrap; }

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
</style>

<div class="grid g3" style="margin-bottom:1.25rem">
  <div class="stat">
    <span>Tiket terbuka (OPEN)</span>
    <b><?= $cntOpen ?></b>
    <div class="small muted" style="margin-top:.4rem">Menunggu penanganan awal</div>
  </div>
  <div class="stat">
    <span>Sedang diproses (IN_PROGRESS)</span>
    <b><?= $cntProg ?></b>
    <div class="small muted" style="margin-top:.4rem">Dalam tahap perbaikan / tindak lanjut</div>
  </div>
  <div class="stat">
    <span>Selesai (RESOLVED)</span>
    <b><?= $cntRes ?></b>
    <div class="small muted" style="margin-top:.4rem">Keluhan telah diselesaikan</div>
  </div>
</div>

<div class="tk-toolbar">
  <div class="tk-tabs" id="tkTabs">
    <button type="button" class="active" data-filter="ALL">Semua (<?= count($tickets) ?>)</button>
    <button type="button" data-filter="OPEN">Terbuka (<?= $cntOpen ?>)</button>
    <button type="button" data-filter="IN_PROGRESS">Diproses (<?= $cntProg ?>)</button>
    <button type="button" data-filter="RESOLVED">Selesai (<?= $cntRes ?>)</button>
  </div>
</div>

<div class="tk-table-card">
  <?php if (!$tickets): ?>
    <p class="muted" style="margin:0; text-align:center; padding:1.5rem 0;">Belum ada tiket pengaduan dari penghuni.</p>
  <?php else: ?>
    <div class="tbl-wrap">
      <table class="clean-tbl">
        <thead>
          <tr>
            <th>Tiket &amp; Tanggal</th>
            <th>Penghuni &amp; Kamar</th>
            <th>Kendala &amp; Fasilitas</th>
            <th>Rincian</th>
            <th style="text-align:center">Bukti</th>
            <th>Status</th>
            <th style="text-align:right">Tindakan</th>
          </tr>
        </thead>
        <tbody id="ticketList">
          <?php foreach ($tickets as $t):
            $parsed = parseAdminTicketSubject($t['subject']);
            $ph = jsonList($t['ticket_proof_path']);
            $photoUrls = [];
            foreach ($ph as $i => $_) {
                $photoUrls[] = url('file.php?t=ticket&id=' . $t['id_ticket'] . '&i=' . $i);
            }
            $modalLabel = $parsed['title'] !== '' ? $parsed['title'] : implode(', ', $parsed['facs']);
          ?>
            <tr class="st-<?= e($t['status']) ?>" id="t<?= $t['id_ticket'] ?>" data-status="<?= e($t['status']) ?>">
              <td style="white-space:nowrap">
                <span class="tk-id">#T-<?= str_pad((string)$t['id_ticket'], 3, '0', STR_PAD_LEFT) ?></span>
                <span class="tk-date"><?= e(tgl($t['created_at'])) ?></span>
              </td>

              <td style="white-space:nowrap">
                <span class="tk-user"><?= e($t['nama_lengkap']) ?></span>
                <span class="tk-room">
                  <?= $t['nomor_kamar'] ? 'Kamar ' . e($t['nomor_kamar']) : '-' ?>
                  <?= $t['nama_kos'] ? ' · ' . e($t['nama_kos']) : '' ?>
                </span>
              </td>

              <td>
                <?php if ($parsed['title'] !== ''): ?>
                  <span class="tk-subj"><?= e($parsed['title']) ?></span>
                <?php endif; ?>
                <?php if ($parsed['facs']): ?>
                  <div class="tk-facs">
                    <?php foreach ($parsed['facs'] as $fc): ?>
                      <span class="tk-pill"><?= e($fc) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>

              <td class="tk-desc"><?= nl2br(e($t['description'])) ?></td>

              <td style="text-align:center; white-space:nowrap">
                <?php if ($photoUrls): ?>
                  <button type="button"
                          class="btn btn-outline btn-sm"
                          data-title="<?= e($modalLabel) ?>"
                          data-photos="<?= e(json_encode($photoUrls)) ?>"
                          onclick="openProofModal(this)">
                    Lihat (<?= count($photoUrls) ?>)
                  </button>
                <?php else: ?>
                  <span class="muted small">-</span>
                <?php endif; ?>
              </td>

              <td style="white-space:nowrap"><?= statusBadge('ticket', $t['status']) ?></td>

              <td style="text-align:right; white-space:nowrap">
                <form method="post" class="tk-act-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id_ticket" value="<?= $t['id_ticket'] ?>">
                  <select name="status">
                    <option value="OPEN" <?= $t['status'] === 'OPEN' ? 'selected' : '' ?>>Terbuka</option>
                    <option value="IN_PROGRESS" <?= $t['status'] === 'IN_PROGRESS' ? 'selected' : '' ?>>Diproses</option>
                    <option value="RESOLVED" <?= $t['status'] === 'RESOLVED' ? 'selected' : '' ?>>Selesai</option>
                  </select>
                  <button class="btn btn-sm" type="submit">Simpan</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
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
document.querySelectorAll('#tkTabs button').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#tkTabs button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const filter = btn.dataset.filter;
    document.querySelectorAll('#ticketList tr').forEach(row => {
      row.style.display = (filter === 'ALL' || row.dataset.status === filter) ? '' : 'none';
    });
  });
});

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