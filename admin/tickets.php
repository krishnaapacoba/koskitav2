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
    $action = $_POST['action'] ?? 'status';

    $own = $db->prepare('SELECT 1 FROM tickets WHERE id_ticket=? AND id_kosan=ANY(CAST(? AS int[]))');
    $own->execute([$tid, $arr]);
    $isMine = (bool)$own->fetchColumn();

    if ($isMine) {
        if ($action === 'progress') {
            $catatan = clean($_POST['catatan'] ?? '');
            if ($catatan === '') {
                flash('error', 'Catatan progres wajib diisi.');
            } else {
                $db->prepare('INSERT INTO ticket_progress(id_ticket,id_user,catatan) VALUES (?,?,?)')
                   ->execute([$tid, (int)$u['id_user'], mb_substr($catatan, 0, 2000)]);
                if (in_array($st, ['OPEN', 'IN_PROGRESS', 'RESOLVED'], true)) {
                    $db->prepare('UPDATE tickets SET status=CAST(? AS ticket_status_enum) WHERE id_ticket=?')
                       ->execute([$st, $tid]);
                }
                flash('success', 'Progres tiket berhasil ditambahkan.');
            }
        } elseif (in_array($st, ['OPEN', 'IN_PROGRESS', 'RESOLVED'], true)) {
            $q = $db->prepare('UPDATE tickets SET status=CAST(? AS ticket_status_enum) WHERE id_ticket=?');
            $q->execute([$st, $tid]);
            if ($q->rowCount()) {
                flash('success', 'Status tiket berhasil diperbarui.');
            }
        }
    } else {
        flash('error', 'Tiket tidak ditemukan.');
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
  /* Ringkasan status (dapat diklik untuk memfilter) */
  .adm-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: .9rem;
    margin-bottom: 1.25rem;
  }
  .adm-stat {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: .95rem 1.05rem;
    display: flex;
    align-items: center;
    gap: .8rem;
    cursor: pointer;
    transition: box-shadow .15s ease, border-color .15s ease, transform .15s ease;
  }
  .adm-stat:hover { box-shadow: 0 6px 18px rgba(31, 41, 55, .07); transform: translateY(-1px); }
  .adm-stat.active { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(15, 118, 110, .1); }
  .adm-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }
  .adm-stat b { display: block; font-size: 1.4rem; line-height: 1.1; }
  .adm-stat span { font-size: .79rem; color: var(--muted); }
  .adm-stat-total .adm-stat-icon { background: var(--soft); color: var(--teal); }
  .adm-stat-open .adm-stat-icon { background: #fee2e2; color: #b91c1c; }
  .adm-stat-prog .adm-stat-icon { background: var(--amber-bg); color: var(--amber); }
  .adm-stat-res .adm-stat-icon { background: #dcfce7; color: #15803d; }

  /* Toolbar: pencarian + filter */
  .adm-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1.1rem;
  }
  .adm-search {
    position: relative;
    flex: 1;
    min-width: 240px;
    max-width: 420px;
  }
  .adm-search svg {
    position: absolute;
    left: .85rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    pointer-events: none;
  }
  .adm-search input {
    margin-top: 0;
    padding-left: 2.5rem;
    background: #fff;
    border-radius: 12px;
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

  /* Daftar tiket berbentuk kartu */
  .adm-list { display: flex; flex-direction: column; gap: .85rem; }
  .adm-ticket {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 18px;
    padding: 1.05rem 1.3rem 1.05rem 1.45rem;
    position: relative;
    overflow: hidden;
    transition: box-shadow .18s ease, transform .18s ease;
  }
  .adm-ticket::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 4px;
    background: #cbd5e1;
  }
  .adm-ticket.st-OPEN::before { background: #ef4444; }
  .adm-ticket.st-IN_PROGRESS::before { background: #f59e0b; }
  .adm-ticket.st-RESOLVED::before { background: var(--teal); }
  .adm-ticket:hover { box-shadow: 0 8px 24px rgba(31, 41, 55, .07); transform: translateY(-1px); }

  .adm-ticket-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: .7rem;
  }
  .adm-person { display: flex; align-items: center; gap: .7rem; min-width: 0; }
  .adm-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--soft);
    color: var(--teal-d);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: .8rem;
    flex-shrink: 0;
  }
  .adm-name { font-weight: 700; display: block; font-size: .95rem; }
  .adm-meta { font-size: .78rem; color: var(--muted); display: block; margin-top: .1rem; }
  .adm-meta .dot { margin: 0 .3rem; }

  .adm-title { margin: 0 0 .4rem; font-size: 1.02rem; font-weight: 700; color: var(--ink); }
  .tk-facs { display: flex; flex-wrap: wrap; gap: .3rem; margin-bottom: .45rem; }
  .tk-pill {
    background: #EEF4ED;
    color: #3F5141;
    font-size: .75rem;
    font-weight: 600;
    padding: .18rem .6rem;
    border-radius: 999px;
  }
  .adm-desc { margin: 0; color: #4A584B; line-height: 1.55; font-size: .9rem; }

  .adm-latest {
    display: flex;
    gap: .6rem;
    align-items: flex-start;
    background: #F7FAF8;
    border: 1px dashed #D3E0D4;
    border-radius: 12px;
    padding: .6rem .85rem;
    margin-top: .7rem;
  }
  .adm-latest-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--teal);
    margin-top: .38rem;
    flex-shrink: 0;
  }
  .adm-latest strong { font-size: .77rem; color: var(--teal-d); }
  .adm-latest p {
    margin: .1rem 0 0;
    font-size: .84rem;
    color: #4A584B;
    line-height: 1.45;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .adm-latest time { font-size: .72rem; color: var(--muted); margin-left: .35rem; font-weight: 400; }

  .adm-foot {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: .5rem;
    flex-wrap: wrap;
    margin-top: .85rem;
    padding-top: .8rem;
    border-top: 1px solid #F0EDE5;
  }
  .adm-status-form {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
  }
  .adm-status-form select {
    margin-top: 0;
    padding: .42rem .6rem;
    font-size: .84rem;
    background: #FAF9F5;
    border-radius: 10px;
    min-width: 140px;
    width: auto;
  }
  .adm-status-form .btn { padding: .42rem .85rem; white-space: nowrap; }

  .adm-noresult {
    display: none;
    text-align: center;
    padding: 2.5rem 1rem;
    color: var(--muted);
  }
  .adm-noresult p { margin: 0; font-size: .94rem; font-weight: 500; }

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

  /* Modal Progres Tiket */
  .progress-timeline {
    max-height: 250px;
    overflow-y: auto;
    margin-bottom: 1rem;
    padding-right: .3rem;
  }
  .progress-item {
    border-left: 3px solid var(--teal);
    background: #FAFBF9;
    border-radius: 0 10px 10px 0;
    padding: .6rem .9rem;
    margin-bottom: .55rem;
  }
  .progress-item:last-child { margin-bottom: 0; }
  .progress-meta {
    font-size: .74rem;
    color: var(--muted);
    margin-bottom: .25rem;
  }
  .progress-note {
    font-size: .88rem;
    color: var(--ink);
    line-height: 1.5;
    white-space: pre-wrap;
  }
  .progress-empty {
    color: var(--muted);
    font-size: .86rem;
    text-align: center;
    padding: 1rem 0 1.15rem;
  }
  .progress-form {
    border-top: 1px solid var(--line);
    padding-top: 1rem;
    display: flex;
    flex-direction: column;
    gap: .8rem;
  }
  .progress-form textarea { background: #FAF9F5; min-height: 90px; }
  .progress-form .field { margin: 0; }
  .progress-form-foot { text-align: right; }

  @media (max-width: 900px) {
    .adm-stats { grid-template-columns: repeat(2, 1fr); }
    .adm-search { max-width: none; }
    .adm-foot .btn { flex: 1; }
    .adm-status-form { flex: 1; }
    .adm-status-form select { flex: 1; }
  }
  @media (max-width: 520px) {
    .adm-stats { grid-template-columns: 1fr; }
    .adm-ticket { padding: 1rem 1.1rem 1rem 1.25rem; }
    .adm-foot { justify-content: stretch; }
  }
</style>

<div class="adm-stats">
  <div class="adm-stat adm-stat-total active" data-filter="ALL">
    <div class="adm-stat-icon">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v4a2 2 0 0 0 0 4v4H4v-4a2 2 0 0 0 0-4V4z"></path><path d="M14 4v16"></path></svg>
    </div>
    <div><b><?= count($tickets) ?></b><span>Total tiket</span></div>
  </div>
  <div class="adm-stat adm-stat-open" data-filter="OPEN">
    <div class="adm-stat-icon">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v4"></path><path d="M12 16h.01"></path></svg>
    </div>
    <div><b><?= $cntOpen ?></b><span>Terbuka</span></div>
  </div>
  <div class="adm-stat adm-stat-prog" data-filter="IN_PROGRESS">
    <div class="adm-stat-icon">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>
    </div>
    <div><b><?= $cntProg ?></b><span>Diproses</span></div>
  </div>
  <div class="adm-stat adm-stat-res" data-filter="RESOLVED">
    <div class="adm-stat-icon">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
    </div>
    <div><b><?= $cntRes ?></b><span>Selesai</span></div>
  </div>
</div>

<div class="adm-toolbar">
  <div class="adm-search">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>
    <input type="text" id="ticketSearch" placeholder="Cari nama penghuni, kamar, kos, atau kendala...">
  </div>
  <div class="tk-tabs" id="tkTabs">
    <button type="button" class="active" data-filter="ALL">Semua</button>
    <button type="button" data-filter="OPEN">Terbuka</button>
    <button type="button" data-filter="IN_PROGRESS">Diproses</button>
    <button type="button" data-filter="RESOLVED">Selesai</button>
  </div>
</div>

<div id="ticketList" class="adm-list">
  <?php if (!$tickets): ?>
    <div class="adm-ticket">
      <p class="muted" style="margin:0; text-align:center; padding:1.5rem 0;">Belum ada tiket pengaduan dari penghuni.</p>
    </div>
  <?php else: ?>
    <?php foreach ($tickets as $t):
        $parsed = parseAdminTicketSubject($t['subject']);
        $ph = jsonList($t['ticket_proof_path']);
        $photoUrls = [];
        foreach ($ph as $i => $_) {
            $photoUrls[] = url('file.php?t=ticket&id=' . $t['id_ticket'] . '&i=' . $i);
        }
        $modalLabel = $parsed['title'] !== '' ? $parsed['title'] : implode(', ', $parsed['facs']);
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
        $displayTitle = $parsed['title'] !== '' ? $parsed['title'] : 'Kendala fasilitas';
        $nameParts = preg_split('/\s+/', trim((string)$t['nama_lengkap']));
        $initials = strtoupper(mb_substr($nameParts[0] ?? '', 0, 1));
        if (count($nameParts) > 1) $initials .= strtoupper(mb_substr($nameParts[1], 0, 1));
        $ticketNo = '#T-' . str_pad((string)$t['id_ticket'], 3, '0', STR_PAD_LEFT);
    ?>
      <article class="adm-ticket st-<?= e($t['status']) ?>" id="t<?= $t['id_ticket'] ?>" data-status="<?= e($t['status']) ?>">
        <div class="adm-ticket-head">
          <div class="adm-person">
            <div class="adm-avatar"><?= e($initials) ?></div>
            <div style="min-width:0">
              <span class="adm-name"><?= e($t['nama_lengkap']) ?></span>
              <span class="adm-meta">
                <?= $t['nomor_kamar'] ? 'Kamar ' . e($t['nomor_kamar']) : 'Kamar -' ?>
                <?php if ($t['nama_kos']): ?><span class="dot">·</span><?= e($t['nama_kos']) ?><?php endif; ?>
                <span class="dot">·</span><?= e($ticketNo) ?>
                <span class="dot">·</span><?= e(tgl($t['created_at'])) ?>
              </span>
            </div>
          </div>
          <?= statusBadge('ticket', $t['status']) ?>
        </div>

        <h4 class="adm-title"><?= e($displayTitle) ?></h4>

        <?php if ($parsed['facs']): ?>
          <div class="tk-facs">
            <?php foreach ($parsed['facs'] as $fc): ?>
              <span class="tk-pill"><?= e($fc) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <p class="adm-desc"><?= nl2br(e($t['description'])) ?></p>

        <?php if ($latest): ?>
          <div class="adm-latest">
            <span class="adm-latest-dot"></span>
            <div style="min-width:0">
              <strong>Progres terbaru <time><?= e($latest['at']) ?></time></strong>
              <p><?= e($latest['catatan']) ?></p>
            </div>
          </div>
        <?php endif; ?>

        <div class="adm-foot">
          <?php if ($photoUrls): ?>
            <button type="button"
                    class="btn btn-outline btn-sm"
                    data-title="<?= e($modalLabel) ?>"
                    data-photos="<?= e(json_encode($photoUrls)) ?>"
                    onclick="openProofModal(this)">
              Lihat Bukti (<?= count($photoUrls) ?>)
            </button>
          <?php endif; ?>

          <button type="button"
                  class="btn btn-outline btn-sm"
                  data-ticket="<?= $t['id_ticket'] ?>"
                  data-label="<?= e($ticketNo) ?> · <?= e($modalLabel) ?>"
                  data-status="<?= e($t['status']) ?>"
                  data-progress="<?= e(json_encode($progData)) ?>"
                  onclick="openProgressModal(this)">
            <?= $progData ? 'Progres (' . count($progData) . ')' : 'Tambah Progres' ?>
          </button>

          <form method="post" class="adm-status-form">
            <?= csrf_field() ?>
            <input type="hidden" name="id_ticket" value="<?= $t['id_ticket'] ?>">
            <select name="status" aria-label="Ubah status tiket">
              <option value="OPEN" <?= $t['status'] === 'OPEN' ? 'selected' : '' ?>>Terbuka</option>
              <option value="IN_PROGRESS" <?= $t['status'] === 'IN_PROGRESS' ? 'selected' : '' ?>>Diproses</option>
              <option value="RESOLVED" <?= $t['status'] === 'RESOLVED' ? 'selected' : '' ?>>Selesai</option>
            </select>
            <button class="btn btn-sm" type="submit">Simpan</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
    <div class="adm-noresult" id="admNoResult">
      <p>Tidak ada tiket yang cocok dengan pencarian atau filter.</p>
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

<!-- Modal Progres Tiket -->
<div class="proof-modal" id="progressModal" onclick="if(event.target===this) closeProgressModal()">
  <div class="proof-modal-box" style="max-width:640px">
    <div class="proof-modal-head">
      <h4 id="progressModalTitle">Progres Tiket</h4>
      <button type="button" class="btn btn-outline btn-sm" onclick="closeProgressModal()">Tutup</button>
    </div>
    <div class="progress-timeline" id="progressTimeline"></div>
    <form method="post" class="progress-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="progress">
      <input type="hidden" name="id_ticket" id="progressTicketId">
      <label class="field">Status tiket
        <select name="status" id="progressStatus">
          <option value="OPEN">Terbuka</option>
          <option value="IN_PROGRESS">Diproses</option>
          <option value="RESOLVED">Selesai</option>
        </select>
      </label>
      <label class="field">Catatan progres
        <textarea name="catatan" rows="3" maxlength="2000" placeholder="Tuliskan tindakan / perkembangan penanganan kendala..." required></textarea>
      </label>
      <div class="progress-form-foot">
        <button class="btn" type="submit">Simpan Progres</button>
      </div>
    </form>
  </div>
</div>

<script>
let activeFilter = 'ALL';
const ticketSearch = document.getElementById('ticketSearch');

function applyTicketFilters() {
  const q = (ticketSearch.value || '').toLowerCase().trim();
  let visible = 0;

  document.querySelectorAll('#ticketList .adm-ticket[data-status]').forEach(card => {
    const okStatus = activeFilter === 'ALL' || card.dataset.status === activeFilter;
    const okSearch = !q || card.textContent.toLowerCase().includes(q);
    const show = okStatus && okSearch;
    card.style.display = show ? '' : 'none';
    if (show) visible++;
  });

  const noResult = document.getElementById('admNoResult');
  if (noResult) noResult.style.display = visible ? 'none' : 'block';
}

function setTicketFilter(filter) {
  activeFilter = filter;
  document.querySelectorAll('#tkTabs button').forEach(b => b.classList.toggle('active', b.dataset.filter === filter));
  document.querySelectorAll('.adm-stat').forEach(c => c.classList.toggle('active', c.dataset.filter === filter));
  applyTicketFilters();
}

document.querySelectorAll('#tkTabs button').forEach(btn => {
  btn.addEventListener('click', () => setTicketFilter(btn.dataset.filter));
});

document.querySelectorAll('.adm-stat[data-filter]').forEach(card => {
  card.addEventListener('click', () => setTicketFilter(card.dataset.filter));
});

if (ticketSearch) ticketSearch.addEventListener('input', applyTicketFilters);

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
  const id = btn.getAttribute('data-ticket');
  const label = btn.getAttribute('data-label') || ('#' + id);
  const status = btn.getAttribute('data-status') || 'OPEN';
  const items = JSON.parse(btn.getAttribute('data-progress') || '[]');

  document.getElementById('progressModalTitle').textContent = 'Progres ' + label;
  document.getElementById('progressTicketId').value = id;
  document.getElementById('progressStatus').value = status;

  const tl = document.getElementById('progressTimeline');
  tl.innerHTML = '';

  if (!items.length) {
    const empty = document.createElement('div');
    empty.className = 'progress-empty';
    empty.textContent = 'Belum ada catatan progres untuk tiket ini.';
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