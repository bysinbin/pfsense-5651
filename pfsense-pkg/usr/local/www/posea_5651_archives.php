<?php
/*
 * posea_5651_archives.php - 5651 İmzalı Günlük Arşivler ve Zaman Damgası Yönetimi
 */

require_once("guiconfig.inc");
require_once("/usr/local/pkg/posea_5651.inc");

$cfg = posea_5651_get_config();
$archives = posea_5651_get_archives();

// Handle File Downloads
if (isset($_GET['download']) && !empty($_GET['date'])) {
    $d = preg_replace('/[^0-9\-]/', '', $_GET['date']);
    $type = $_GET['download'];
    $base = POSEA_5651_ARCHIVE_DIR . "/5651_{$d}";

    if ($type === 'log') {
        $target = file_exists("{$base}.log.gz") ? "{$base}.log.gz" : "{$base}.log";
        if (file_exists($target)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($target) . '"');
            header('Content-Length: ' . filesize($target));
            readfile($target);
            exit;
        }
    } elseif ($type === 'sha256' && file_exists("{$base}.sha256")) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="5651_' . $d . '.sha256"');
        readfile("{$base}.sha256");
        exit;
    } elseif ($type === 'tsr' && file_exists("{$base}.tsr")) {
        header('Content-Type: application/timestamp-reply');
        header('Content-Disposition: attachment; filename="5651_' . $d . '.tsr"');
        readfile("{$base}.tsr");
        exit;
    }
}

// Handle Manual Actions
$action_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'sign_now') {
        $sign_date = $_POST['date'] ?: date("Y-m-d");
        $ok = posea_5651_sign_daily_log($sign_date);
        if ($ok) {
            $action_msg = "{$sign_date} tarihli yasal log başarıyla kapatıldı, SHA-256 özeti çıkarıldı ve RFC 3161 Zaman Damgası ile mühürlendi!";
        } else {
            $action_msg = "{$sign_date} gününe ait aktif log bulunamadı veya henüz veri oluşmadı.";
        }
        $archives = posea_5651_get_archives();
    }
}

$pgtitle = array(gettext("Services"), gettext("5651 Yasal Loglama"), gettext("İmzalı Günlük Arşivler"));
include("head.inc");

$tab_array = array();
$tab_array[] = array(gettext("Genel Ayarlar"), false, "/pkg_edit.php?xml=posea_5651.xml");
$tab_array[] = array(gettext("İmzalı Günlük Arşivler"), true, "/posea_5651_archives.php");
$tab_array[] = array(gettext("Canlı Yasal Log İzleyici"), false, "/posea_5651_live.php");
$tab_array[] = array(gettext("Zaman Damgası (TSA) Testi"), false, "/posea_5651_test.php");
display_top_tabs($tab_array);

$total_archives = count($archives);
$total_lines = 0;
$granted_count = 0;
foreach ($archives as $a) {
    $total_lines += intval($a['lines'] ?? 0);
    if (!empty($a['tsr_granted'])) $granted_count++;
}
$grant_ratio = ($total_archives > 0) ? round(($granted_count / $total_archives) * 100) : 100;

$daemon_running = posea_5651_is_running();
$pid = file_exists(POSEA_5651_PID) ? trim(file_get_contents(POSEA_5651_PID)) : '-';
?>

<style>
.legal-card {
    background: #1e293b !important;
    border: 1px solid #334155 !important;
    border-radius: 8px !important;
    padding: 16px 14px !important;
    margin-bottom: 18px !important;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.25) !important;
    text-align: center;
}
.legal-card-title {
    color: #e2e8f0 !important;
    font-size: 0.85rem !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    margin: 0 0 8px 0 !important;
}
.legal-card-value {
    font-size: 2.2rem !important;
    font-weight: 800 !important;
    line-height: 1.1 !important;
    margin: 4px 0 !important;
}
.legal-card-sub {
    font-size: 0.8rem !important;
    color: #94a3b8 !important;
    font-weight: 500 !important;
    margin: 0 !important;
}
</style>

