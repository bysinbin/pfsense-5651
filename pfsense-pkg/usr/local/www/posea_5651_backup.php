<?php
/*
 * posea_5651_backup.php - 5651 Yasal Arşivler Uzak Sunucu & SMB/NFS/iSCSI Yedekleme
 */

require_once("guiconfig.inc");
require_once("/usr/local/pkg/posea_5651.inc");

$bcfg = posea_5651_get_backup_config();
$save_msg = '';
$save_class = 'success';

// Handle AJAX Requests (Test / Sync)
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $act = $_POST['ajax_action'];

    if ($act === 'test_connection') {
        $test_cfg = [
            'protocol' => $_POST['protocol'] ?? 'smb',
            'smb_server' => trim($_POST['smb_server'] ?? ''),
            'smb_port' => intval($_POST['smb_port'] ?: 445),
            'smb_share' => trim($_POST['smb_share'] ?? ''),
            'smb_dir' => trim($_POST['smb_dir'] ?? ''),
            'smb_domain' => trim($_POST['smb_domain'] ?? ''),
            'smb_user' => trim($_POST['smb_user'] ?? ''),
            'smb_password' => trim($_POST['smb_password'] ?? ''),
            'nfs_server' => trim($_POST['nfs_server'] ?? ''),
            'nfs_export' => trim($_POST['nfs_export'] ?? ''),
            'nfs_version' => $_POST['nfs_version'] ?? '3',
            'local_path' => trim($_POST['local_path'] ?? ''),
            'sftp_server' => trim($_POST['sftp_server'] ?? ''),
            'sftp_port' => intval($_POST['sftp_port'] ?: 22),
            'sftp_user' => trim($_POST['sftp_user'] ?? ''),
            'sftp_password' => trim($_POST['sftp_password'] ?? ''),
            'sftp_path' => trim($_POST['sftp_path'] ?? ''),
            'ftp_server' => trim($_POST['ftp_server'] ?? ''),
            'ftp_port' => intval($_POST['ftp_port'] ?: 21),
            'ftp_user' => trim($_POST['ftp_user'] ?? ''),
            'ftp_password' => trim($_POST['ftp_password'] ?? ''),
            'ftp_path' => trim($_POST['ftp_path'] ?? '')
        ];
        $res = posea_5651_test_backup_connection($test_cfg);
        echo json_encode($res);
        exit;
    }

    if ($act === 'sync_all') {
        $res = posea_5651_sync_all_archives();
        echo json_encode($res);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
    exit;
}

// Handle Form Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $bcfg['enabled'] = !empty($_POST['enabled']);
    $bcfg['protocol'] = $_POST['protocol'] ?? 'smb';
    $bcfg['auto_sync'] = !empty($_POST['auto_sync']);
    $bcfg['retention_days'] = intval($_POST['retention_days'] ?? 0);

    // SMB
    $bcfg['smb_server'] = trim($_POST['smb_server'] ?? '');
    $bcfg['smb_port'] = intval($_POST['smb_port'] ?: 445);
    $bcfg['smb_share'] = trim($_POST['smb_share'] ?? '');
    $bcfg['smb_dir'] = trim($_POST['smb_dir'] ?? '');
    $bcfg['smb_domain'] = trim($_POST['smb_domain'] ?? '');
    $bcfg['smb_user'] = trim($_POST['smb_user'] ?? '');
    if (!empty($_POST['smb_password'])) {
        $bcfg['smb_password'] = $_POST['smb_password'];
    }

    // NFS
    $bcfg['nfs_server'] = trim($_POST['nfs_server'] ?? '');
    $bcfg['nfs_export'] = trim($_POST['nfs_export'] ?? '');
    $bcfg['nfs_version'] = $_POST['nfs_version'] ?? '3';

    // Local / iSCSI
    $bcfg['local_path'] = trim($_POST['local_path'] ?? '');

    // SFTP
    $bcfg['sftp_server'] = trim($_POST['sftp_server'] ?? '');
    $bcfg['sftp_port'] = intval($_POST['sftp_port'] ?: 22);
    $bcfg['sftp_user'] = trim($_POST['sftp_user'] ?? '');
    if (!empty($_POST['sftp_password'])) {
        $bcfg['sftp_password'] = $_POST['sftp_password'];
    }
    $bcfg['sftp_path'] = trim($_POST['sftp_path'] ?? '');

    // FTP
    $bcfg['ftp_server'] = trim($_POST['ftp_server'] ?? '');
    $bcfg['ftp_port'] = intval($_POST['ftp_port'] ?: 21);
    $bcfg['ftp_user'] = trim($_POST['ftp_user'] ?? '');
    if (!empty($_POST['ftp_password'])) {
        $bcfg['ftp_password'] = $_POST['ftp_password'];
    }
    $bcfg['ftp_path'] = trim($_POST['ftp_path'] ?? '');

    posea_5651_save_backup_config($bcfg);
    $save_msg = "Uzak yedekleme ayarları başarıyla kaydedildi.";
}

