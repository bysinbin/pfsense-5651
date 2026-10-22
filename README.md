# Posea 5651 - pfSense 5651 Yasal Loglama ve TÜBİTAK/RFC 3161 Zaman Damgası Paketi

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![pfSense](https://img.shields.io/badge/pfSense-Plus%20%2F%20CE-red.svg)](https://www.pfsense.org/)
[![RFC 3161](https://img.shields.io/badge/Zaman%20Damgası-RFC%203161-green.svg)](https://www.ietf.org/rfc/rfc3161.txt)

Türkiye Cumhuriyeti **5651 Sayılı Kanun** ve BTK (Bilgi Teknolojileri ve İletişim Kurumu) yönetmeliklerine %100 uyumlu, pfSense güvenlik duvarı için geliştirilmiş yerli **Yasal Loglama ve Nitelikli Zaman Damgası** entegrasyon paketidir.

---

## 🚀 Özellikler

- **Tekil Yasal IPDR Akışı**:
  - İç IP & Port, Dış NAT IP & Port, Hedef IP & Port, Protokol (TCP/UDP).
  - MAC Adresi ve DHCP Kira Başlangıç/Bitiş kayıtları.
  - Active Directory SSO (Single Sign-On) kullanıcı adı entegrasyonu (`sener\kadir`, `akdoqan` vb.).
  - PoseaFilter DNS & URL erişim ve engelleme kararları (`ALLOW` / `BLOCK` / `SINKHOLE`).
- **Nitelikli Kriptografik Zaman Damgası (RFC 3161)**:
  - Günlük log dosyaları her gece yarısı (00:01) otomatik kapatılır.
  - Dosyanın **SHA-256** özeti hesaplanır.
  - **TÜBİTAK KamuSM**, **Türktrust**, **E-Tuğra** veya test TSA sunucularına HTTP(S) TimeStampReq (`.tsq`) gönderilerek dijital mühür (`.tsr`) alınır.
- **pfSense WebGUI Entegrasyonu (`Services > 5651 Yasal Loglama`)**:
  - **Genel Ayarlar**: TSA URL'si, kullanıcı/parola, özet algoritması, saklama süresi.
  - **İmzalı Günlük Arşivler**: Geçmiş günlerin logları, SHA-256 özetleri, mühür durumu, tek tıkla `.log.gz`, `.sha256`, `.tsr` indirme ve anlık **Doğrula (Verify)** butonu.
  - **Canlı Yasal Log İzleyici**: AD kullanıcı adlarıyla canlı akan yasal log akışı.
  - **Zaman Damgası (TSA) Testi**: TSA sunucusunu anlık test eden görsel teşhis aracı.
- **Yasal Saklama Süresi**:
  - Kanunun zorunlu kıldığı en az 2 yıl (730 gün) boyunca diskte güvenli saklama ve otomatik temizlik.

---

## 📦 Kurulum (pfSense Üzerinde)

pfSense SSH veya konsolundan tek komutla kurabilirsiniz:

```sh
fetch -o - https://raw.githubusercontent.com/bysinbin/pfsense-5651/main/scripts/install.sh | sh
```

Kurulum tamamlandığında pfSense Web arayüzünde **Services > 5651 Yasal Loglama** menüsü otomatik olarak belirecektir.

---

## 📜 Lisans

Bu proje MIT lisansı altında dağıtılmaktadır.
