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
cp -f "${BASE_DIR}/pfsense-pkg/usr/local/www/posea_5651_backup.php" /usr/local/www/

if [ -f "${BASE_DIR}/pfsense-pkg/usr/local/bin/posea-smb-sync" ]; then
    cp -f "${BASE_DIR}/pfsense-pkg/usr/local/bin/posea-smb-sync" /usr/local/bin/
    chmod 755 /usr/local/bin/posea-smb-sync
fi

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
    echo "  -> posea5651 paketi paket listesine eklendi.\n";
}

// Register in menu
$menus = config_get_path("installedpackages/menu", []);
$menu_found = false;
foreach ($menus as $m) {
    if (($m["name"] ?? "") === "5651 Yasal Loglama" || ($m["configfile"] ?? "") === "posea_5651.xml") {
        $menu_found = true;
        break;
    }
}
if (!$menu_found) {
    $menus[] = array(
        "name" => "5651 Yasal Loglama",
        "section" => "Services",
        "configfile" => "posea_5651.xml",
        "url" => "/pkg_edit.php?xml=posea_5651.xml"
    );
    config_set_path("installedpackages/menu", $menus);
    $changed = true;
    echo "  -> 5651 Yasal Loglama menuye eklendi.\n";
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

# 4. FreeBSD pkg veritabanına kaydet (System > Package Manager)
echo "[*] Step 4: FreeBSD Paket Yöneticisine kaydediliyor..."
cat << 'EOF' > /tmp/manifest_posea_5651.ucl
name: "pfSense-pkg-Posea5651"
origin: "security/pfSense-pkg-Posea5651"
version: "1.0.0"
comment: "5651 Yasal Loglama ve TUBITAK/RFC 3161 Zaman Damgasi"
maintainer: "bysinbin"
www: "https://github.com/bysinbin/pfsense-5651"
abi: "FreeBSD:15:amd64"
arch: "freebsd:15:x86:64"
prefix: "/usr/local"
flatsize: 256000
desc: "5651 sayili kanun uyumlu IPDR, DHCP, NAT ve AD SSO oturum loglama paketi. Gunluk SHA-256 ozeti cikarir ve TUBITAK KamuSM / RFC 3161 Zaman Damgasi ile muhurler."
categories: [
    "security"
]
annotations: {
    FreeBSD_version: "1500029",
    repository: "pfSense"
}
files: {
    /usr/local/pkg/posea_5651d.php: "-"
}
EOF
/usr/local/sbin/pkg-static register -t -M /tmp/manifest_posea_5651.ucl 2>/dev/null || true
rm -f /tmp/manifest_posea_5651.ucl

# 5. Cache temizle
rm -f /tmp/config.cache /tmp/pkg_cache.json

# 6. Servisi Başlat
echo "[*] Step 5: Posea 5651 Servisi Başlatılıyor..."
/usr/local/etc/rc.d/posea_5651.sh restart

echo "=========================================================="
echo "  [BAŞARILI] 5651 Yasal Loglama Paketi Kuruldu!"
echo "  Erişim: pfSense WebGUI > Services > 5651 Yasal Loglama"
echo "=========================================================="
