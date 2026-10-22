<?php
/*
 * posea_5651_live.php - Canlı Yasal 5651 Log İzleyici
 */

require_once("guiconfig.inc");
require_once("/usr/local/pkg/posea_5651.inc");

$active_file = posea_5651_get_active_file(date("Y-m-d"));

// AJAX fetch for auto-refresh
if (isset($_GET['ajax'])) {
    header("Content-Type: application/json");
    $lines = [];
    if (file_exists($active_file)) {
        // Read tail of file
        $tail = shell_exec("/usr/bin/tail -n 60 " . escapeshellarg($active_file));
        if ($tail) {
            $raw_lines = explode("\n", trim($tail));
            foreach (array_reverse($raw_lines) as $l) {
                if (empty($l)) continue;
                $parts = explode("|", $l);
                if (count($parts) >= 8) {
                    $lines[] = [
                        "ts" => $parts[0] ?? '',
                        "mac" => $parts[1] ?? '',
                        "src" => $parts[2] ?? '',
                        "user" => $parts[3] ?? '',
                        "dst" => $parts[4] ?? '',
                        "nat" => $parts[5] ?? '',
                        "proto" => $parts[6] ?? '',
                        "action" => $parts[7] ?? '',
                        "detail" => $parts[8] ?? ''
                    ];
                }
            }
        }
    }
    echo json_encode(["lines" => $lines, "filesize" => file_exists($active_file) ? filesize($active_file) : 0]);
    exit;
}

$pgtitle = array(gettext("Services"), gettext("5651 Yasal Loglama"), gettext("Canlı Yasal Log İzleyici"));
include("head.inc");

$tab_array = array();
$tab_array[] = array(gettext("Genel Ayarlar"), false, "/pkg_edit.php?xml=posea_5651.xml");
$tab_array[] = array(gettext("İmzalı Günlük Arşivler"), false, "/posea_5651_archives.php");
$tab_array[] = array(gettext("Canlı Yasal Log İzleyici"), true, "/posea_5651_live.php");
$tab_array[] = array(gettext("Zaman Damgası (TSA) Testi"), false, "/posea_5651_test.php");
$tab_array[] = array(gettext("Uzak Yedekleme (SMB/NFS/iSCSI)"), false, "/posea_5651_backup.php");
$tab_array[] = array(gettext("Denetim & Bütünlük Sertifikası"), false, "/posea_5651_integrity.php");
display_top_tabs($tab_array);
?>

<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-default">
            <div class="panel-heading" style="display:flex; justify-content:space-between; align-items:center;">
                <h2 class="panel-title"><i class="fa-solid fa-tower-broadcast"></i> Bugünün Aktif 5651 Yasal Log Akışı (<?=date("Y-m-d")?>)</h2>
                <div>
                    <label style="font-weight:normal; margin-right:15px; cursor:pointer;">
                        <input type="checkbox" id="auto_refresh" checked> Canlı Otomatik Akış (3sn)
                    </label>
                    <button class="btn btn-xs btn-default" onclick="fetchLogs()"><i class="fa-solid fa-arrows-rotate"></i> Şimdi Yenile</button>
                </div>
            </div>
            <div class="panel-body">
                <div style="margin-bottom: 12px; display:flex; gap:10px;">
                    <input type="text" id="filter_box" class="form-control" placeholder="IP, MAC, Kullanıcı Adı veya Alan Adı Filtrele..." onkeyup="applyFilter()" style="max-width: 400px;" />
                    <span id="log_stats" class="text-muted" style="line-height: 34px;">Yükleniyor...</span>
                </div>

                <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                    <table class="table table-striped table-hover table-condensed" style="font-size: 0.85rem;" id="log_table">
                        <thead>
                            <tr>
                                <th style="width: 140px;">Zaman</th>
                                <th style="width: 140px;">Kaynak IP / Port</th>
                                <th style="width: 140px;">MAC Adresi</th>
                                <th style="width: 160px;">AD Kullanıcı</th>
                                <th>Hedef (IP / FQDN)</th>
                                <th style="width: 60px;">Proto</th>
                                <th style="width: 90px;">Eylem</th>
                                <th>Açıklama / Kategori</th>
                            </tr>
                        </thead>
                        <tbody id="log_tbody">
                            <tr><td colspan="8" class="text-center"><em>Loglar yükleniyor...</em></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let allRows = [];

function fetchLogs() {
    fetch('/posea_5651_live.php?ajax=1')
        .then(r => r.json())
        .then(data => {
            allRows = data.lines || [];
            document.getElementById('log_stats').innerText = `Son ${allRows.length} kayıt listeleniyor (Aktif Dosya: ${(data.filesize/1024).toFixed(1)} KB)`;
            renderTable(allRows);
        })
        .catch(e => console.error("Log fetch error", e));
}

function renderTable(rows) {
    const tbody = document.getElementById('log_tbody');
    if (!rows || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center"><em>Bugüne ait henüz log kaydı oluşmadı.</em></td></tr>';
        return;
    }

    let q = (document.getElementById('filter_box').value || '').toLowerCase();
    let html = '';

    rows.forEach(r => {
        let full = `${r.ts} ${r.src} ${r.mac} ${r.user} ${r.dst} ${r.action} ${r.detail}`.toLowerCase();
        if (q && !full.includes(q)) return;

        let badgeClass = 'label-info';
        if (r.action === 'SINKHOLE' || r.action === 'BLOCK' || r.action === 'DROP') badgeClass = 'label-danger';
        else if (r.action === 'PASS' || r.action === 'ALLOW') badgeClass = 'label-success';
        else if (r.action === 'DHCP_ASSIGN') badgeClass = 'label-warning';

        let userBadge = (r.user && r.user !== '-') 
            ? `<span class="label label-primary"><i class="fa-solid fa-user"></i> ${r.user}</span>` 
            : `<span class="text-muted">-</span>`;

        html += `<tr>
            <td><small class="text-muted">${r.ts}</small></td>
            <td><code>${r.src}</code></td>
            <td><small>${r.mac}</small></td>
            <td>${userBadge}</td>
            <td><strong>${r.dst}</strong></td>
            <td><span class="label label-default">${r.proto}</span></td>
            <td><span class="label ${badgeClass}">${r.action}</span></td>
            <td><small>${r.detail}</small></td>
        </tr>`;
    });

    tbody.innerHTML = html || '<tr><td colspan="8" class="text-center text-muted">Aramanızla eşleşen log bulunamadı.</td></tr>';
}

function applyFilter() {
    renderTable(allRows);
}

fetchLogs();
setInterval(() => {
    if (document.getElementById('auto_refresh').checked) {
        fetchLogs();
    }
}, 3000);
</script>

<?php include("foot.inc"); ?>
