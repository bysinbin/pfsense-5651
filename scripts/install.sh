#!/bin/sh
#
# install.sh - 5651 Yasal Loglama & TÜBİTAK Zaman Damgası Kurulum Scripti (pfSense)
#
set -e

echo "=========================================================="
echo "  Posea 5651 Yasal Loglama ve Zaman Damgası Kurulumu"
echo "=========================================================="

SCRIPT_DIR=$(dirname "$0")
BASE_DIR="${SCRIPT_DIR}/.."

# 1. Dizinleri oluştur
echo "[*] Step 1: Dizinler oluşturuluyor..."
mkdir -p /var/etc/posea_5651
mkdir -p /var/log/5651/active
mkdir -p /var/log/5651/archives
mkdir -p /usr/local/pkg
mkdir -p /usr/local/etc/rc.d
mkdir -p /usr/local/www

# 2. Paket dosyalarını kopyala
echo "[*] Step 2: Paket ve WebGUI bileşenleri yükleniyor..."
cp -f "${BASE_DIR}/pfsense-pkg/usr/local/pkg/posea_5651.xml" /usr/local/pkg/
cp -f "${BASE_DIR}/pfsense-pkg/usr/local/pkg/posea_5651.inc" /usr/local/pkg/
cp -f "${BASE_DIR}/pfsense-pkg/usr/local/pkg/posea_5651d.php" /usr/local/pkg/
chmod 755 /usr/local/pkg/posea_5651d.php

cp -f "${BASE_DIR}/pfsense-pkg/etc/rc.d/posea_5651.sh" /usr/local/etc/rc.d/
chmod 755 /usr/local/etc/rc.d/posea_5651.sh

cp -f "${BASE_DIR}/pfsense-pkg/usr/local/www/posea_5651_archives.php" /usr/local/www/
cp -f "${BASE_DIR}/pfsense-pkg/usr/local/www/posea_5651_live.php" /usr/local/www/
cp -f "${BASE_DIR}/pfsense-pkg/usr/local/www/posea_5651_test.php" /usr/local/www/

# 3. pfSense config.xml içine paketi kaydet
echo "[*] Step 3: Paket pfSense config.xml'e kaydediliyor..."
/usr/local/bin/php -r '
require_once("config.inc");
global $config;

$changed = false;

// Register in menu/packages
$found = false;
if (!empty($config["installedpackages"]["package"])) {
    foreach ($config["installedpackages"]["package"] as $pkg) {
        if ($pkg["name"] === "posea5651") {
            $found = true;
            break;
        }
    }
} else {
    $config["installedpackages"]["package"] = array();
}

if (!$found) {
    $config["installedpackages"]["package"][] = array(
        "name" => "posea5651",
        "descr" => "5651 Yasal Loglama ve TUBITAK/RFC 3161 Zaman Damgasi",
        "website" => "https://github.com/bysinbin/pfsense-5651",
        "version" => "1.0.0",
        "status" => "Stable",
        "configurationfile" => "posea_5651.xml"
    );
    $changed = true;
    echo "  -> posea5651 paketi menuye eklendi.\n";
}

// Register default config
if (empty($config["installedpackages"]["posea5651"]["config"])) {
    $config["installedpackages"]["posea5651"] = array(
        "config" => array(
            array(
                "enable" => "on",
                "log_dhcp" => "on",
                "log_nat" => "on",
                "log_filter" => "on",
                "ad_sso_enrich" => "on",
                "tsa_provider" => "freetsa",
                "tsa_url" => "https://freetsa.org/tsr",
                "tsa_hash" => "sha256",
                "retention_days" => "730",
                "sign_time" => "00:01",
                "gzip_compress" => "on"
            )
        )
    );
    $changed = true;
    echo "  -> posea5651 varsayilan yapilandirmasi eklendi.\n";
}

if ($changed) {
    write_config("Posea 5651 paketi pfSense sistemine kaydedildi.");
    echo "  -> config.xml guncellendi.\n";
} else {
    echo "  -> posea5651 zaten config.xml icinde kayitli.\n";
}
'

# 4. Cache temizle
rm -f /tmp/config.cache

# 5. Servisi Başlat
echo "[*] Step 4: Posea 5651 Servisi Başlatılıyor..."
/usr/local/etc/rc.d/posea_5651.sh restart

echo "=========================================================="
echo "  [BAŞARILI] 5651 Yasal Loglama Paketi Kuruldu!"
echo "  Erişim: pfSense WebGUI > Services > 5651 Yasal Loglama"
echo "=========================================================="
