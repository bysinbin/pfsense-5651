<?php
/*
 * posea_5651.inc - 5651 Yasal Loglama ve TÜBİTAK/RFC 3161 Zaman Damgası Kütüphanesi
 *
 * pfSense üzerinde 5651 sayılı kanun uyumlu IPDR, DHCP, NAT ve AD SSO oturum
 * loglarını toplar, günlük SHA-256 kriptografik özeti çıkarır ve RFC 3161
 * Zaman Damgası (TÜBİTAK KamuSM / Türktrust / E-Tuğra / FreeTSA) ile mühürler.
 *
 * SMB2/3, NFS, iSCSI/Yerel Mount, SFTP ve FTP ile otomatik uzak arşiv yedeklemeyi destekler.
 */

require_once("config.inc");
require_once("util.inc");
require_once("pfsense-utils.inc");
require_once("service-utils.inc");

define('POSEA_5651_CONF_DIR', '/var/etc/posea_5651');
define('POSEA_5651_CONF', POSEA_5651_CONF_DIR . '/config.json');
define('POSEA_5651_BACKUP_CONF', POSEA_5651_CONF_DIR . '/backup.json');
define('POSEA_5651_LOG_DIR', '/var/log/5651');
define('POSEA_5651_ACTIVE_DIR', POSEA_5651_LOG_DIR . '/active');
define('POSEA_5651_ARCHIVE_DIR', POSEA_5651_LOG_DIR . '/archives');
define('POSEA_5651_INDEX_FILE', POSEA_5651_ARCHIVE_DIR . '/index.json');
define('POSEA_5651_DAEMON_LOG', '/var/log/posea_5651.log');
define('POSEA_5651_PID', '/var/run/posea_5651.pid');
define('POSEA_5651_RC', '/usr/local/etc/rc.d/posea_5651.sh');
define('POSEA_5651_SMB_BIN', '/usr/local/bin/posea-smb-sync');

@mkdir(POSEA_5651_CONF_DIR, 0755, true);
@mkdir(POSEA_5651_ACTIVE_DIR, 0755, true);
@mkdir(POSEA_5651_ARCHIVE_DIR, 0755, true);

function posea_5651_is_running() {
    $pidfile = POSEA_5651_PID;
    if (file_exists($pidfile)) {
        $pid = intval(trim(file_get_contents($pidfile)));
        if ($pid > 0 && posix_kill($pid, 0)) {
            return true;
        }
    }
    $out = trim(shell_exec("/usr/bin/pgrep -f posea_5651d.php 2>/dev/null") ?? '');
    return !empty($out);
}

function posea_5651_log($msg, $level = "INFO") {
    $ts = date("Y-m-d H:i:s");
    $line = "[{$ts}] [{$level}] {$msg}\n";
    @file_put_contents(POSEA_5651_DAEMON_LOG, $line, FILE_APPEND);
}

function posea_5651_get_config() {
    global $config;

    $defaults = [
        "enabled" => true,
        "log_dhcp" => true,
        "log_nat" => true,
        "log_filter" => true,
        "ad_sso_enrich" => true,
        "tsa_provider" => "freetsa",
        "tsa_url" => "https://freetsa.org/tsr",
        "tsa_user" => "",
        "tsa_pass" => "",
        "tsa_hash" => "sha256",
        "retention_days" => 730,
        "sign_time" => "00:01",
        "gzip_compress" => true
    ];

    if (file_exists(POSEA_5651_CONF)) {
        $loaded = json_decode(file_get_contents(POSEA_5651_CONF), true) ?: [];
        return array_merge($defaults, $loaded);
    }

    if (!empty($config['installedpackages']['posea5651']['config'][0])) {
        $pkg = $config['installedpackages']['posea5651']['config'][0];
        $from_xml = [
            "enabled" => !empty($pkg['enable']),
            "log_dhcp" => !empty($pkg['log_dhcp']),
            "log_nat" => !empty($pkg['log_nat']),
            "log_filter" => !empty($pkg['log_filter']),
            "ad_sso_enrich" => !empty($pkg['ad_sso_enrich']),
            "tsa_provider" => $pkg['tsa_provider'] ?? "freetsa",
            "tsa_url" => $pkg['tsa_url'] ?? "https://freetsa.org/tsr",
            "tsa_user" => $pkg['tsa_user'] ?? "",
            "tsa_pass" => $pkg['tsa_pass'] ?? "",
            "tsa_hash" => $pkg['tsa_hash'] ?? "sha256",
            "retention_days" => intval($pkg['retention_days'] ?? 730),
            "sign_time" => $pkg['sign_time'] ?? "00:01",
            "gzip_compress" => !empty($pkg['gzip_compress'])
        ];
        return array_merge($defaults, $from_xml);
    }

    return $defaults;
}