<div class="row">
    <div class="col-sm-12">
        <?php if ($action_msg): ?>
            <div class="alert alert-info alert-dismissible" role="alert">
                <button type="button" class="close" data-dismiss="alert"><span aria-hidden="true">&times;</span></button>
                <i class="fa-solid fa-circle-info"></i> <?=$action_msg?>
            </div>
        <?php endif; ?>

        <!-- Stat Cards -->
        <div class="row">
            <div class="col-md-3 col-sm-6">
                <div class="legal-card">
                    <p class="legal-card-title"><i class="fa-solid fa-calendar-check" style="color: #38bdf8;"></i> Mühürlü Günler</p>
                    <div class="legal-card-value" style="color: #38bdf8 !important;"><?=$total_archives?> Gün</div>
                    <p class="legal-card-sub"><i class="fa-solid fa-circle-check" style="color: #4ade80;"></i> Günlük Arşiv</p>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="legal-card">
                    <p class="legal-card-title"><i class="fa-solid fa-stamp" style="color: #4ade80;"></i> Zaman Damgası Oranı</p>
                    <div class="legal-card-value" style="color: #4ade80 !important;">%<?=$grant_ratio?></div>
                    <p class="legal-card-sub">RFC 3161 Mühür Geçerliliği</p>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="legal-card">
                    <p class="legal-card-title"><i class="fa-solid fa-list-ol" style="color: #fbbf24;"></i> Toplam Kayıt Satırı</p>
                    <div class="legal-card-value" style="color: #fbbf24 !important;"><?=number_format($total_lines)?></div>
                    <p class="legal-card-sub">IPDR / NAT / DHCP Logları</p>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="legal-card">
                    <p class="legal-card-title"><i class="fa-solid fa-shield-halved" style="color: #c084fc;"></i> 5651 Servis Durumu</p>
                    <div class="legal-card-value" style="font-size: 1.6rem !important; color: <?=$daemon_running ? '#4ade80' : '#f87171'?> !important;">
                        <?=$daemon_running ? 'ÇALIŞIYOR' : 'DURDURULDU'?>
                    </div>
                    <p class="legal-card-sub">PID: <?=$pid?> | Saklama: <?=$cfg['retention_days']?> Gün</p>
                </div>
            </div>
        </div>

        <!-- Quick Manual Action -->
        <div class="panel panel-default">
            <div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-bolt"></i> Manuel Zaman Damgalama ve Acil Kapatma</h2></div>
            <div class="panel-body">
                <p class="text-muted" style="margin-bottom: 10px;">
                    Normal şartlarda sistem her gece <strong><?=$cfg['sign_time']?></strong>'da dünün logunu otomatik olarak kapatır ve TÜBİTAK/TSA sunucusuna mühürletir.
                    Acil denetim, test veya anlık yedek alma durumunda aşağıdaki buton ile bugünün aktif logunu anında kapatıp mühürleyebilirsiniz:
                </p>
                <form method="post" style="display:inline-block;" onsubmit="return confirm('Bugünün aktif log dosyasını şimdi kapatıp zaman damgasıyla mühürlemek istediğinize emin misiniz?');">
                    <input type="hidden" name="act" value="sign_now" />
                    <input type="hidden" name="date" value="<?=date('Y-m-d')?>" />
                    <button type="submit" class="btn btn-warning">
                        <i class="fa-solid fa-stamp"></i> Bugünün Logunu Şimdi Mühürle (<?=date('Y-m-d')?>)
                    </button>
                </form>
            </div>
        </div>

        <!-- Archives Table -->
        <div class="panel panel-default">
            <div class="panel-heading" style="display:flex; justify-content:space-between; align-items:center;">
                <h2 class="panel-title"><i class="fa-solid fa-box-archive"></i> İmzalı ve Zaman Damgalı Günlük Arşiv Dosyaları</h2>
                <button class="btn btn-xs btn-default" onclick="location.reload();"><i class="fa-solid fa-arrows-rotate"></i> Yenile</button>
            </div>
            <div class="panel-body table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th style="width: 130px;">Tarih</th>
                            <th style="width: 120px;">Satır Sayısı</th>
                            <th style="width: 120px;">Dosya Boyutu</th>
                            <th>SHA-256 Özeti (Kriptografik Hash)</th>
                            <th style="width: 180px;">Zaman Damgası (TSA)</th>
                            <th style="width: 130px;">Hukuki Durum</th>
                            <th style="width: 220px;" class="text-right">Yasal Dosyaları İndir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($archives)): ?>
                            <tr>
                                <td colspan="7" class="text-center" style="padding: 25px;">
                                    <em>Henüz tamamlanmış günlük arşiv bulunmuyor. Gece yarısı ilk günlük log kapatılıp mühürlendiğinde veya yukarıdaki butona bastığınızda burada listelenecektir.</em>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach (array_reverse($archives) as $d => $a):
                                $is_ok = !empty($a['tsr_granted']);
                                $size_kb = round(($a['size_bytes'] ?? 0) / 1024, 1);
                            ?>
                                <tr>
                                    <td><strong><i class="fa-regular fa-calendar"></i> <?=htmlspecialchars($d)?></strong></td>
                                    <td><span class="badge"><?=number_format($a['lines'] ?? 0)?> satır</span></td>
                                    <td><?=$size_kb?> KB <?=!empty($a['has_gz']) ? '<span class="label label-info">.gz</span>' : ''?></td>
                                    <td>
                                        <code style="font-size:0.8rem;" title="<?=htmlspecialchars($a['sha256'])?>">
                                            <?=htmlspecialchars(substr($a['sha256'], 0, 16))?>...<?=htmlspecialchars(substr($a['sha256'], -8))?>
                                        </code>
                                    </td>
                                    <td>
                                        <small>
                                            <strong>TSA:</strong> <?=htmlspecialchars($a['tsa_time'] ?? '-')?><br/>
                                            <span class="text-muted">Seri: <?=htmlspecialchars(substr($a['tsa_serial'] ?? '-', 0, 14))?></span>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($is_ok): ?>
                                            <span class="label label-success"><i class="fa-solid fa-stamp"></i> MÜHÜRLÜ</span>
                                        <?php else: ?>
                                            <span class="label label-danger"><i class="fa-solid fa-triangle-exclamation"></i> DAMGASIZ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <div class="btn-group btn-group-xs" role="group">
                                            <a href="?download=log&date=<?=$d?>" class="btn btn-default" title="Log Dosyasını İndir"><i class="fa-solid fa-file-lines"></i> Log</a>
                                            <a href="?download=sha256&date=<?=$d?>" class="btn btn-default" title="SHA-256 Hash Dosyasını İndir"><i class="fa-solid fa-hashtag"></i> SHA</a>
                                            <a href="?download=tsr&date=<?=$d?>" class="btn btn-primary" title="TÜBİTAK/RFC3161 Zaman Damgasını İndir (.tsr)"><i class="fa-solid fa-stamp"></i> TSR</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include("foot.inc"); ?>
