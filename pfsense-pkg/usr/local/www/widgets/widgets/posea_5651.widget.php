<?php
/*
 * posea_5651.widget.php
 *
 * 5651 Yasal Loglama & TÜBİTAK/RFC 3161 Zaman Damgası Dashboard Widget
 * Visual Metrics, TSA Status, and Remote Storage Info
 */

require_once("guiconfig.inc");
require_once("service-utils.inc");
require_once("/usr/local/pkg/posea_5651.inc");
require_once("/usr/local/www/widgets/include/posea_5651.inc");

// Handle AJAX actions
if (isset($_POST['l5651_action'])) {
	header('Content-Type: application/json');
	$act = $_POST['l5651_action'];
	if (in_array($act, ['start', 'stop', 'restart'])) {
		mwexec_bg("/usr/local/etc/rc.d/posea_5651.sh " . escapeshellarg($act));
		usleep(300000);
		echo json_encode(['success' => true, 'action' => $act]);
	} else {
		echo json_encode(['success' => false, 'error' => 'Geçersiz eylem']);
	}
	exit;
}

$l5651_running = isvalidpid('/var/run/posea_5651.pid');
$l5651_pid = $l5651_running ? trim(@file_get_contents('/var/run/posea_5651.pid')) : '';

// Today log stats
$today_log = '/var/log/5651/active/5651_' . date('Y-m-d') . '.log';
$log_size_str = '0 B';
if (file_exists($today_log)) {
	$bytes = filesize($today_log);
	if ($bytes >= 1048576) {
		$log_size_str = number_format($bytes / 1048576, 2, ',', '.') . ' MB';
	} elseif ($bytes >= 1024) {
		$log_size_str = number_format($bytes / 1024, 1, ',', '.') . ' KB';
	} else {
		$log_size_str = $bytes . ' B';
	}
}

// Archives count
$archives = posea_5651_get_archives();
$archive_count = count($archives);

// Remote Backup Status
$bcfg = posea_5651_get_backup_config();
$remote_enabled = !empty($bcfg['enabled']);
$remote_proto = strtoupper($bcfg['protocol'] ?? 'SMB');
?>

<style>
.l5651-stat-grid {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 8px;
	margin-bottom: 12px;
}
.l5651-stat-card {
	background: #1e293b;
	border: 1px solid #334155;
	border-radius: 6px;
	padding: 10px 8px;
	text-align: center;
	color: #f8fafc;
}
.l5651-stat-num {
	font-size: 18px;
	font-weight: 700;
	line-height: 1.1;
	margin-top: 2px;
}
.l5651-stat-lbl {
	font-size: 10px;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: #94a3b8;
}
</style>

<div id="posea-5651-widget-body">
	<!-- Top Service Status Bar -->
	<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
		<div>
			<strong style="color: #94a3b8; font-size: 11px; text-transform: uppercase;">Servis Durumu:</strong>
			<?php if ($l5651_running): ?>
				<span class="label label-success" style="font-size: 11px; padding: 3px 7px; margin-left: 4px;">
					<i class="fa-solid fa-circle-check"></i> ÇALIŞIYOR <?=!empty($l5651_pid) ? "($l5651_pid)" : ""?>
				</span>
			<?php else: ?>
				<span class="label label-danger" style="font-size: 11px; padding: 3px 7px; margin-left: 4px;">
					<i class="fa-solid fa-circle-xmark"></i> DURDURULDU
				</span>
			<?php endif; ?>
		</div>
		<div>
			<button type="button" class="btn btn-xs btn-warning posea-5651-act-btn" data-action="restart" title="Servisi Yeniden Başlat">
				<i class="fa-solid fa-rotate-right"></i>
			</button>
		</div>
	</div>

	<!-- 3 Mini Metric Cards -->
	<div class="l5651-stat-grid">
		<div class="l5651-stat-card">
			<div class="l5651-stat-lbl">Bugünün Logu</div>
			<div class="l5651-stat-num text-warning"><?=$log_size_str?></div>
		</div>
		<div class="l5651-stat-card">
			<div class="l5651-stat-lbl">İmzalı Arşiv</div>
			<div class="l5651-stat-num text-success"><?=$archive_count?> Gün</div>
		</div>
		<div class="l5651-stat-card">
			<div class="l5651-stat-lbl">Uzak Depolama</div>
			<div class="l5651-stat-num text-info">
				<?php if ($remote_enabled): ?>
					<span style="font-size: 14px;"><?=$remote_proto?></span>
				<?php else: ?>
					<span class="text-muted" style="font-size: 14px;">KAPALI</span>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- TSA & Storage Status Banner -->
	<div style="background: #1e293b; border: 1px solid #334155; border-radius: 6px; padding: 10px; margin-bottom: 12px; font-size: 11px;">
		<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
			<span style="color: #cbd5e1;"><i class="fa-solid fa-stamp text-primary"></i> <strong>Zaman Damgası:</strong> TÜBİTAK / RFC 3161</span>
			<span class="label label-primary" style="font-size: 10px;">HER GECE 23:59</span>
		</div>
		<div style="display: flex; justify-content: space-between; align-items: center; color: #94a3b8; font-size: 10px;">
			<span><i class="fa-solid fa-file-contract"></i> 5651 IPDR + DHCP + NAT + AD SSO</span>
			<span>
				<?php if ($remote_enabled): ?>
					<strong class="text-success"><i class="fa-solid fa-cloud-arrow-up"></i> Otomatik Aktarım Açık</strong>
				<?php else: ?>
					<span class="text-muted"><i class="fa-solid fa-cloud"></i> Yerel Disk</span>
				<?php endif; ?>
			</span>
		</div>
	</div>

	<!-- Action Buttons -->
	<div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #334155; padding-top: 8px;">
		<div class="btn-group btn-group-xs">
			<a href="/posea_5651_live.php" class="btn btn-info" title="Canlı Yasal Log İzleyici">
				<i class="fa-solid fa-terminal"></i> Canlı Log
			</a>
			<a href="/posea_5651_archives.php" class="btn btn-success" title="İmzalı Günlük Arşivler &amp; ZIP İndir">
				<i class="fa-solid fa-box-archive"></i> Arşivler (<?=$archive_count?>)
			</a>
			<a href="/posea_5651_backup.php" class="btn btn-default" title="Uzak Yedekleme Ayarları">
				<i class="fa-solid fa-cloud-arrow-up"></i> Uzak Yedek
			</a>
			<a href="/pkg_edit.php?xml=posea_5651.xml" class="btn btn-default" title="5651 Ayarları">
				<i class="fa-solid fa-cog"></i> Ayarlar
			</a>
		</div>
		<div>
			<a href="/posea_5651_test.php" class="btn btn-xs btn-default" title="Zaman Damgası Testi">
				<i class="fa-solid fa-stamp"></i> Test
			</a>
		</div>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	$('.posea-5651-act-btn').on('click', function(e) {
		e.preventDefault();
		var btn = $(this);
		var icon = btn.find('i');
		icon.removeClass('fa-rotate-right').addClass('fa-spinner fa-spin');
		btn.prop('disabled', true);

		$.ajax({
			url: '/widgets/widgets/posea_5651.widget.php',
			type: 'POST',
			data: { l5651_action: btn.data('action') },
			dataType: 'json',
			success: function() {
				setTimeout(function() { location.reload(); }, 1200);
			},
			error: function() {
				setTimeout(function() { location.reload(); }, 1500);
			}
		});
	});
});
//]]>
</script>
