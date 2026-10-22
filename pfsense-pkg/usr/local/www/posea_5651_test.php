<?php
/*
 * posea_5651_test.php - RFC 3161 Zaman Damgası Sunucusu (TSA) Bağlantı Testi
 */

require_once("guiconfig.inc");
require_once("/usr/local/pkg/posea_5651.inc");

$cfg = posea_5651_get_config();
$test_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'test_tsa') {
        $custom_url = trim($_POST['test_url'] ?? '');
        $custom_user = trim($_POST['test_user'] ?? '');
        $custom_pass = trim($_POST['test_pass'] ?? '');

        $test_cfg = $cfg;
        if ($custom_url) $test_cfg['tsa_url'] = $custom_url;
        if ($custom_user) $test_cfg['tsa_user'] = $custom_user;
        if ($custom_pass) $test_cfg['tsa_pass'] = $custom_pass;

        $test_result = posea_5651_test_tsa($test_cfg);
    }
}

$pgtitle = array(gettext("Services"), gettext("5651 Yasal Loglama"), gettext("Zaman Damgası (TSA) Testi"));
include("head.inc");

$tab_array = array();
$tab_array[] = array(gettext("Genel Ayarlar"), false, "/pkg_edit.php?xml=posea_5651.xml");
$tab_array[] = array(gettext("İmzalı Günlük Arşivler"), false, "/posea_5651_archives.php");
$tab_array[] = array(gettext("Canlı Yasal Log İzleyici"), false, "/posea_5651_live.php");
$tab_array[] = array(gettext("Zaman Damgası (TSA) Testi"), true, "/posea_5651_test.php");
$tab_array[] = array(gettext("Uzak Yedekleme (SMB/NFS/iSCSI)"), false, "/posea_5651_backup.php");
display_top_tabs($tab_array);
?>

<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-default">
            <div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-stamp"></i> RFC 3161 Zaman Damgası Otoritesi (TSA) İletişim Testi</h2></div>
            <div class="panel-body">
                <p class="text-muted">
                    Tanımlanan Zaman Damgası Sunucusu (<strong><?=htmlspecialchars($cfg['tsa_url'])?></strong>) ile OpenSSL üzerinden test niteliğinde bir TimeStampReq paketi gönderir ve dönen dijital mührü (TSR) anlık olarak doğrular.
                </p>

                <form method="post" class="form-horizontal">
                    <input type="hidden" name="act" value="test_tsa" />

                    <div class="form-group">
                        <label class="col-sm-2 control-label">TSA URL Adresi:</label>
                        <div class="col-sm-6">
                            <input type="text" name="test_url" class="form-control" value="<?=htmlspecialchars($cfg['tsa_url'])?>" required />
                            <small class="text-muted">TÜBİTAK KamuSM için: <code>http://zds.kamusm.gov.tr</code> veya test için <code>https://freetsa.org/tsr</code></small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-2 control-label">Kullanıcı Adı (Opsiyonel):</label>
                        <div class="col-sm-4">
                            <input type="text" name="test_user" class="form-control" value="<?=htmlspecialchars($cfg['tsa_user'])?>" placeholder="TÜBİTAK Müşteri No / Kullanıcı" />
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-2 control-label">Parola / Token (Opsiyonel):</label>
                        <div class="col-sm-4">
                            <input type="password" name="test_pass" class="form-control" value="<?=htmlspecialchars($cfg['tsa_pass'])?>" placeholder="API Parolası" />
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-sm-offset-2 col-sm-6">
                            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-play"></i> Zaman Damgası Testini Başlat</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($test_result !== null): ?>
            <div class="panel panel-default">
                <div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-clipboard-check"></i> Test Sonuçları</h2></div>
                <div class="panel-body">
                    <?php if ($test_result['status']): ?>
                        <div class="alert alert-success">
                            <h4><i class="fa-solid fa-circle-check"></i> Zaman Damgası Başarıyla Alındı ve Doğrulandı!</h4>
                            <p><?=$test_result['msg']?></p>
                            <hr style="margin: 10px 0;"/>
                            <p><strong>Mühür Zamanı (TSA Time):</strong> <?=htmlspecialchars($test_result['time_stamp'])?></p>
                            <p><strong>Seri Numarası:</strong> <code><?=htmlspecialchars($test_result['serial'])?></code></p>
                            <p><strong>İmzalayan Otorite (TSA):</strong> <?=htmlspecialchars($test_result['tsa'] ?: 'RFC 3161 Compliant Authority')?></p>
                        </div>

                        <h4>Ham ASN.1 OpenSSL Yanıtı (TSR Text):</h4>
                        <pre style="background: #0f172a; color: #38bdf8; padding: 12px; border-radius: 6px; max-height: 300px; overflow-y: auto;"><?=htmlspecialchars($test_result['raw'])?></pre>
                    <?php else: ?>
                        <div class="alert alert-danger">
                            <h4><i class="fa-solid fa-circle-xmark"></i> Zaman Damgası Testi Başarısız Oldu!</h4>
                            <p><?=$test_result['msg']?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include("foot.inc"); ?>