function posea_5651_save_config($cfg) {
    @mkdir(POSEA_5651_CONF_DIR, 0755, true);
    return file_put_contents(POSEA_5651_CONF, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function posea_5651_get_active_file($date = null) {
    if (!$date) $date = date("Y-m-d");
    @mkdir(POSEA_5651_ACTIVE_DIR, 0755, true);
    return POSEA_5651_ACTIVE_DIR . "/5651_{$date}.log";
}

/* Format BTK/5651 pipe-separated legal log row */
function posea_5651_format_row($ts, $mac, $local_ip, $local_port, $user, $dest_ip, $dest_port, $nat_ip, $nat_port, $proto, $action, $detail = '') {
    $ts = $ts ?: date("Y-m-d H:i:s");
    $mac = $mac ?: "-";
    $local_ip = $local_ip ?: "-";
    $local_port = $local_port ?: "-";
    $user = $user ?: "-";
    $dest_ip = $dest_ip ?: "-";
    $dest_port = $dest_port ?: "-";
    $nat_ip = $nat_ip ?: "-";
    $nat_port = $nat_port ?: "-";
    $proto = strtoupper($proto ?: "TCP");
    $action = strtoupper($action ?: "ALLOW");
    $detail = str_replace(["\r", "\n", "|"], " ", trim($detail));

    return "{$ts}|{$mac}|{$local_ip}:{$local_port}|{$user}|{$dest_ip}:{$dest_port}|{$nat_ip}:{$nat_port}|{$proto}|{$action}|{$detail}\n";
}

/* === RFC 3161 ZAMAN DAMGASI İMZALAMA MOTORU === */

function posea_5651_sign_daily_log($date) {
    $cfg = posea_5651_get_config();
    $active_file = POSEA_5651_ACTIVE_DIR . "/5651_{$date}.log";

    if (!file_exists($active_file) || filesize($active_file) == 0) {
        posea_5651_log("Signing skipped: {$active_file} not found or empty", "WARN");
        return false;
    }

    $archive_base = POSEA_5651_ARCHIVE_DIR . "/5651_{$date}";
    $archive_log = "{$archive_base}.log";
    $archive_sha = "{$archive_base}.sha256";
    $archive_tsq = "{$archive_base}.tsq";
    $archive_tsr = "{$archive_base}.tsr";

    // 1. Move active log to archive
    @rename($active_file, $archive_log);

    // 2. Count lines and size
    $line_count = 0;
    $fp = @fopen($archive_log, "r");
    if ($fp) {
        while (!feof($fp)) {
            $line_count += substr_count(fread($fp, 65536), "\n");
        }
        fclose($fp);
    }
    $raw_size = filesize($archive_log);

    // 3. Compute SHA-256 hash
    $hash_val = hash_file("sha256", $archive_log);
    $sha_content = "{$hash_val}  5651_{$date}.log\n";
    file_put_contents($archive_sha, $sha_content);

    // 4. Generate RFC 3161 Request (.tsq) via OpenSSL
    $cmd_tsq = "/usr/bin/openssl ts -query -data " . escapeshellarg($archive_log) . " -sha256 -cert -out " . escapeshellarg($archive_tsq) . " 2>&1";
    shell_exec($cmd_tsq);

    if (!file_exists($archive_tsq) || filesize($archive_tsq) == 0) {
        posea_5651_log("Failed to create OpenSSL timestamp request for {$date}", "ERROR");
        return false;
    }

    // 5. Send .tsq to TSA server via curl
    $tsa_url = $cfg['tsa_url'] ?: "https://freetsa.org/tsr";
    $auth_flag = "";
    if (!empty($cfg['tsa_user']) && !empty($cfg['tsa_pass'])) {
        $auth_flag = "-u " . escapeshellarg($cfg['tsa_user'] . ":" . $cfg['tsa_pass']);
    }

    $cmd_curl = "/usr/local/bin/curl -s -m 20 {$auth_flag} -H 'Content-Type: application/timestamp-query' --data-binary @" . escapeshellarg($archive_tsq) . " " . escapeshellarg($tsa_url) . " > " . escapeshellarg($archive_tsr);
    shell_exec($cmd_curl);

    // 6. Verify TSA Response
    $ts_info = [];
    $is_granted = false;
    if (file_exists($archive_tsr) && filesize($archive_tsr) > 100) {
        $parse_cmd = "/usr/bin/openssl ts -reply -in " . escapeshellarg($archive_tsr) . " -text 2>/dev/null";
        $text_out = shell_exec($parse_cmd);
        if ($text_out && stripos($text_out, "Status: Granted") !== false) {
            $is_granted = true;

            // Extract Time stamp string
            if (preg_match('/Time stamp:\s*(.+)/i', $text_out, $m_t)) {
                $ts_info['time_stamp'] = trim($m_t[1]);
            }
            // Extract Serial Number
            if (preg_match('/Serial number:\s*(.+)/i', $text_out, $m_s)) {
                $ts_info['serial'] = trim($m_s[1]);
            }
            // Extract TSA Authority
            if (preg_match('/TSA:\s*(.+)/i', $text_out, $m_a)) {
                $ts_info['tsa'] = trim($m_a[1]);
            }
        }
    }

    // 7. Optional: Gzip log file for storage compression
    $is_gzipped = false;
    if (!empty($cfg['gzip_compress'])) {
        $gz_cmd = "/usr/bin/gzip -c " . escapeshellarg($archive_log) . " > " . escapeshellarg("{$archive_log}.gz");
        shell_exec($gz_cmd);
        if (file_exists("{$archive_log}.gz")) {
            $is_gzipped = true;
        }
    }

    // 8. Update Index JSON
    $index = posea_5651_get_archives();
    $index[$date] = [
        "date" => $date,
        "log_file" => "5651_{$date}.log",
        "has_gz" => $is_gzipped,
        "lines" => $line_count,
        "size_bytes" => $raw_size,
        "sha256" => $hash_val,
        "tsr_file" => "5651_{$date}.tsr",
        "tsr_granted" => $is_granted,
        "tsa_url" => $tsa_url,
        "tsa_time" => $ts_info['time_stamp'] ?? date("Y-m-d H:i:s"),
        "tsa_serial" => $ts_info['serial'] ?? '-',
        "signed_at" => date("Y-m-d H:i:s"),
        "remote_synced" => false
    ];
    file_put_contents(POSEA_5651_INDEX_FILE, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    posea_5651_log("Daily 5651 log signed successfully for {$date} (Lines: {$line_count}, TSA: " . ($is_granted ? 'OK' : 'FAIL') . ")", "INFO");

    // 9. Auto-package complete ZIP bundle
    posea_5651_create_zip_archive($date);

    // 10. Remote Backup Trigger (SMB / NFS / iSCSI / SFTP / FTP)
    $bcfg = posea_5651_get_backup_config();
    if (!empty($bcfg['enabled']) && !empty($bcfg['auto_sync'])) {
        posea_5651_sync_archive($date, $bcfg);
    }

    // 11. Local Retention Cleanup
    if (!empty($bcfg['retention_days']) && intval($bcfg['retention_days']) > 0) {
        posea_5651_cleanup_retention(intval($bcfg['retention_days']));
    }

    return true;
}

function posea_5651_get_archives() {
    if (file_exists(POSEA_5651_INDEX_FILE)) {
        return json_decode(file_get_contents(POSEA_5651_INDEX_FILE), true) ?: [];
    }
    return [];
}

function posea_5651_verify_archive($date) {
    $archive_base = POSEA_5651_ARCHIVE_DIR . "/5651_{$date}";
    $log_file = "{$archive_base}.log";
    $sha_file = "{$archive_base}.sha256";
    $tsr_file = "{$archive_base}.tsr";

    $result = [
        "date" => $date,
        "log_exists" => file_exists($log_file),
        "sha_matches" => false,
        "tsr_granted" => false,
        "tsa_time" => "-",
        "tsa_serial" => "-",
        "tsa_name" => "-",
        "valid" => false
    ];

    if (!$result['log_exists'] && file_exists("{$log_file}.gz")) {
        $log_file = "{$log_file}.gz";
        $result['log_exists'] = true;
    }

    if (!file_exists($sha_file) || !file_exists($tsr_file)) {
        return $result;
    }

    // Verify SHA-256
    $sha_content = trim(file_get_contents($sha_file));
    $expected_hash = explode(" ", $sha_content)[0];
    $actual_hash = hash_file("sha256", $log_file);
    if (strcasecmp($expected_hash, $actual_hash) === 0) {
        $result['sha_matches'] = true;
    }

    // Verify TSR
    $parse_cmd = "/usr/bin/openssl ts -reply -in " . escapeshellarg($tsr_file) . " -text 2>/dev/null";
    $text_out = shell_exec($parse_cmd);
    if ($text_out && stripos($text_out, "Status: Granted") !== false) {
        $result['tsr_granted'] = true;
        if (preg_match('/Time stamp:\s*(.+)/i', $text_out, $m_t)) {
            $result['tsa_time'] = trim($m_t[1]);
        }
        if (preg_match('/Serial number:\s*(.+)/i', $text_out, $m_s)) {
            $result['tsa_serial'] = trim($m_s[1]);
        }
        if (preg_match('/TSA:\s*(.+)/i', $text_out, $m_a)) {
            $result['tsa_name'] = trim($m_a[1]);
        }
    }

    $result['valid'] = ($result['sha_matches'] && $result['tsr_granted']);
    return $result;
}

function posea_5651_test_tsa($cfg) {
    $tsa_url = $cfg['tsa_url'] ?: "https://freetsa.org/tsr";
    $tmp_dir = "/tmp/5651_tsa_test_" . uniqid();
    @mkdir($tmp_dir, 0755, true);

    $dummy_file = "{$tmp_dir}/test.txt";
    file_put_contents($dummy_file, "Posea 5651 TSA Connectivity Test: " . date("Y-m-d H:i:s"));

    $tsq_file = "{$tmp_dir}/test.tsq";
    $tsr_file = "{$tmp_dir}/test.tsr";

    shell_exec("/usr/bin/openssl ts -query -data " . escapeshellarg($dummy_file) . " -sha256 -cert -out " . escapeshellarg($tsq_file) . " 2>&1");

    if (!file_exists($tsq_file) || filesize($tsq_file) == 0) {
        @shell_exec("rm -rf " . escapeshellarg($tmp_dir));
        return ["status" => false, "msg" => "OpenSSL RFC 3161 istek paketi (.tsq) oluşturulamadı."];
    }

    $auth_flag = "";
    if (!empty($cfg['tsa_user']) && !empty($cfg['tsa_pass'])) {
        $auth_flag = "-u " . escapeshellarg($cfg['tsa_user'] . ":" . $cfg['tsa_pass']);
    }

    $cmd_curl = "/usr/local/bin/curl -s -m 15 {$auth_flag} -H 'Content-Type: application/timestamp-query' --data-binary @" . escapeshellarg($tsq_file) . " " . escapeshellarg($tsa_url) . " > " . escapeshellarg($tsr_file);
    shell_exec($cmd_curl);

    if (!file_exists($tsr_file) || filesize($tsr_file) < 50) {
        @shell_exec("rm -rf " . escapeshellarg($tmp_dir));
        return ["status" => false, "msg" => "TSA sunucusundan yanıt alınamadı. Sunucu adresi veya internet bağlantısını kontrol edin."];
    }

    $out = shell_exec("/usr/bin/openssl ts -reply -in " . escapeshellarg($tsr_file) . " -text 2>/dev/null");
    @shell_exec("rm -rf " . escapeshellarg($tmp_dir));

    if ($out && stripos($out, "Status: Granted") !== false) {
        $time_stamp = "";
        $serial = "";
        $tsa_name = "";
        if (preg_match('/Time stamp:\s*(.+)/i', $out, $m)) $time_stamp = trim($m[1]);
        if (preg_match('/Serial number:\s*(.+)/i', $out, $m)) $serial = trim($m[1]);
        if (preg_match('/TSA:\s*(.+)/i', $out, $m)) $tsa_name = trim($m[1]);

        return [
            "status" => true,
            "msg" => "Zaman damgası oturumu başarıyla onaylandı (Status: Granted).",
            "time_stamp" => $time_stamp,
            "serial" => $serial,
            "tsa" => $tsa_name,
            "raw" => $out
        ];
    }

    return [
        "status" => false,
        "msg" => "TSA isteği onaylamadı (Kullanıcı adı, parola veya kontör geçersiz olabilir): " . substr($out, 0, 200)
    ];
}

/* === REMOTE BACKUP & TRANSFER ENGINE (SMB / NFS / iSCSI / SFTP / FTP) === */

function posea_5651_get_backup_config() {
    global $config;

    $defaults = [
        "enabled" => false,
        "protocol" => "smb",
        "auto_sync" => true,
        "retention_days" => 0,
        // SMB
        "smb_server" => "",
        "smb_port" => 445,
        "smb_share" => "",
        "smb_dir" => "",
        "smb_domain" => "",
        "smb_user" => "",
        "smb_password" => "",
        // NFS
        "nfs_server" => "",
        "nfs_export" => "",
        "nfs_version" => "3",
        // Local / iSCSI
        "local_path" => "",
        // SFTP
        "sftp_server" => "",
        "sftp_port" => 22,
        "sftp_user" => "",
        "sftp_password" => "",
        "sftp_path" => "",
        // FTP
        "ftp_server" => "",
        "ftp_port" => 21,
        "ftp_user" => "",
        "ftp_password" => "",
        "ftp_path" => "",
        // Status tracking
        "last_sync_time" => "",
        "last_sync_status" => "",
        "last_sync_msg" => ""
    ];

    if (file_exists(POSEA_5651_BACKUP_CONF)) {
        $loaded = json_decode(file_get_contents(POSEA_5651_BACKUP_CONF), true) ?: [];
        return array_merge($defaults, $loaded);
    }

    if (!empty($config['installedpackages']['posea5651backup']['config'][0])) {
        $pkg = $config['installedpackages']['posea5651backup']['config'][0];
        return array_merge($defaults, $pkg);
    }

    return $defaults;
}

function posea_5651_save_backup_config($cfg) {
    global $config;

    @mkdir(POSEA_5651_CONF_DIR, 0755, true);
    file_put_contents(POSEA_5651_BACKUP_CONF, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    if (!isset($config['installedpackages']['posea5651backup'])) {
        $config['installedpackages']['posea5651backup'] = ['config' => []];
    }
    $config['installedpackages']['posea5651backup']['config'][0] = $cfg;
    write_config("Updated Posea 5651 remote backup settings");
    return true;
}

/* Creates a complete single .zip archive containing .log, .sha256, and .tsr */
function posea_5651_create_zip_archive($date) {
    $archive_base = POSEA_5651_ARCHIVE_DIR . "/5651_{$date}";
    $zip_file = "{$archive_base}.zip";

    $files = [];
    if (file_exists("{$archive_base}.log")) $files[] = "{$archive_base}.log";
    elseif (file_exists("{$archive_base}.log.gz")) $files[] = "{$archive_base}.log.gz";
    if (file_exists("{$archive_base}.sha256")) $files[] = "{$archive_base}.sha256";
    if (file_exists("{$archive_base}.tsr")) $files[] = "{$archive_base}.tsr";
    if (file_exists("{$archive_base}.tsq")) $files[] = "{$archive_base}.tsq";

    if (empty($files)) {
        return false;
    }

    $file_args = implode(" ", array_map('escapeshellarg', $files));
    $zip_cmd = "/usr/local/bin/zip -j -q " . escapeshellarg($zip_file) . " {$file_args} 2>&1";
    shell_exec($zip_cmd);

    return file_exists($zip_file) ? $zip_file : false;
}

/* Test remote connectivity and write permissions */
function posea_5651_test_backup_connection($cfg = null) {
    if (!$cfg) $cfg = posea_5651_get_backup_config();
    $proto = strtolower($cfg['protocol'] ?? 'smb');

    switch ($proto) {
        case 'smb':
            if (!file_exists(POSEA_5651_SMB_BIN)) {
                return ["success" => false, "message" => "SMB aktarım ikili dosyası bulunamadı: " . POSEA_5651_SMB_BIN];
            }
            if (empty($cfg['smb_server']) || empty($cfg['smb_share'])) {
                return ["success" => false, "message" => "SMB Sunucu IP ve Paylaşım adı zorunludur."];
            }
            $port = intval($cfg['smb_port'] ?: 445);
            $cmd = sprintf(
                "%s -server %s -port %d -share %s -dir %s -domain %s -user %s -password %s -action test",
                escapeshellcmd(POSEA_5651_SMB_BIN),
                escapeshellarg($cfg['smb_server']),
                $port,
                escapeshellarg($cfg['smb_share']),
                escapeshellarg($cfg['smb_dir'] ?? ''),
                escapeshellarg($cfg['smb_domain'] ?? ''),
                escapeshellarg($cfg['smb_user'] ?? ''),
                escapeshellarg($cfg['smb_password'] ?? '')
            );
            $out = trim(shell_exec($cmd) ?? '');
            $res = json_decode($out, true);
            if ($res) {
                return [
                    "success" => !empty($res['success']),
                    "message" => !empty($res['success']) ? ($res['message'] ?? 'SMB bağlantısı başarılı') : ($res['error'] ?? 'SMB bağlantı hatası')
                ];
            }
            return ["success" => false, "message" => "SMB çıktısı ayrıştırılamadı: " . substr($out, 0, 300)];

        case 'nfs':
            if (empty($cfg['nfs_server']) || empty($cfg['nfs_export'])) {
                return ["success" => false, "message" => "NFS Sunucu IP ve Dışa Aktarım (Export) yolu zorunludur."];
            }
            $tmp_mnt = "/tmp/posea_nfs_test_" . uniqid();
            @mkdir($tmp_mnt, 0755, true);
            $ver_opt = ($cfg['nfs_version'] === '4') ? "-o nfsv4" : "-o nfsv3";
            $cmd = sprintf("/sbin/mount_nfs %s -o retrycnt=1,timeout=5 %s %s 2>&1", $ver_opt, escapeshellarg($cfg['nfs_server'] . ':' . $cfg['nfs_export']), escapeshellarg($tmp_mnt));
            exec($cmd, $out, $ret);
            if ($ret !== 0) {
                @rmdir($tmp_mnt);
                return ["success" => false, "message" => "NFS Bağlantı hatası: " . implode(" ", $out)];
            }
            $test_file = "{$tmp_mnt}/.posea_test_" . time() . ".tmp";
            $write_ok = @file_put_contents($test_file, "Posea 5651 NFS Test");
            @unlink($test_file);
            exec("/sbin/umount -f " . escapeshellarg($tmp_mnt));
            @rmdir($tmp_mnt);

            if ($write_ok === false) {
                return ["success" => false, "message" => "NFS paylaşıma bağlandı ancak yazma izni (write permission) başarısız!"];
            }
            return ["success" => true, "message" => "NFS Paylaşımına bağlanıldı ve yazma testi başarıyla tamamlandı!"];

        case 'local':
            $dir = rtrim($cfg['local_path'] ?? '', '/\\');
            if (empty($dir)) {
                return ["success" => false, "message" => "Yerel / Mount edilmiş hedef dizin yolu boş olamaz."];
            }
            if (!is_dir($dir)) {
                return ["success" => false, "message" => "Belirtilen hedef dizin mevcut değil: {$dir}"];
            }
            $test_file = "{$dir}/.posea_test_" . time() . ".tmp";
            if (@file_put_contents($test_file, "Posea 5651 Local Test") === false) {
                return ["success" => false, "message" => "Hedef dizine yazma izni yok: {$dir}"];
            }
            @unlink($test_file);
            return ["success" => true, "message" => "Hedef dizin ({$dir}) erişilebilir ve yazma izinleri tam!"];

        case 'sftp':
            if (empty($cfg['sftp_server']) || empty($cfg['sftp_user'])) {
                return ["success" => false, "message" => "SFTP Sunucu ve Kullanıcı adı zorunludur."];
            }
            $port = intval($cfg['sftp_port'] ?: 22);
            $auth = escapeshellarg($cfg['sftp_user'] . ':' . $cfg['sftp_password']);
            $url = escapeshellarg("sftp://" . $cfg['sftp_server'] . ":" . $port . "/" . ltrim($cfg['sftp_path'] ?? '', '/'));
            $cmd = "/usr/local/bin/curl -s -k -m 10 -u {$auth} {$url} -l 2>&1";
            exec($cmd, $out, $ret);
            if ($ret === 0) {
                return ["success" => true, "message" => "SFTP Sunucu bağlantısı ve dizin erişimi başarılı!"];
            }
            return ["success" => false, "message" => "SFTP Bağlantı hatası: " . implode(" ", $out)];

        case 'ftp':
            if (empty($cfg['ftp_server']) || empty($cfg['ftp_user'])) {
                return ["success" => false, "message" => "FTP Sunucu ve Kullanıcı adı zorunludur."];
            }
            $port = intval($cfg['ftp_port'] ?: 21);
            $auth = escapeshellarg($cfg['ftp_user'] . ':' . $cfg['ftp_password']);
            $url = escapeshellarg("ftp://" . $cfg['ftp_server'] . ":" . $port . "/" . ltrim($cfg['ftp_path'] ?? '', '/'));
            $cmd = "/usr/local/bin/curl -s -m 10 -u {$auth} {$url} -l 2>&1";
            exec($cmd, $out, $ret);
            if ($ret === 0) {
                return ["success" => true, "message" => "FTP Sunucu bağlantısı ve listeleme başarılı!"];
            }
            return ["success" => false, "message" => "FTP Bağlantı hatası: " . implode(" ", $out)];

        default:
            return ["success" => false, "message" => "Desteklenmeyen protokol: {$proto}"];
    }
}

/* Upload a specific daily archive (.zip) to configured remote storage */
function posea_5651_sync_archive($date, $bcfg = null) {
    if (!$bcfg) $bcfg = posea_5651_get_backup_config();
    if (empty($bcfg['enabled'])) {
        return ["success" => false, "message" => "Uzak yedekleme devre dışı."];
    }

    $zip_file = posea_5651_create_zip_archive($date);
    if (!$zip_file || !file_exists($zip_file)) {
        return ["success" => false, "message" => "Arşiv zip paketi bulunamadı: 5651_{$date}.zip"];
    }

    $proto = strtolower($bcfg['protocol'] ?? 'smb');
    $base_name = basename($zip_file);
    $res = ["success" => false, "message" => ""];

    switch ($proto) {
        case 'smb':
            $port = intval($bcfg['smb_port'] ?: 445);
            $cmd = sprintf(
                "%s -server %s -port %d -share %s -dir %s -domain %s -user %s -password %s -action upload -file %s",
                escapeshellcmd(POSEA_5651_SMB_BIN),
                escapeshellarg($bcfg['smb_server']),
                $port,
                escapeshellarg($bcfg['smb_share']),
                escapeshellarg($bcfg['smb_dir'] ?? ''),
                escapeshellarg($bcfg['smb_domain'] ?? ''),
                escapeshellarg($bcfg['smb_user'] ?? ''),
                escapeshellarg($bcfg['smb_password'] ?? ''),
                escapeshellarg($zip_file)
            );
            $out = trim(shell_exec($cmd) ?? '');
            $parsed = json_decode($out, true);
            if ($parsed && !empty($parsed['success'])) {
                $res = ["success" => true, "message" => "SMB ile yüklendi: {$base_name}"];
            } else {
                $err = $parsed['error'] ?? $out;
                $res = ["success" => false, "message" => "SMB yükleme hatası: {$err}"];
            }
            break;

        case 'nfs':
            $tmp_mnt = "/tmp/posea_nfs_sync_" . uniqid();
            @mkdir($tmp_mnt, 0755, true);
            $ver_opt = ($bcfg['nfs_version'] === '4') ? "-o nfsv4" : "-o nfsv3";
            $cmd = sprintf("/sbin/mount_nfs %s -o retrycnt=1,timeout=10 %s %s 2>&1", $ver_opt, escapeshellarg($bcfg['nfs_server'] . ':' . $bcfg['nfs_export']), escapeshellarg($tmp_mnt));
            exec($cmd, $out, $ret);
            if ($ret === 0) {
                $dst = "{$tmp_mnt}/{$base_name}";
                if (@copy($zip_file, $dst)) {
                    $res = ["success" => true, "message" => "NFS paylaşımına başarıyla kopyalandı: {$base_name}"];
                } else {
                    $res = ["success" => false, "message" => "NFS dizinine kopyalama başarısız oldu."];
                }
                exec("/sbin/umount -f " . escapeshellarg($tmp_mnt));
            } else {
                $res = ["success" => false, "message" => "NFS bağlama hatası: " . implode(" ", $out)];
            }
            @rmdir($tmp_mnt);
            break;

        case 'local':
            $dir = rtrim($bcfg['local_path'] ?? '', '/\\');
            if (is_dir($dir) && is_writable($dir)) {
                $dst = "{$dir}/{$base_name}";
                if (@copy($zip_file, $dst)) {
                    $res = ["success" => true, "message" => "Yerel/iSCSI dizine kopyalandı: {$dst}"];
                } else {
                    $res = ["success" => false, "message" => "Hedef dizine kopyalanamadı: {$dst}"];
                }
            } else {
                $res = ["success" => false, "message" => "Hedef yerel dizin geçersiz veya yazma izni yok: {$dir}"];
            }
            break;

        case 'sftp':
            $port = intval($bcfg['sftp_port'] ?: 22);
            $auth = escapeshellarg($bcfg['sftp_user'] . ':' . $bcfg['sftp_password']);
            $url = escapeshellarg("sftp://" . $bcfg['sftp_server'] . ":" . $port . "/" . ltrim($bcfg['sftp_path'] ?? '', '/') . "/{$base_name}");
            $cmd = "/usr/local/bin/curl -s -k -m 30 -u {$auth} -T " . escapeshellarg($zip_file) . " {$url} 2>&1";
            exec($cmd, $out, $ret);
            if ($ret === 0) {
                $res = ["success" => true, "message" => "SFTP ile aktarıldı: {$base_name}"];
            } else {
                $res = ["success" => false, "message" => "SFTP aktarım hatası: " . implode(" ", $out)];
            }
            break;

        case 'ftp':
            $port = intval($bcfg['ftp_port'] ?: 21);
            $auth = escapeshellarg($bcfg['ftp_user'] . ':' . $bcfg['ftp_password']);
            $url = escapeshellarg("ftp://" . $bcfg['ftp_server'] . ":" . $port . "/" . ltrim($bcfg['ftp_path'] ?? '', '/') . "/{$base_name}");
            $cmd = "/usr/local/bin/curl -s -m 30 -u {$auth} -T " . escapeshellarg($zip_file) . " {$url} 2>&1";
            exec($cmd, $out, $ret);
            if ($ret === 0) {
                $res = ["success" => true, "message" => "FTP ile aktarıldı: {$base_name}"];
            } else {
                $res = ["success" => false, "message" => "FTP aktarım hatası: " . implode(" ", $out)];
            }
            break;
    }

    // Update status in index.json and config
    $bcfg['last_sync_time'] = date("Y-m-d H:i:s");
    $bcfg['last_sync_status'] = $res['success'] ? 'OK' : 'FAIL';
    $bcfg['last_sync_msg'] = $res['message'];
    posea_5651_save_backup_config($bcfg);

    if ($res['success']) {
        $index = posea_5651_get_archives();
        if (isset($index[$date])) {
            $index[$date]['remote_synced'] = true;
            $index[$date]['remote_synced_at'] = date("Y-m-d H:i:s");
            file_put_contents(POSEA_5651_INDEX_FILE, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        posea_5651_log("5651 archive for {$date} synced remotely: {$res['message']}", "INFO");
    } else {
        posea_5651_log("5651 archive sync failed for {$date}: {$res['message']}", "ERROR");
    }

    return $res;
}

/* Upload ALL historical signed archives to remote storage */
function posea_5651_sync_all_archives($bcfg = null) {
    if (!$bcfg) $bcfg = posea_5651_get_backup_config();
    $archives = posea_5651_get_archives();
    $total = count($archives);
    $synced = 0;
    $failed = 0;
    $details = [];

    foreach ($archives as $date => $info) {
        $r = posea_5651_sync_archive($date, $bcfg);
        if ($r['success']) {
            $synced++;
        } else {
            $failed++;
        }
        $details[$date] = $r['message'];
    }

    return [
        "success" => ($failed === 0),
        "total" => $total,
        "synced" => $synced,
        "failed" => $failed,
        "details" => $details
    ];
}

/* Local retention cleanup: deletes local copies older than $days days */
function posea_5651_cleanup_retention($days) {
    if ($days <= 0) return 0;
    $cutoff = time() - ($days * 86400);
    $archives = posea_5651_get_archives();
    $deleted_count = 0;

    foreach ($archives as $date => $info) {
        $dt = strtotime($date);
        if ($dt && $dt < $cutoff) {
            // Delete raw files but preserve entry in index if remote_synced is true
            $base = POSEA_5651_ARCHIVE_DIR . "/5651_{$date}";
            @unlink("{$base}.log");
            @unlink("{$base}.log.gz");
            @unlink("{$base}.sha256");
            @unlink("{$base}.tsr");
            @unlink("{$base}.tsq");
            @unlink("{$base}.zip");
            $deleted_count++;
            posea_5651_log("Retention cleanup: deleted local archive files for {$date}", "INFO");
        }
    }
    return $deleted_count;
}

/* === PACKAGE HOOKS === */

function posea_5651_install() {
    posea_5651_log("Posea 5651 package installed", "INFO");
    posea_5651_resync();
}

function posea_5651_deinstall() {
    posea_5651_log("Posea 5651 package deinstalled", "INFO");
    mwexec("/usr/local/etc/rc.d/posea_5651.sh stop");
}

function posea_5651_resync() {
    global $config;
    $cfg = posea_5651_get_config();
    posea_5651_save_config($cfg);

    if (!empty($cfg['enabled'])) {
        mwexec("/usr/local/etc/rc.d/posea_5651.sh restart");
    } else {
        mwexec("/usr/local/etc/rc.d/posea_5651.sh stop");
    }
}
?>