$pgtitle = array(gettext("Services"), gettext("5651 Yasal Loglama"), gettext("Uzak Yedekleme"));
include("head.inc");

$tab_array = array();
$tab_array[] = array(gettext("Genel Ayarlar"), false, "/pkg_edit.php?xml=posea_5651.xml");
$tab_array[] = array(gettext("İmzalı Günlük Arşivler"), false, "/posea_5651_archives.php");
$tab_array[] = array(gettext("Canlı Yasal Log İzleyici"), false, "/posea_5651_live.php");
$tab_array[] = array(gettext("Zaman Damgası (TSA) Testi"), false, "/posea_5651_test.php");
$tab_array[] = array(gettext("Uzak Yedekleme (SMB/NFS/iSCSI)"), true, "/posea_5651_backup.php");
display_top_tabs($tab_array);

$archives = posea_5651_get_archives();
$archive_count = count($archives);
$synced_count = 0;
foreach ($archives as $a) {
    if (!empty($a['remote_synced'])) $synced_count++;
}
?>

<style>
.posea-stat-card {
    background: #1e293b;
    color: #f8fafc;
    border-radius: 8px;
    padding: 18px 22px;
    margin-bottom: 20px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
    border: 1px solid #334155;
}
.posea-stat-val {
    font-size: 26px;
    font-weight: 700;
    line-height: 1.2;
    margin-top: 4px;
}
.posea-stat-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #94a3b8;
}
.proto-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 20px;
    margin-top: 15px;
}
</style>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title"><i class="fa-solid fa-cloud-arrow-up" style="margin-right: 8px;"></i>5651 Günlük İmzalı Arşivler Uzak Yedekleme (SMB / NFS / iSCSI / SFTP)</h2>
    </div>
    <div class="panel-body">
        <?php if (!empty($save_msg)): ?>
            <div class="alert alert-<?=$save_class?> alert-dismissible" role="alert">
                <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                <i class="fa-solid fa-check-circle" style="margin-right: 5px;"></i><?=$save_msg?>
            </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="row">
            <div class="col-sm-3">
                <div class="posea-stat-card">
                    <div class="posea-stat-label">Yedekleme Durumu</div>
                    <div class="posea-stat-val">
                        <?php if (!empty($bcfg['enabled'])): ?>
                            <span class="text-success"><i class="fa-solid fa-circle-check"></i> AKTİF</span>
                        <?php else: ?>
                            <span class="text-muted"><i class="fa-solid fa-circle-pause"></i> DEVRE DIŞI</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-sm-3">
                <div class="posea-stat-card">
                    <div class="posea-stat-label">Aktarım Protokolü</div>
                    <div class="posea-stat-val text-info">
                        <?=strtoupper($bcfg['protocol'] ?? 'SMB')?>
                    </div>
                </div>
            </div>
            <div class="col-sm-3">
                <div class="posea-stat-card">
                    <div class="posea-stat-label">Arşiv Senkronizasyonu</div>
                    <div class="posea-stat-val text-warning">
                        <?=$synced_count?> / <?=$archive_count?> <span style="font-size: 14px; color: #94a3b8;">Paket</span>
                    </div>
                </div>
            </div>
            <div class="col-sm-3">
                <div class="posea-stat-card">
                    <div class="posea-stat-label">Son Başarılı Aktarım</div>
                    <div class="posea-stat-val" style="font-size: 16px; margin-top: 10px; color: #38bdf8;">
                        <?=!empty($bcfg['last_sync_time']) ? htmlspecialchars($bcfg['last_sync_time']) : 'Henüz yapılmadı'?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Live Ajax Alert Result -->
        <div id="ajax-alert" style="display: none;" class="alert" role="alert"></div>

        <form action="/posea_5651_backup.php" method="post" id="backup-form" class="form-horizontal">
            <!-- Genel Yapılandırma -->
            <div class="form-group">
                <label class="col-sm-2 control-label">Uzak Yedekleme</label>
                <div class="col-sm-10">
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?=!empty($bcfg['enabled']) ? 'checked' : ''?>>
                            <strong>Uzak Sunucuya Otomatik Arşiv Aktarımını Etkinleştir</strong>
                        </label>
                    </div>
                    <span class="help-block">5651 sayılı kanun gereği günlük mühürlenen zaman damgalı arşiv paketleri pfSense diski dışında harici bir depolama ünitesine aktarılır.</span>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-2 control-label">Otomatik Zamanlama</label>
                <div class="col-sm-10">
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="auto_sync" value="1" <?=!empty($bcfg['auto_sync']) ? 'checked' : ''?>>
                            <strong>Her gece zaman damgası basılır basılmaz (23:59:50) otomatik aktar</strong>
                        </label>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-2 control-label">Yerel Saklama Politikası</label>
                <div class="col-sm-4">
                    <select name="retention_days" class="form-control">
                        <option value="0" <?=$bcfg['retention_days'] == 0 ? 'selected' : ''?>>Süresiz Sakla (Disk dolana kadar silme)</option>
                        <option value="30" <?=$bcfg['retention_days'] == 30 ? 'selected' : ''?>>30 Günden Eski Yerel Kopyaları Temizle</option>
                        <option value="60" <?=$bcfg['retention_days'] == 60 ? 'selected' : ''?>>60 Günden Eski Yerel Kopyaları Temizle</option>
                        <option value="90" <?=$bcfg['retention_days'] == 90 ? 'selected' : ''?>>90 Günden Eski Yerel Kopyaları Temizle</option>
                        <option value="180" <?=$bcfg['retention_days'] == 180 ? 'selected' : ''?>>180 Günden Eski Yerel Kopyaları Temizle</option>
                        <option value="365" <?=$bcfg['retention_days'] == 365 ? 'selected' : ''?>>1 Yıldan Eski Yerel Kopyaları Temizle</option>
                    </select>
                    <span class="help-block">Uzak sunucuya başarılı aktarılan arşivlerin pfSense diskinde ne kadar tutulacağını belirler.</span>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-2 control-label">Aktarım Protokolü</label>
                <div class="col-sm-4">
                    <select name="protocol" id="protocol-select" class="form-control">
                        <option value="smb" <?=$bcfg['protocol'] === 'smb' ? 'selected' : ''?>>SMB / CIFS (Windows Dosya Paylaşımı, Samba, QNAP, Synology)</option>
                        <option value="nfs" <?=$bcfg['protocol'] === 'nfs' ? 'selected' : ''?>>NFS Paylaşımı (Linux, TrueNAS, Synology NFS)</option>
                        <option value="local" <?=$bcfg['protocol'] === 'local' ? 'selected' : ''?>>Yerel Dizin / iSCSI / Harici Mount Noktası</option>
                        <option value="sftp" <?=$bcfg['protocol'] === 'sftp' ? 'selected' : ''?>>SFTP / SSH (Güvenli Uzak Dosya Sunucusu)</option>
                        <option value="ftp" <?=$bcfg['protocol'] === 'ftp' ? 'selected' : ''?>>FTP / FTPS (Standart Dosya Sunucusu)</option>
                    </select>
                </div>
            </div>

            <!-- 1. SMB Form Fields -->
            <div id="proto-smb" class="proto-box">
                <h4><i class="fa-solid fa-network-wired text-primary"></i> SMB2 / SMB3 Windows Paylaşımı ve NAS Ayarları</h4>
                <p class="text-muted">pfSense çekirdeğinde çalışan yerel Go SMB2/3 motoru ile Windows Server 2016/2019/2022/11 veya NAS paylaşımına doğrudan yükler.</p>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Sunucu IP / Host *</label>
                            <div class="col-sm-8">
                                <input type="text" name="smb_server" class="form-control" value="<?=htmlspecialchars($bcfg['smb_server'])?>" placeholder="Örn: 192.168.2.222">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Port</label>
                            <div class="col-sm-8">
                                <input type="number" name="smb_port" class="form-control" value="<?=$bcfg['smb_port'] ?: 445?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Paylaşım Adı (Share) *</label>
                            <div class="col-sm-8">
                                <input type="text" name="smb_share" class="form-control" value="<?=htmlspecialchars($bcfg['smb_share'])?>" placeholder="Örn: 5651_Loglar">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Alt Dizin (Opsiyonel)</label>
                            <div class="col-sm-8">
                                <input type="text" name="smb_dir" class="form-control" value="<?=htmlspecialchars($bcfg['smb_dir'])?>" placeholder="Örn: pfSense_Arsivler">
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Domain / Çalışma Grubu</label>
                            <div class="col-sm-8">
                                <input type="text" name="smb_domain" class="form-control" value="<?=htmlspecialchars($bcfg['smb_domain'])?>" placeholder="Örn: SENER (veya boş)">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Kullanıcı Adı *</label>
                            <div class="col-sm-8">
                                <input type="text" name="smb_user" class="form-control" value="<?=htmlspecialchars($bcfg['smb_user'])?>" placeholder="Örn: administrator veya loguser">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Parola *</label>
                            <div class="col-sm-8">
                                <input type="password" name="smb_password" class="form-control" value="<?=htmlspecialchars($bcfg['smb_password'])?>" placeholder="Kullanıcı parolası">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. NFS Form Fields -->
            <div id="proto-nfs" class="proto-box" style="display: none;">
                <h4><i class="fa-solid fa-server text-info"></i> NFS (Network File System) Ayarları</h4>
                <p class="text-muted">FreeBSD çekirdek NFS istemcisi kullanılarak paylaşılan dizine güvenli aktarım yapılır.</p>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label class="col-sm-4 control-label">NFS Sunucu IP *</label>
                            <div class="col-sm-8">
                                <input type="text" name="nfs_server" class="form-control" value="<?=htmlspecialchars($bcfg['nfs_server'])?>" placeholder="Örn: 192.168.2.100">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Export Yolu *</label>
                            <div class="col-sm-8">
                                <input type="text" name="nfs_export" class="form-control" value="<?=htmlspecialchars($bcfg['nfs_export'])?>" placeholder="Örn: /volume1/5651_backup">
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label class="col-sm-4 control-label">NFS Protokol Versiyonu</label>
                            <div class="col-sm-8">
                                <select name="nfs_version" class="form-control">
                                    <option value="3" <?=$bcfg['nfs_version'] === '3' ? 'selected' : ''?>>NFSv3 (Varsayılan)</option>
                                    <option value="4" <?=$bcfg['nfs_version'] === '4' ? 'selected' : ''?>>NFSv4</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Local / iSCSI Form Fields -->
            <div id="proto-local" class="proto-box" style="display: none;">
                <h4><i class="fa-solid fa-hard-drive text-warning"></i> Yerel Dizin / iSCSI Mount Noktası</h4>
                <p class="text-muted">FreeBSD `iscsictl` ile mount edilmiş iSCSI bloğu, harici disk veya sistem içi yerel bir dizin yolu belirtiniz.</p>
                <div class="form-group">
                    <label class="col-sm-2 control-label">Hedef Dizin Yolu *</label>
                    <div class="col-sm-8">
                        <input type="text" name="local_path" class="form-control" value="<?=htmlspecialchars($bcfg['local_path'])?>" placeholder="Örn: /mnt/nas_backup veya /backup/5651">
                        <span class="help-block">Bu dizinin pfSense üzerinde önceden oluşturulmuş ve yazılabilir olması gereklidir.</span>
                    </div>
                </div>
            </div>

            <!-- 4. SFTP Form Fields -->
            <div id="proto-sftp" class="proto-box" style="display: none;">
                <h4><i class="fa-solid fa-key text-success"></i> SFTP / SSH Güvenli Dosya Aktarımı</h4>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label class="col-sm-4 control-label">SFTP Sunucu IP *</label>
                            <div class="col-sm-8">
                                <input type="text" name="sftp_server" class="form-control" value="<?=htmlspecialchars($bcfg['sftp_server'])?>" placeholder="Örn: 192.168.2.100">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Port</label>
                            <div class="col-sm-8">
                                <input type="number" name="sftp_port" class="form-control" value="<?=$bcfg['sftp_port'] ?: 22?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Uzak Dizin Yolu</label>
                            <div class="col-sm-8">
                                <input type="text" name="sftp_path" class="form-control" value="<?=htmlspecialchars($bcfg['sftp_path'])?>" placeholder="Örn: /backup/5651">
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Kullanıcı Adı *</label>
                            <div class="col-sm-8">
                                <input type="text" name="sftp_user" class="form-control" value="<?=htmlspecialchars($bcfg['sftp_user'])?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Parola *</label>
                            <div class="col-sm-8">
                                <input type="password" name="sftp_password" class="form-control" value="<?=htmlspecialchars($bcfg['sftp_password'])?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 5. FTP Form Fields -->
            <div id="proto-ftp" class="proto-box" style="display: none;">
                <h4><i class="fa-solid fa-folder-open text-danger"></i> FTP / FTPS Ayarları</h4>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label class="col-sm-4 control-label">FTP Sunucu IP *</label>
                            <div class="col-sm-8">
                                <input type="text" name="ftp_server" class="form-control" value="<?=htmlspecialchars($bcfg['ftp_server'])?>" placeholder="Örn: 192.168.2.100">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Port</label>
                            <div class="col-sm-8">
                                <input type="number" name="ftp_port" class="form-control" value="<?=$bcfg['ftp_port'] ?: 21?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Uzak Dizin</label>
                            <div class="col-sm-8">
                                <input type="text" name="ftp_path" class="form-control" value="<?=htmlspecialchars($bcfg['ftp_path'])?>" placeholder="Örn: /5651">
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Kullanıcı Adı *</label>
                            <div class="col-sm-8">
                                <input type="text" name="ftp_user" class="form-control" value="<?=htmlspecialchars($bcfg['ftp_user'])?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">Parola *</label>
                            <div class="col-sm-8">
                                <input type="password" name="ftp_password" class="form-control" value="<?=htmlspecialchars($bcfg['ftp_password'])?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <hr>

            <!-- Buttons -->
            <div class="form-group" style="margin-top: 25px;">
                <div class="col-sm-offset-2 col-sm-10">
                    <button type="submit" name="save" class="btn btn-primary btn-lg" style="margin-right: 12px;">
                        <i class="fa-solid fa-floppy-disk" style="margin-right: 6px;"></i>Ayarları Kaydet
                    </button>
                    <button type="button" id="btn-test-conn" class="btn btn-info btn-lg" style="margin-right: 12px;">
                        <i class="fa-solid fa-plug" style="margin-right: 6px;"></i>Bağlantıyı &amp; Yazmayı Test Et
                    </button>
                    <button type="button" id="btn-sync-all" class="btn btn-success btn-lg">
                        <i class="fa-solid fa-cloud-arrow-up" style="margin-right: 6px;"></i>Tüm Arşivleri Şimdi Aktar (<?=$archive_count?> Arşiv)
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
    function updateProtoDisplay() {
        var proto = $('#protocol-select').val();
        $('.proto-box').hide();
        $('#proto-' + proto).show();
    }

    $('#protocol-select').on('change', updateProtoDisplay);
    updateProtoDisplay();

    // AJAX Connection & Write Test
    $('#btn-test-conn').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var origHtml = btn.html();
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Bağlantı Test Ediliyor...');
        $('#ajax-alert').hide().removeClass('alert-success alert-danger');

        var formData = $('#backup-form').serializeArray();
        formData.push({name: 'ajax_action', value: 'test_connection'});

        $.ajax({
            url: '/posea_5651_backup.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).html(origHtml);
                if (resp.success) {
                    $('#ajax-alert').addClass('alert-success').html('<i class="fa-solid fa-circle-check"></i> ' + resp.message).fadeIn();
                } else {
                    $('#ajax-alert').addClass('alert-danger').html('<i class="fa-solid fa-triangle-exclamation"></i> ' + resp.message).fadeIn();
                }
            },
            error: function(xhr, status, err) {
                btn.prop('disabled', false).html(origHtml);
                $('#ajax-alert').addClass('alert-danger').html('<i class="fa-solid fa-triangle-exclamation"></i> İstek başarısız: ' + err).fadeIn();
            }
        });
    });

    // AJAX Sync All Archives
    $('#btn-sync-all').on('click', function(e) {
        e.preventDefault();
        if (!confirm('Tüm mevcut 5651 imzalı arşiv paketleri seçili uzak sunucuya aktarılacaktır. Devam edilsin mi?')) {
            return;
        }

        var btn = $(this);
        var origHtml = btn.html();
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Arşivler Aktarılıyor...');
        $('#ajax-alert').hide().removeClass('alert-success alert-danger');

        $.ajax({
            url: '/posea_5651_backup.php',
            type: 'POST',
            data: {ajax_action: 'sync_all'},
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).html(origHtml);
                if (resp.success) {
                    $('#ajax-alert').addClass('alert-success').html('<i class="fa-solid fa-circle-check"></i> Başarılı! Toplam ' + resp.synced + ' / ' + resp.total + ' arşiv uzak depolamaya aktarıldı.').fadeIn();
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    $('#ajax-alert').addClass('alert-danger').html('<i class="fa-solid fa-triangle-exclamation"></i> Aktarım sırasında ' + resp.failed + ' arşivde hata oluştu.').fadeIn();
                }
            },
            error: function(xhr, status, err) {
                btn.prop('disabled', false).html(origHtml);
                $('#ajax-alert').addClass('alert-danger').html('<i class="fa-solid fa-triangle-exclamation"></i> İstek başarısız: ' + err).fadeIn();
            }
        });
    });
});
//]]>
</script>

<?php include("foot.inc"); ?>
