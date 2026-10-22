<?php
/*
 * posea_5651.inc - 5651 Yasal Loglama ve TÜBİTAK/RFC 3161 Zaman Damgası Kütüphanesi
 *
 * pfSense üzerinde 5651 sayılı kanun uyumlu IPDR, DHCP, NAT ve AD SSO oturum
 * loglarını toplar, günlük SHA-256 kriptografik özeti çıkarır ve RFC 3161
 * Zaman Damgası (TÜBİTAK KamuSM / Türktrust / E-Tuğra / FreeTSA) ile mühürler.
 */

require_once("config.inc");
require_once("util.inc");
require_once("pfsense-utils.inc");
require_once("service-utils.inc");

define('POSEA_5651_CONF_DIR', '/var/etc/posea_5651');
define('POSEA_5651_CONF', POSEA_5651_CONF_DIR . '/config.json');
define('POSEA_5651_LOG_DIR', '/var/log/5651');
define('POSEA_5651_ACTIVE_DIR', POSEA_5651_LOG_DIR . '/active');
define('POSEA_5651_ARCHIVE_DIR', POSEA_5651_LOG_DIR . '/archives');
define('POSEA_5651_INDEX_FILE', POSEA_5651_ARCHIVE_DIR . '/index.json');
define('POSEA_5651_DAEMON_LOG', '/var/log/posea_5651.log');
define('POSEA_5651_PID', '/var/run/posea_5651.pid');
define('POSEA_5651_RC', '/usr/local/etc/rc.d/posea_5651.sh');

@mkdir(POSEA_5651_CONF_DIR, 0755, true);
@mkdir(POSEA_5651_ACTIVE_DIR, 0755, true);
@mkdir(POSEA_5651_ARCHIVE_DIR, 0755, true);

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
        "signed_at" => date("Y-m-d H:i:s")
    ];
    file_put_contents(POSEA_5651_INDEX_FILE, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    posea_5651_log("Daily 5651 log signed successfully for {$date} (Lines: {$line_count}, TSA: " . ($is_granted ? 'OK' : 'FAIL') . ")", "INFO");
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
        // Temporarily gunzip or test against gz
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

    // OpenSSL TS request
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
