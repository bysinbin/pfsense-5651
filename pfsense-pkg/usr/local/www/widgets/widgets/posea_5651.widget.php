<?php
/*
 * posea_5651.widget.php
 *
 * 5651 Yasal Loglama & TÜBİTAK/RFC 3161 Zaman Damgası Dashboard Widget
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

<div class="table-responsive">
	<table class="table table-striped table-hover table-condensed" style="vertical-align: middle; margin-bottom: 0;">
		<tbody>
			<tr>
				<td style="width: 35%;"><strong>Durum:</strong></td>
				<td>
					<?php if ($l5651_running): ?>
						<span class="label label-success" style="font-size: 11px; padding: 4px 8px;">
							<i class="fa-solid fa-circle-check"></i> ÇALIŞIYOR <?=!empty($l5651_pid) ? "(PID: $l5651_pid)" : ""?>
						</span>
					<?php else: ?>
						<span class="label label-danger" style="font-size: 11px; padding: 4px 8px;">
							<i class="fa-solid fa-circle-xmark"></i> DURDURULDU
						</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><strong>Bugünün Logu:</strong></td>
				<td>
					<span class="badge" style="background-color: #f59e0b; font-size: 12px; font-weight: 600;">
						<?=$log_size_str?>
					</span>
					<span style="font-size: 11px; color: #64748b; margin-left: 5px;">(5651_<?=date('Y-m-d')?>.log)</span>
				</td>
			</tr>
			<tr>
				<td><strong>Zaman Damgası:</strong></td>
				<td>
					<span class="label label-primary" style="font-size: 11px;">
						<i class="fa-solid fa-stamp"></i> TÜBİTAK / RFC 3161
					</span>
					<span style="font-size: 11px; color: #475569; margin-left: 5px;">Her gece 23:59:50</span>
				</td>
			</tr>
			<tr>
				<td><strong>Mühürlü Arşivler:</strong></td>
				<td>
					<strong><?=$archive_count?></strong> Günlük İmzalı Paket &bull;
					<?php if ($remote_enabled): ?>
						<span class="text-success" title="Uzak Yedekleme Aktif"><i class="fa-solid fa-cloud-check"></i> <?=$remote_proto?> Yedekleme Aktif</span>
					<?php else: ?>
						<span class="text-muted"><i class="fa-solid fa-cloud"></i> Uzak Yedekleme Kapalı</span>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>
</div>

<div style="margin-top: 10px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #e2e8f0; padding-top: 8px;">
	<div class="btn-group btn-group-xs">
		<a href="/posea_5651_live.php" class="btn btn-info" title="Canlı Yasal Log İzleyici">
			<i class="fa-solid fa-terminal"></i> Canlı Log
		</a>
		<a href="/posea_5651_archives.php" class="btn btn-success" title="İmzalı Günlük Arşivler &amp; İndir">
			<i class="fa-solid fa-box-archive"></i> Arşivler (<?=$archive_count?>)
		</a>
		<a href="/posea_5651_backup.php" class="btn btn-default" title="Uzak Yedekleme Ayarları">
			<i class="fa-solid fa-cloud-arrow-up"></i> Yedekleme
		</a>
		<a href="/pkg_edit.php?xml=posea_5651.xml" class="btn btn-default" title="5651 Ayarları">
			<i class="fa-solid fa-cog"></i> Ayarlar
		</a>
	</div>
	<div>
		<button type="button" class="btn btn-xs btn-warning posea-5651-act-btn" data-action="restart" title="Servisi Yeniden Başlat">
			<i class="fa-solid fa-rotate-right"></i> Yeniden Başlat
		</button>
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
