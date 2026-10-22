<?php
/*
 * posea_5651_archives.php - 5651 İmzalı Günlük Arşivler ve Zaman Damgası Yönetimi
 */

require_once("guiconfig.inc");
require_once("/usr/local/pkg/posea_5651.inc");

$cfg = posea_5651_get_config();
$bcfg = posea_5651_get_backup_config();
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
    } elseif ($type === 'zip') {
        $zip_file = posea_5651_create_zip_archive($d);
        if ($zip_file && file_exists($zip_file)) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="5651_' . $d . '.zip"');
            header('Content-Length: ' . filesize($zip_file));
            readfile($zip_file);
            exit;
        }
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
    } elseif ($act === 'sync_remote') {
        $sync_date = $_POST['date'] ?: '';
        if ($sync_date) {
            $res = posea_5651_sync_archive($sync_date);
            $action_msg = $res['message'];
            $archives = posea_5651_get_archives();
        }
    }
}

$pgtitle = array(gettext("Services"), gettext("5651 Yasal Loglama"), gettext("İmzalı Günlük Arşivler"));
include("head.inc");

$tab_array = array();
$tab_array[] = array(gettext("Genel Ayarlar"), false, "/pkg_edit.php?xml=posea_5651.xml");
$tab_array[] = array(gettext("İmzalı Günlük Arşivler"), true, "/posea_5651_archives.php");
$tab_array[] = array(gettext("Canlı Yasal Log İzleyici"), false, "/posea_5651_live.php");
$tab_array[] = array(gettext("Zaman Damgası (TSA) Testi"), false, "/posea_5651_test.php");
$tab_array[] = array(gettext("Uzak Yedekleme (SMB/NFS/iSCSI)"), false, "/posea_5651_backup.php");
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
.stat-box {
    background: #1e293b;
    color: #f8fafc;
    border-radius: 8px;
    padding: 18px 22px;
    margin-bottom: 20px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
    border: 1px solid #334155;
}
.stat-box .val {
    font-size: 26px;
    font-weight: 700;
    line-height: 1.2;
    margin-top: 4px;
}
.stat-box .lbl {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #94a3b8;
}
.stat-box .sub {
    font-size: 12px;
    color: #cbd5e1;
    margin-top: 4px;
}
</style>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title"><i class="fa-solid fa-box-archive" style="margin-right: 8px;"></i>5651 İmzalı Günlük Arşivler ve Zaman Damgası Havuzu</h2>
    </div>
    <div class="panel-body">
        <?php if (!empty($action_msg)): ?>
            <div class="alert alert-info alert-dismissible" role="alert">
                <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                <i class="fa-solid fa-circle-info" style="margin-right: 5px;"></i><?=htmlspecialchars($action_msg)?>
            </div>
        <?php endif; ?>

        <!-- Stats Overview Cards -->
        <div class="row">
            <div class="col-sm-3">
                <div class="stat-box">
                    <div class="lbl">5651 Daemon Durumu</div>
                    <div class="val">
                        <?php if ($daemon_running): ?>
                            <span class="text-success"><i class="fa-solid fa-circle-check"></i> AKTİF (PID: <?=$pid?>)</span>
                        <?php else: ?>
                            <span class="text-danger"><i class="fa-solid fa-circle-xmark"></i> DURDURULDU</span>
                        <?php endif; ?>
                    </div>
                    <div class="sub">Yasal loglama servisi anlık kayıt alıyor</div>
                </div>
            </div>
            <div class="col-sm-3">
                <div class="stat-box">
                    <div class="lbl">Toplam İmzalı Günlük Arşiv</div>
                    <div class="val text-primary"><?=$total_archives?> <span style="font-size: 15px; color: #94a3b8;">Gün</span></div>
                    <div class="sub">RFC 3161 Mühürlü Gün Sayısı</div>
                </div>
            </div>
            <div class="col-sm-3">
                <div class="stat-box">
                    <div class="lbl">Hukuki Geçerlilik Oranı</div>
                    <div class="val text-success"><?=$grant_ratio?>%</div>
                    <div class="sub"><?=$granted_count?> / <?=$total_archives?> Gün Status: Granted</div>
                </div>
            </div>
            <div class="col-sm-3">
                <div class="stat-box">
                    <div class="lbl">Kayıt Altına Alınan Satır</div>
                    <div class="val text-warning"><?=number_format($total_lines)?></div>
                    <div class="sub">Toplam yasal IPDR / NAT / AD akışı</div>
                </div>
            </div>
        </div>

        <!-- Manual Actions Card -->
        <div class="panel panel-default" style="margin-top: 10px;">
            <div class="panel-body" style="background: #1e293b; border: 1px solid #334155; border-radius: 6px; color: #f8fafc;">
                <form action="/posea_5651_archives.php" method="post" class="form-inline" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">
                    <div>
                        <strong style="color: #38bdf8;"><i class="fa-solid fa-stamp"></i> Günlük Logu Kapat ve Zaman Damgası Bas:</strong>
                        <span style="color: #94a3b8; margin-left: 10px;">(Normal şartlarda her gece saat 23:59:50'de otomatik gerçekleşir)</span>
                    </div>
                    <div style="margin-top: 5px;">
                        <input type="date" name="date" class="form-control input-sm" style="background: #0f172a; color: #f8fafc; border: 1px solid #475569;" value="<?=date('Y-m-d')?>" max="<?=date('Y-m-d')?>">
                        <button type="submit" name="act" value="sign_now" class="btn btn-primary btn-sm" style="margin-left: 6px;">
                            <i class="fa-solid fa-fingerprint"></i> Günlük Logu İmzala &amp; Mühürle
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Archives Table -->
        <div class="panel panel-default">
            <div class="panel-heading" style="display:flex; justify-content:space-between; align-items:center;">
                <h2 class="panel-title"><i class="fa-solid fa-box-archive"></i> İmzalı ve Zaman Damgalı Günlük Arşiv Dosyaları</h2>
                <div>
                    <a href="/posea_5651_backup.php" class="btn btn-xs btn-info"><i class="fa-solid fa-cloud-arrow-up"></i> Uzak Yedekleme Ayarları</a>
                    <button class="btn btn-xs btn-default" onclick="location.reload();" style="margin-left: 6px;"><i class="fa-solid fa-arrows-rotate"></i> Yenile</button>
                </div>
            </div>
            <div class="panel-body table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th style="width: 130px;">Tarih</th>
                            <th style="width: 110px;">Satır Sayısı</th>
                            <th style="width: 110px;">Boyut</th>
                            <th>SHA-256 Özeti (Kriptografik Hash)</th>
                            <th style="width: 170px;">Zaman Damgası (TSA)</th>
                            <th style="width: 140px;">Hukuki Durum</th>
                            <th style="width: 290px;" class="text-right">İndir &amp; Uzak Depolama</th>
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
                                $is_synced = !empty($a['remote_synced']);
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
                                        <?php if ($is_synced): ?>
                                            <span class="label label-info" title="Uzak sunucuya (SMB/NAS) aktarıldı"><i class="fa-solid fa-cloud-arrow-up"></i> YEDEKLENDİ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <form action="/posea_5651_archives.php" method="post" style="display:inline;">
                                            <input type="hidden" name="date" value="<?=$d?>">
                                            <input type="hidden" name="act" value="sync_remote">
                                            <button type="submit" class="btn btn-xs btn-warning" title="Uzak Depolamaya (SMB/NAS) Şimdi Aktar">
                                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                            </button>
                                        </form>
                                        <div class="btn-group btn-group-xs" role="group" style="margin-left: 4px;">
                                            <a href="?download=zip&date=<?=$d?>" class="btn btn-success" title="Eksiksiz ZIP Paketini İndir (.log + .sha + .tsr)"><i class="fa-solid fa-file-zipper"></i> ZIP</a>
                                            <a href="?download=log&date=<?=$d?>" class="btn btn-default" title="Ham Log Dosyasını İndir"><i class="fa-solid fa-file-lines"></i> Log</a>
                                            <a href="?download=sha256&date=<?=$d?>" class="btn btn-default" title="SHA-256 Hash Dosyasını İndir"><i class="fa-solid fa-hashtag"></i> SHA</a>
                                            <a href="?download=tsr&date=<?=$d?>" class="btn btn-primary" title="Zaman Damgasını İndir (.tsr)"><i class="fa-solid fa-stamp"></i> TSR</a>
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
