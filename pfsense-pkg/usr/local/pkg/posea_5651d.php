<?php
/*
 * posea_5651d.php - 5651 Yasal Loglama Arka Plan Servisi
 *
 * pfSense üzerinde DHCP, NAT ve PoseaFilter web filtre loglarını dinler,
 * AD SSO kimlikleriyle zenginleştirerek BTK uyumlu 5651 log akışı oluşturur.
 * Her gece yarısı günü kapatıp TÜBİTAK/RFC 3161 Zaman Damgası ile mühürler.
 */

declare(ticks = 1);
require_once("/usr/local/pkg/posea_5651.inc");

$running = true;

function sig_handler($signo) {
    global $running;
    posea_5651_log("Received signal {$signo}, stopping 5651 daemon...", "INFO");
    $running = false;
}

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'sig_handler');
    pcntl_signal(SIGINT, 'sig_handler');
}

file_put_contents(POSEA_5651_PID, getmypid());
posea_5651_log("Posea 5651 Daemon v1.0.0 started (PID: " . getmypid() . ")", "INFO");

$filter_log_file = "/var/log/poseafilter/filter.log";
$filter_log_pos = 0;
if (file_exists($filter_log_file)) {
    $filter_log_pos = filesize($filter_log_file); // Start at current end
}

$last_dhcp_check = 0;
$seen_leases = [];
$current_date = date("Y-m-d");

while ($running) {
    $now = time();
    $today = date("Y-m-d");
    $cfg = posea_5651_get_config();

    if (empty($cfg['enabled'])) {
        sleep(5);
        continue;
    }

    // --- 1. GECE YARISI GÜN KAPATMA VE ZAMAN DAMGASI İMZALAMA ---
    if ($today !== $current_date) {
        $yesterday = $current_date;
        $current_date = $today;
        posea_5651_log("Day rollover detected: Finalizing and signing 5651 log for {$yesterday}...", "INFO");
        posea_5651_sign_daily_log($yesterday);
    }

    // --- 2. POSEAFILTER WEB / DNS LOGLARINI TOPLAMA VE AD SSO İLE BİRLEŞTİRME ---
    if (!empty($cfg['log_filter']) && file_exists($filter_log_file)) {
        clearstatcache(false, $filter_log_file);
        $curr_size = filesize($filter_log_file);

        if ($curr_size < $filter_log_pos) {
            // File rotated
            $filter_log_pos = 0;
        }

        if ($curr_size > $filter_log_pos) {
            $fp = @fopen($filter_log_file, "r");
            if ($fp) {
                fseek($fp, $filter_log_pos);

                // Load AD Users cache
                $ad_users = [];
                $ad_user_file = "/var/etc/poseafilter/ad_users.json";
                if (file_exists($ad_user_file)) {
                    $ad_users = json_decode(file_get_contents($ad_user_file), true) ?: [];
                }

                $active_file = posea_5651_get_active_file($current_date);
                $out_fp = @fopen($active_file, "a");

                while (($line = fgets($fp)) !== false) {
                    $line = trim($line);
                    if (empty($line)) continue;

                    // Parse line: [2026-10-22T21:40:00] [DNS_BLOCK] IP: 192.168.2.170 | Domain: radio.stream.com | Action: SINKHOLE | Reason: Streaming & Online Radio
                    $ts = date("Y-m-d H:i:s");
                    $client_ip = "";
                    $domain = "";
                    $action = "PASS";
                    $reason = "";

                    if (preg_match('/\[([^\]]+)\]\s+\[([^\]]+)\]\s+IP:\s*([\d\.]+)\s*\|\s*Domain:\s*([^\|]+)\s*\|\s*Action:\s*([^\|]+)\s*\|\s*Reason:\s*(.*)/i', $line, $m)) {
                        $ts = str_replace('T', ' ', $m[1]);
                        $client_ip = trim($m[3]);
                        $domain = trim($m[4]);
                        $action = trim($m[5]);
                        $reason = trim($m[6]);
                    }

                    if ($client_ip) {
                        $user_name = $ad_users[$client_ip]['username'] ?? "-";
                        $mac = $ad_users[$client_ip]['mac'] ?? "-";

                        $formatted = posea_5651_format_row(
                            $ts,
                            $mac,
                            $client_ip,
                            "53",
                            $user_name,
                            $domain,
                            "53",
                            "-",
                            "-",
                            "UDP",
                            $action,
                            $reason
                        );

                        if ($out_fp) {
                            fwrite($out_fp, $formatted);
                        }
                    }
                }

                $filter_log_pos = ftell($fp);
                fclose($fp);
                if ($out_fp) fclose($out_fp);
            }
        }
    }

    // --- 3. DHCP KİRA LOGLARINI PERİYODİK TOPLAMA ---
    if (!empty($cfg['log_dhcp']) && ($now - $last_dhcp_check >= 30)) {
        $last_dhcp_check = $now;
        $lease_file = "/var/dhcpd/var/db/dhcpd.leases";
        if (file_exists($lease_file)) {
            $lease_content = @file_get_contents($lease_file);
            $blocks = explode("lease ", $lease_content);
            $active_file = posea_5651_get_active_file($current_date);
            $out_fp = @fopen($active_file, "a");

            foreach ($blocks as $blk) {
                if (preg_match('/^([\d\.]+)\s*\{/s', $blk, $m_ip)) {
                    $ip = $m_ip[1];
                    $mac = "";
                    $hostname = "";
                    $starts = "";

                    if (preg_match('/hardware ethernet\s+([a-f0-9:]{17});/i', $blk, $m_m)) $mac = strtolower($m_m[1]);
                    if (preg_match('/client-hostname\s+"([^"]+)";/i', $blk, $m_h)) $hostname = trim($m_h[1]);
                    if (preg_match('/starts\s+\d+\s+([0-9\/\s:]+);/i', $blk, $m_s)) $starts = trim($m_s[1]);

                    $lease_key = "{$ip}_{$mac}_{$starts}";
                    if ($mac && !isset($seen_leases[$lease_key])) {
                        $seen_leases[$lease_key] = true;

                        if ($out_fp) {
                            $row = posea_5651_format_row(
                                date("Y-m-d H:i:s"),
                                $mac,
                                $ip,
                                "67",
                                $hostname ?: "-",
                                "255.255.255.255",
                                "68",
                                "-",
                                "-",
                                "UDP",
                                "DHCP_ASSIGN",
                                "DHCP Kira Ataması: Hostname=" . ($hostname ?: 'N/A')
                            );
                            fwrite($out_fp, $row);
                        }
                    }
                }
            }
            if ($out_fp) fclose($out_fp);
        }
    }

    sleep(1);
}

@unlink(POSEA_5651_PID);
posea_5651_log("Posea 5651 Daemon terminated cleanly.", "INFO");
exit(0);
?>
