<p align="center"><img src="public/assets/lunixi.svg" width="240" alt="Lunixi"></p>

# CodeIgniter 4 ödeme örneği

[English](README.md) · [Rehber](https://docs.lunixi.com/tr/sdks/codeigniter) · [Kaynak](https://github.com/Lunixi-Payment/lunixi-php/tree/main/samples/codeigniter)

**CodeIgniter 4.7.5 / PHP 8.2+** üzerinde çalışan, **`lunixi/php-sdk:^0.29.5`** kullanan uygulama örneği. Yerel oturum/parola özeti, CSRF ve sipariş sahipliği, yönlendirmeli/gömülü Lunixi formu, ham imzalı webhook, gerçek SQLite transaction ve şifreli kalıcı ödeme olay kuyruğu içerir. Mevcut PHP SDK reposunun örneğidir; ayrı paket veya Bundle değildir.

İki açıkça oluşturulan deneme hesabının ayrı sunucu tarafı 19,90 TRY siparişi bulunur. Tarayıcıdan tutar alınmaz, kart verisi toplanmaz, otomatik sahte ödeme modu açılmaz. Çoklu kart, taksit, alternatif yöntemler ve sigorta Lunixi form ayarlarındadır. İmzalama, para ve tahsilat kanıtı ortak SDK'dadır.

## Çalıştırma

64 bit PHP 8.2+, Composer, Intl, Mbstring, Sodium, cURL, JSON ve SQLite3 gerekir. Resmi App Starter 4.7.5 iskeletinin MIT atfı korunmuştur.

```bash
composer install
cp .env.example .env
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Yalnız bu örneğe ait, Git dışında kalan `.env` dosyasını doldurun; üst dizinin ortam dosyası yüklenmez:

| Değişken | Değer |
| --- | --- |
| `LUNIXI_MERCHANT_ID` | Kayıtlı üye işyeri kimliği |
| `LUNIXI_ENVIRONMENT` | Anahtar/webhook ile aynı `TEST` veya `LIVE` |
| `LUNIXI_KEY_ID`, `LUNIXI_PRIVATE_KEY` | Kayıtlı sunucu imzalama bilgileri |
| `LUNIXI_WEBHOOK_SECRET` | Webhook sırrı |
| `LUNIXI_ENCRYPTION_KEY` | Yukarıda üretilen ayrı 32 bayt anahtarın base64 karşılığı |
| `LUNIXI_APP_URL` | Dönüş/webhook için herkese açık HTTPS kök adres |
| `LUNIXI_CHECKOUT_MODE` | `embedded` (varsayılan) veya isteğe bağlı `hosted` |
| `LUNIXI_PREAUTH` | Ön provizyon için `true`, diğer durumda `false` |
| `DEMO_ALICE_PASSWORD`, `DEMO_BOB_PASSWORD` | Ayrı, en az 16 karakterlik güçlü parolalar |

Şifreleme anahtarını ayrı saklayıp yedekleyin, güncellemede değiştirmeyin; API/webhook/çerez sırrından türetmeyin. `.env`, anahtar, SQLite dosyası ve günlükleri Git'e eklemeyin.

```bash
php spark lunixi:setup
php spark serve --host 127.0.0.1 --port 8080
```

`http://localhost:8080/login` adresinde `alice@example.test` veya `bob@example.test` ve sizin belirlediğiniz parolayla oturum açın. Kullanıcı/sipariş tabloları yalnız açık kurulum komutuyla oluşturulur; HTTP yolu DDL çalıştırmaz. Varsayılan veritabanı `public/` dışında `writable/lunixi.sqlite` dosyasıdır. `LUNIXI_DATABASE` başka özel SQLite dosyası seçebilir.

Gerçek TEST ödemesi için TLS tüneli/HTTPS sunucusu kullanın; `app.baseURL` ve `LUNIXI_APP_URL` değerlerini güncelleyin, Lunixi'ye **`https://adresiniz/webhooks/lunixi`** kaydedin. SDK dönüş adresinde HTTPS zorunludur. Yayında web kökünü `public/` seçin; HTTPS ve güvenli çerezi açın (`app.forceGlobalSecureRequests=true`, `cookie.secure=true`), debug kapalı tutun ve host/proxy politikanızı uygulayın.

## Güvenlik ve durumlar

- Oturum açma yerel parola özeti, CSRF ve hız sınırını kullanır; otomatik rota kapalıdır.
- `POST /payments/{id}` oturum, CSRF ve sunucu sipariş sahibini doğrular. İstemci tutarı alınmaz.
- `GET /payments/{id}/return` yalnız sahibine mevcut durumu gösterir; “başarılı” parametresi ödeme kaydetmez.
- Tam `POST /payments/{id}/return` yolu en fazla 64 KiB bilgi dönüşünü alır; hiçbir form alanını yorumlamadan aynı origin üzerindeki GET adresine sabit 303 verir. Oturum/sipariş sorgusu, veritabanı yazımı ve gateway çağrısı yoktur. Bilinmeyen sipariş de aynı yönlendirmeyi alır.
- CSRF istisnaları yalnız bu bilgi dönüş POST'u ve `POST /webhooks/lunixi` yoludur. Webhook için ham bayt v2 HMAC, zaman, imzalı olay kimliği/türü/ortamı ve kalıcı tekrar/içerik çakışması kontrolü zorunludur.
- Kimliği doğrulanmış GET; üye işyeri, ödeme/sipariş kimliği, para birimi, tutar ve tam çoklu kart ana ödeme toplamını doğrular.

Cross-site form POST sırasında SameSite=Lax çerezi gelmeyebilir; 303 sonrası GET normal oturum ve sahiplik kontrolünü uygular. Form dönüş imzası webhook sırrıyla doğrulanmaz. Reverse proxy üzerinde de gövde sınırı koyun; PHP uygulama kontrolünden önce girdiyi belleğe alabilir.

API kökü sabit `https://api-gateway.lunixi.com` adresidir. Gerçek TEST/LIVE erişimini anahtar belirler, isteğe uydurma ortam alanı eklenmez. Varsayılan gömülü form kendi sitenizde açılır. İsteğe bağlı yönlendirmede backend yanıtındaki doğrulanmış `paymentPageUrl` kullanılır ve denemede şifreli saklanır; tarayıcı token’ından URL üretilmez. Gömülü form resmi `https://cdn.lunixi.com/lunixi-checkout.min.js` dosyasını ve güvenli JSON'u kullanır; API'den gelen HTML basılmaz. CSP izinlerini resmi form gereksinimleriyle düzenleyin.

Rezervasyon ağ çağrısından önce kalıcıdır; tarayıcı tokenı ayrı anahtarla şifrelidir. Yanıt kaybında aynı istek/anahtar korunur. Gateway idempotency önbelleği 24 saattir; SDK 0.29.3 temkinli **23 saatlik yeniden gönderim sınırı** uygular. Eski belirsiz işlem yeni anahtarla tahsil edilmez; GET/webhook uzlaştırması açık kalır. Kaybolan ilk yanıt, imzalı ve opak asıl deneme metadata kanıtıyla kurtarılabilir.

Sipariş, olay tüketimi ve şifreli outbox **aynı CodeIgniter SQLite3 bağlantısı/transaction derinliği** içinde yazılır; ayrı PDO transaction açılmaz. İlk SQL yazımı okumadan önce yazma kilidini alır. Yerel hata hepsini geri alır; CI'nin strict transaction hata durumu yalnız modülün geri alması bittikten sonra sıfırlanır. İç içe/dış transaction reddedilir.

Provizyon/kısmi tahsilat bekler. Kesin sonuç kanıtı olmayan `FAILED` incelemede kalır; yeni anahtar açılmaz. İptal, değişmiş sipariş veya üye işyeri/ortam değişikliği de kontrol ister. Anapara; taksit ek bedeli dahil brüt tahsilat/iade bakiyesinden ayrı tutulur. `order.paid` ancak tam tahsilat sonrası bir kez şifreli SQL kuyruğuna yazılır.

## Uzlaştırma ve uygulama işleri

```bash
php spark lunixi:reconcile
```

Komutu zamanlayarak eksik webhookları doğrulanmış GET ile uzlaştırın. Hata durumunda sır yazdırmadan sıfırdan farklı çıkış kodu döner. Mali geçmiş/anahtarlar otomatik silinmez.

Örnek yerel sipariş ve ödeme olayını kaydeder; uygulamanızın stok, e-posta, ürün/KDV veya teslimat kurallarını uydurmaz. Commit sonrası dayanıklı outbox tüketicinizi bağlayın; güncel sipariş/bekletme durumunu kontrol edin ve belirsiz harici sonuçları kontrol için bekletin. Tahsilat/iade/iptal yönetimi gerçek uygulama işlem kimlikleriyle SDK'nın kalıcı operasyon API'sine bağlanabilir; yetkisiz örnek yönetim rotası sunulmaz.

Çalışan veri deposu açıkça **SQLite3** kapsamındadır. Başka veritabanı için aynı yerel sipariş bağlantısı/transaction API'sini kullanan `LedgerStore` adaptörünü gerçek testlerle uygulayın.

## Kontroller

```bash
php vendor/bin/phpunit -c phpunit.xml
python3 tests/http-smoke.py
php vendor/bin/phpstan analyse -c phpstan.neon --no-progress
php vendor/bin/phpcs --standard=phpcs.xml
composer audit
```

Testler gerçek CodeIgniter rotalarını/güvenliğini ve SQLite'ı açar. HTTP smoke ayrı gerçek PHP/CodeIgniter sunucusunda oturum çerezi yenileme, eksik CSRF, sahiplik, HTTP yöntemi, oturumsuz cross-site POST → 303 → korumalı GET ve sahte dönüşü test eder. Çevrimdışı saklanan yönlendirmeli/gömülü denemelerde gerçek `no-store`, `no-referrer` ve yerel güvenlik başlıkları da doğrulanır; Lunixi ödeme isteği gönderilmez. Uygulama filtresi framework korumalarını sürdürür, yalnız referrer politikasını değiştirir. SDK yanıtları kontrollü HTTP taşımasıyla üretilirken kripto/veritabanı gerçektir. SQL tetikleyici geri alma/tekrar, imza/ortam, kesin para, kayıp yanıt, 24 saat sınırı ve FAILED inceleme testleri bulunur.

Yayından önce HTTPS uygulamanızda kayıtlı TEST üye işyeriyle gerçek form/webhook akışını tamamlayın. [SDK güvenlik politikası](../../SECURITY.md) ve [resmi CodeIgniter rehberi](https://codeigniter.com/user_guide/) ek kaynaklardır.
