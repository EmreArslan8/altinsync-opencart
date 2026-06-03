# AltınSync — OpenCart Otomatik Altın Fiyat Güncelleme Eklentisi

> Altın takı satan OpenCart mağazalarında ürün fiyatlarını **canlı gram altın kuruna göre, her 5 dakikada bir otomatik** güncelleyen eklenti.

**🔗 Canlı Demo:** https://emrearslan8.github.io/altinsync-opencart/
**📦 Kurulabilir paket:** [`altinsync.ocmod.zip`](altinsync.ocmod.zip)

---

## Problem

Bir kuyumcu/altın takı e-ticaret sitesinde fiyatlar sabit kalamaz; gram altın kuru gün içinde sürekli değişir. Her ürünün fiyatını elle güncellemek imkânsızdır. İhtiyaç: **fiyatların güncel altın kuruna göre otomatik güncellenmesi.**

## Çözüm

OpenCart için, altına bağlı ürünlerin fiyatını şu formülle hesaplayıp düzenli olarak güncelleyen bir modül:

```
Satış Fiyatı = (gram × milyem × canlı gram altın) + (gram × işçilik) → × (1 + kâr%) → (opsiyonel × KDV)
```

- **Canlı kur:** [Truncgil](https://finans.truncgil.com) finans API'sinden gram has altın fiyatı (sunucu tarafında çekilir, CORS sorunu yok).
- **Otomatik güncelleme:** Cron her 5 dakikada (ayarlanabilir) tüm `is_gold` ürünlerin fiyatını yeniden hesaplar ve `oc_product` tablosuna yazar.
- **Dayanıklılık:** API çökerse son geçerli fiyatı kullanır (fallback), eşzamanlı çalışmayı kilitle önler, her işlemi günlüğe yazar.
- **Yönetim paneli:** canlı fiyat & durum, "Şimdi Güncelle" butonu, kâr marjı / işçilik / KDV / aralık ayarları, ürün listesi.

## Ekran

Admin panelinde **Eklentiler → Modüller → AltınSync** altında: canlı gram fiyatı, geri sayım, ürün tablosu (otomatik hesaplanan fiyatlar) ve manuel güncelleme butonu yer alır.

---

## Kurulum (OpenCart 4.x)

1. Admin → **Extensions → Installer** → `altinsync.ocmod.zip` dosyasını yükle.
2. Admin → **Extensions → Extensions → Modules** → **AltınSync**'i bul → **+ (Install)**.
   (Bu adım gerekli tabloları oluşturur ve izinleri ekler.)
3. **AltınSync**'i düzenle → kâr marjı, işçilik, KDV ve güncelleme aralığını ayarla → **Kaydet**.
4. Ürünlerin gram/milyem/işçilik değerlerini `oc_altinsync` tablosuna gir (veya kendi ürün alanlarınla eşle).
5. Sunucuda cron kur:

```bash
*/5 * * * * php /path/to/store/extension/altinsync/altinsync_cron.php >> /var/log/altinsync.log 2>&1
```

> "Şimdi Güncelle" butonu cron olmadan da elle çalıştırma imkânı verir.

## Dosya Yapısı

```
src/
├── install.json                         # paket meta verisi
├── altinsync.lib.php                    # çekirdek: kur çekme + fiyat formülü + güncelleme (cron ile paylaşılır)
├── altinsync_cron.php                   # 5 dk'lık cron — config.php'yi otomatik bulur
└── admin/
    ├── controller/module/altinsync.php  # ayarlar + "Şimdi Güncelle" + install()/uninstall()
    ├── view/template/module/altinsync.twig
    └── language/en-gb/module/altinsync.php
docs/index.html                          # GitHub Pages canlı demo (gerçek kuru tarayıcıdan çeker)
altinsync.ocmod.zip                      # kurulabilir paket
```

## Teknik Notlar

- **Uyumluluk:** OpenCart 4.1.x üzerinde geliştirildi ve test edildi (3.x'e uyarlanabilir).
- **Veritabanı:** `oc_altinsync` (gram/milyem/işçilik), `oc_altinsync_log` (senkron geçmişi). Modül kurulumunda otomatik oluşturulur.
- **Ayarlar** `oc_setting` içinde JSON olarak saklanır; cron ve admin aynı kaynağı okur.
- **Para birimi:** Mağaza para birimi TRY (₺) olmalıdır.
- Üretimde ücretsiz API yerine SLA'li bir kaynak (Harem Altın / Altınkaynak) önerilir.

---

_Bu repo, "altın takı sitesinde fiyatların 5 dakikada bir otomatik güncellenmesi" iş tanımına yönelik, uçtan uca çalışan bir OpenCart eklentisi örneğidir._
