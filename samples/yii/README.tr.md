<p align="center"><img src="web/assets/lunixi.svg" width="240" alt="Lunixi"></p>

# Yii 2 ödeme örneği

[English](README.md) · [Rehber](https://docs.lunixi.com/tr/sdks/yii) · [Kaynak](https://github.com/Lunixi-Payment/lunixi-php/tree/main/samples/yii)

**Yii 2.0.55 / PHP 8.2+** üzerinde çalışan web/konsol örneği; **`lunixi/php-sdk:^0.29.5`** kullanır. Yerel IdentityInterface oturumu, parola özeti, CSRF ve sipariş sahipliğini gömülü/yönlendirmeli Lunixi formuna bağlar. Ham imzalı webhook, doğrulanmış tahsilat ve native SQLite transaction ile sipariş, olay kaydı ve şifreli outbox birlikte yazılır. Mevcut PHP SDK reposundaki kaynak örneğidir; ayrı Yii eklentisi değildir.

İki açıkça oluşturulan deneme hesabının ayrı sunucu tarafı 19,90 TRY siparişi bulunur. İstemci tutarı ödeme kanıtı değildir; kart verisi toplanmaz ve otomatik sahte ödeme açılmaz. Yöntemler, çoklu kart, taksit ve sigorta Lunixi panelindedir. İmzalama, integer para ve güvenli tekrar ortak SDK'dadır.

## Kurulum

64 bit PHP 8.2+, Composer, PDO SQLite, Sodium, cURL ve JSON gerekir. Resmi Yii temel iskeletinin BSD-3-Clause atfı korunur. Kullanılmayan mail/debug/generator/demo rotaları kaldırılmıştır. Düz HTML uygulaması Bower/jQuery kullanmaz; Composer asset replace ayarı resmi Yii rehberindeki seçenektir.

```bash
composer install
cp env.example .env
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Kaynak dışında tutulan bu örneğe ait `.env` **kabuk ortam dosyasını** doldurun. Üst örnek dizininin ortamı okunmaz:

| Değişken | Değer |
| --- | --- |
| `LUNIXI_MERCHANT_ID` | Kayıtlı üye işyeri kimliği |
| `LUNIXI_ENVIRONMENT` | Anahtar/webhook ile eşleşen `TEST` veya `LIVE` |
| `LUNIXI_KEY_ID`, `LUNIXI_PRIVATE_KEY` | Kayıtlı Ed25519 sunucu bilgileri |
| `LUNIXI_WEBHOOK_SECRET` | Webhook imzalama sırrı |
| `LUNIXI_ENCRYPTION_KEY` | İlk komuttan ayrı 32 bayt anahtarın base64 karşılığı |
| `YII_COOKIE_KEY` | İkinci komuttan farklı, ayrı çerez doğrulama anahtarı |
| `LUNIXI_APP_URL` | Herkese açık HTTPS kök adres |
| `LUNIXI_CHECKOUT_MODE` | `embedded` (varsayılan) veya isteğe bağlı `hosted` |
| `LUNIXI_PREAUTH` | Ön provizyon için `true`, diğer durumda `false` |
| `DEMO_ALICE_PASSWORD`, `DEMO_BOB_PASSWORD` | Ayrı, en az 16 karakterlik güçlü parolalar |
| `APP_LANGUAGE` | `en` veya `tr` |

```bash
set -a
. ./.env
set +a
php yii lunixi/setup
php yii serve --port=8081
```

`http://localhost:8081/login` üzerinde `alice@example.test` / `bob@example.test` ve sizin parolanızla oturum açın. Tablo/kullanıcı/siparişler yalnız açık konsol kurulumuyla oluşturulur; HTTP yolunda DDL yoktur. Varsayılan veritabanı `web/` dışında `runtime/lunixi.sqlite` dosyasıdır; `LUNIXI_DATABASE` başka özel SQLite dosyası seçebilir. Anahtarlar bağımsız ve kalıcı olmalı; sır, SQLite, oturum ve günlük dosyaları Git'e girmez.

Örnek dosya yalnız loopback geliştirmesi için `APP_ENV=dev`, `YII_LOCAL_HTTP=1` seçer. Gerçek TEST ödemesinde HTTPS/TLS tüneli ve Lunixi'de kayıtlı **`https://adresiniz/webhooks/lunixi`** gerekir. `LUNIXI_APP_URL` değerini değiştirin. Yayında document root `web/`, `APP_ENV=prod`, `YII_LOCAL_HTTP=0`, HTTPS/güvenli çerez ve host/proxy politikanız kullanılmalı; debug kapalı kalmalıdır. Şifreleme anahtarını çerez/API/webhook/veritabanı sırrından türetmeyin.

## Yerel güvenlik sınırları

- Oturum açma; Yii CSRF, parola özeti ve atomik SQL hız sınırını kullanır. Oturum kimliği girişte yenilenir, IdentityInterface SQL'den çözülür.
- `POST /payments/{id}` AccessControl, VerbFilter, CSRF ve sunucu sipariş sahibini kontrol eder. İstemci tutarı kullanılmaz.
- `GET /payments/{id}/return` yalnız sahibine kayıtlı durumu gösterir; ödeme kimliği/tutar/durum parametreleri etkisizdir.
- Tam `POST /payments/{id}/return` yolu en fazla 64 KiB bilgi dönüşünü alır; alanları yorumlamadan aynı origin GET adresine sabit 303 verir. Oturum/sipariş sorgusu, veritabanı yazımı veya gateway çağrısı yoktur; bilinmeyen sipariş de aynı yanıtı alır.
- Yalnız bu bilgi dönüş POST'u ve ayrı webhook POST'u CSRF'den muaftır. Webhook için ham bayt v2 HMAC, zaman, imzalı olay kimliği/türü/ortamı ve kalıcı tekrar/içerik çakışması kontrolü zorunludur.
- Kimliği doğrulanmış GET; üye işyeri, sipariş/ödeme, para birimi, tutar ve çoklu kartın tam/dengeli ana ödeme toplamını kanıtlar.

Cross-site form POST sırasında SameSite=Lax çerezi gelmeyebilir; 303 sonrası GET normal oturum ve sahiplik kontrolünü korur. Dönüş formu webhook sırrıyla doğrulanmaz. Proxy gövde sınırı da uygulayın; PHP uygulama kontrolünden önce girdiyi belleğe alabilir. Ödeme başlatma/giriş/çıkış CSRF korumasını sürdürür.

API adresi sabit `https://api-gateway.lunixi.com` adresidir; gerçek ortamı anahtar belirler, uydurma ortam parametresi eklenmez. Varsayılan gömülü form kendi sitenizde açılır. İsteğe bağlı yönlendirmede backend yanıtındaki doğrulanmış `paymentPageUrl` kullanılır ve denemede şifreli saklanır; tarayıcı token’ından URL üretilmez. Gömülü mod sabit `https://cdn.lunixi.com/lunixi-checkout.min.js` ve güvenli JSON kullanır; gateway HTML'i çalıştırılmaz. Resmi formun CSP origin izinlerini tanımlayın.

## Kalıcılık ve para doğruluğu

Ödeme rezervasyonu ağ çağrısından önce kaydedilir. Tarayıcı tokenı ve outbox ayrı anahtarla kimliği doğrulanmış şifrelidir. Zaman aşımı aynı istek/anahtarla sürer; belirsiz sonuç otomatik anahtar yenilemez. SDK 0.29.3, gateway'in 24 saatlik idempotency önbelleğinden önce **23 saatlik** temkinli tekrar sınırı uygular. Sonrasında finansal POST gönderilmez; GET/webhook uzlaştırması açık kalır. Kayıp ilk yanıt, asıl denemenin opak kimliği/parmak iziyle doğrulanabilir.

`NativeLedger`, native `yii\db\Connection` ve transaction/savepoint sayacını kullanır. İlk SQLite yazımı okumadan önce yazma kilidi alır; dış/iç içe native veya PDO transaction reddedilir. Sipariş, olay ve şifreli outbox birlikte commit olur; SQL tetikleyici hatası hepsini geri alır. Güncel sipariş aynı transaction altında yeniden karşılaştırılır; sahip/işyeri/ortam/tutar/para birimi değişikliği ve iptal kontrol gerektirir.

Provizyon/kısmi tahsilat bekletilir. Kesin sonuç kanıtı olmayan `FAILED` incelemede kalır; yeni anahtar açılmaz. Anapara, taksit ek bedeli dahil brüt tahsilat/iade bakiyelerinden ayrıdır. `order.paid` olayı yalnız tam tahsilatta bir kez yazılır; mali geçmiş otomatik silinmez.

## Uzlaştırma ve iş akışınız

```bash
php yii lunixi/reconcile
```

Eksik webhooklar için komutu zamanlayın. Sır yazdırmadan hata sayısı ve sıfırdan farklı çıkış kodu verir. Kayıtları, credential ve şifreleme anahtarını iade/uzlaştırma saklama süresince koruyun.

Örnek sipariş ve şifreli ödeme olayını kaydeder; uygulamanızın stok/e-posta/ürün-KDV/iade belgesi kurallarını uydurmaz. Commit sonrası dayanıklı outbox tüketicisini bağlayın; güncel sipariş/bekletme durumunu kontrol edip belirsiz harici sonuçları incelemeye bırakın. Mali yönetim ekranları SDK'nın kalıcı tahsilat/iade/iptal API'sine gerçek uygulama işlem ID'leriyle bağlanabilir; güvenliksiz örnek yönetim rotası eklenmemiştir.

Çalışan adaptör **yalnız SQLite PDO** içindir. Başka üretim sürücüsüne geçerken aynı yerel sipariş bağlantısı/transaction API'si üzerinde `LedgerStore` uygulayıp gerçek veritabanı testlerini çalıştırın; bellek deposu veya ayrı PDO transaction kullanmayın.

## Doğrulama

```bash
php vendor/bin/phpunit -c phpunit.xml
python3 tests/http-smoke.py
php vendor/bin/phpstan analyse -c phpstan.neon --no-progress
php vendor/bin/phpcs --standard=phpcs.xml
composer audit
```

Testler gerçek Yii rotası/güvenlik/identity ve SQLite kullanır; yalnız gateway HTTP yanıtları kontrollüdür. Ayrı gerçek HTTP sunucusu; oturum çerezi yenileme, CSRF, sahip/yöntem kontrolü, oturumsuz cross-site POST → 303 → korumalı GET ve sahte dönüşü Lunixi ödeme isteği göndermeden doğrular. SQL tetikleyici geri alma/tekrar, imza/ortam, kesin para, kayıp yanıt, opak kurtarma, süre sınırı ve FAILED inceleme kapsanır.

Yayından önce HTTPS uygulamanızda kayıtlı TEST üye işyeriyle gerçek form ve webhook kabulünü tamamlayın. Üretim kimlik politikası, uygulama yan etkileri ve yüksek hacimli veritabanı kabulü uygulamanızın parçasıdır. [SDK güvenlik politikası](../../SECURITY.md) ve [resmi Yii rehberi](https://www.yiiframework.com/doc/guide/2.0/en) ek kaynaklardır.
