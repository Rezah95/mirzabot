# مستند فنی و کسب‌وکاری فارسی پروژه Mirza Bot

> این سند بر اساس خروجی Graphify، کد اجرایی مخزن و فایل راهنمای پروژه تهیه شده است. هدف آن توضیح رفتار واقعی سیستم، مسیرهای اصلی داده و قواعدی است که هنگام نگهداری، توسعه یا عیب‌یابی باید در نظر گرفته شوند.

**وضعیت مبنا:** commit `c1402e16e6d901866196e8861b3b408ce91e89bd` در تاریخ ۲۰۲۶-۰۹-۲۴ (نسخه 0.5.8)

**مخزن و منابع اصلی:** [README](../README.md)، [گزارش Graphify](../graphify-out/GRAPH_REPORT.md)، [ورودی اصلی ربات](../index.php)، [توابع عمومی و پرداخت](../function.php)، [کیبوردها](../keyboard.php)، [لایه پنل‌ها](../panels.php)، [Mini App API](../api/miniapp.php)

**روش استفاده از این سند:** بخش‌های ۲ تا ۱۹ رفتار محصول و جریان‌های اجرایی را شرح می‌دهند؛ بخش‌های ۲۰ تا ۲۵ مرجع پوشه‌ها، API، دیتابیس، نصب و عملیات نسخهٔ فعلی هستند. نام فیلدها و statusها دقیقاً مطابق کد حفظ شده‌اند. مقدار تنظیمات هر نصب ممکن است متفاوت باشد. هیچ کلید یا گذرواژهٔ واقعی در این سند ثبت نشده است.

## فهرست بخش‌ها

| بخش | موضوع |
|---|---|
| ۱ تا ۳ | روش بررسی، معماری و قابلیت‌ها |
| ۴ تا ۷ | مدل داده، کاربر، کاتالوگ و پنل‌های VPN |
| ۸ تا ۱۰ | پرداخت، قواعد درگاه و چرخهٔ سرویس |
| ۱۱ تا ۱۲ | Mini App، API و مدیریت وب |
| ۱۳ تا ۱۵ | کران، کنترل‌های امنیتی و نصب |
| ۱۶ تا ۱۹ | سناریو، توسعه، محدودیت‌ها و پذیرش |
| ۲۰ تا ۲۵ | نقشهٔ فایل‌ها، قراردادهای API، شِما، نصب وب، عملیات و انتشار |
| ۲۶ | ارجاع سریع |

## خلاصه اجرایی

Mirza Bot یک سامانه فروش و مدیریت اشتراک VPN روی Telegram است. ربات از لحظه انتخاب محصول و دریافت وجه تا ساخت خودکار حساب روی پنل VPN، تحویل کانفیگ یا لینک اشتراک، تمدید، افزایش حجم/زمان، اعلان انقضا و گزارش‌گیری را پوشش می‌دهد.

سیستم چهار سطح اصلی دارد:

1. **ربات Telegram:** رابط اصلی کاربران، مدیران، کارشناسان و فروشندگان.
2. **Telegram Mini App:** رابط وب‌مانند برای مشاهده سرویس‌ها، فاکتورها و خرید مستقیم با موجودی کیف پول.
3. **پنل مدیریت وب:** مدیریت کاربران، پنل‌ها، محصولات، سرویس‌ها، پرداخت‌ها و تنظیمات.
4. **لایه اتصال به پنل‌های VPN:** یک واسط `ManagePanel` که عملیات ساخت، حذف، تمدید، تغییر وضعیت، افزایش حجم و افزایش زمان را به adapter مناسب هر پنل واگذار می‌کند.

نکته مهم در خواندن این سند این است که «پرداخت» در پروژه دو کاربرد دارد:

- در ربات، معمولاً کاربر ابتدا کیف پول خود را شارژ می‌کند و سپس عملیات خرید، تمدید یا افزایش سرویس با آن مبلغ انجام می‌شود.
- در Mini App، خرید سرویس می‌تواند مستقیماً از موجودی کیف پول کسر شود و بدون ایجاد درگاه جدید انجام شود.

## ۱. روش استخراج و درجه اطمینان

Graphify برای این snapshot شامل **۱۹۶ فایل، ۳۲۹۶ گره، ۶۹۶۹ یال و ۲۳۴ خوشه** است. گزارش، حدود ۹۱٪ روابط را استخراج‌شده و ۹٪ را استنباطی می‌داند و ۶۵۸ یال استنباطی ثبت کرده است. commit کوتاه `c1402e16` در گزارش با commit مبنای این سند یکسان است. گراف نقشهٔ مسیرهاست؛ ادعاهای اجرایی این سند با فایل‌های PHP فعلی تطبیق داده شده‌اند.

در این سند:

- رفتارهایی که مستقیماً از شرط‌ها، کوئری‌ها یا توابع کد خوانده شده‌اند، **رفتار قطعی کد** محسوب می‌شوند.
- روابطی که فقط در Graphify با برچسب `INFERRED` آمده‌اند، برای جهت‌یابی معماری استفاده شده‌اند و به‌عنوان قرارداد قطعی API تلقی نمی‌شوند.
- مقادیر فعال/غیرفعال تنظیمات از دیتابیس و `PaySetting`/`setting` خوانده می‌شوند؛ بنابراین ممکن است دو نصب با کد یکسان، UI متفاوتی نشان دهند.

گزارش Graphify شامل JavaScript فشردهٔ Mini App و نسخه‌های جایگزین زیر `vpnbot/` نیز هست. بنابراین بعضی گره‌های عمومی مانند `$` یا `select()` مرکز گراف شده‌اند؛ مرکزی‌بودن آن‌ها به‌تنهایی نشانهٔ اهمیت کسب‌وکاری یا قرارداد قطعی نیست. مسیرهای ریشه، API، پنل، نصب و cronهای فعلی جداگانه در این سند بررسی شده‌اند.

## ۲. معماری کلان

```text
کاربر Telegram
      │
      ▼
   index.php ──► keyboard.php / languagechange()
      │                         │
      │                         └── نمایش محصول، کیف پول، درگاه و عملیات سرویس
      ▼
   function.php ──► PDO/MySQL ──► user / product / invoice / Payment_report
      │
      ▼
   ManagePanel (panels.php)
      │
      ├── Marzban / Marzneshin
      ├── Sanaei/Alireza / x-ui / S-UI
      ├── Hiddify / WGDashboard
      ├── IBSng / MikroTik
      └── mirza_agent / Rebecca / Manualsale
      │
      ▼
   ساخت یا مدیریت کانفیگ و ارسال QR / لینک اشتراک / فایل conf

Mini App ──► api/verify.php ──► api/miniapp.php ──► همین لایه سرویس و دیتابیس

پنل وب ──► panel/login.php + APIهای api/ ──► دیتابیس و ManagePanel

درگاه/رسید ──► payment/* یا cronbot/* ──► claimPaymentPaid()
                                      └──► DirectPayment()
                                             └──► ManagePanel + sendMessageService()
```

### اجزای مهم و مسئولیت آن‌ها

| جزء | مسئولیت | فایل‌های شاخص |
|---|---|---|
| bootstrap ربات | timezone، بارگذاری تنظیمات، تشخیص update، ثبت/بارگذاری کاربر، کنترل عضویت و ضداسپم | [`index.php`](../index.php) |
| ارتباط Telegram | wrapperهای `sendmessage`، ارسال فایل/عکس، edit/delete و کنترل خطاهای API | [`botapi.php`](../botapi.php) |
| منطق کسب‌وکار | دیتابیس، فاکتور، پرداخت، کیف پول، referral، cashback و تحویل سرویس | [`function.php`](../function.php) |
| منوی ربات | ساخت کیبورد محصول، درگاه‌ها، مدیریت و قواعد نمایش آن‌ها | [`keyboard.php`](../keyboard.php) |
| abstraction پنل | یکسان‌سازی عملیات روی پنل‌های مختلف و dispatch به adapter | [`panels.php`](../panels.php) و فایل‌های adapter |
| Mini App | احراز هویت Bearer، catalog، خرید، فاکتورها و اطلاعات کاربر | [`api/miniapp.php`](../api/miniapp.php)، [`api/verify.php`](../api/verify.php) |
| API مدیریتی | CRUD پنل، محصول، کاربر، فاکتور، پرداخت، تخفیف و تنظیمات | پوشه [`api/`](../api/) |
| پنل وب | داشبورد، login، مدیریت کاربران/محصول/پرداخت و session مدیر | [`panel/`](../panel/) |
| پردازش پس‌زمینه | اعلان، انقضا، پرداخت‌های معلق، backup، health check و اعمال هدیه | [`cronbot/`](../cronbot/) |

## ۳. قابلیت‌های محصول

بر اساس README و مسیرهای کد، قابلیت‌های اصلی عبارت‌اند از:

- فروش خودکار اشتراک و ساخت کانفیگ روی چند نوع پنل VPN.
- ساخت سرویس آزمایشی، مدیریت پروتکل و inbound، تولید QR و تحویل کانفیگ/لینک اشتراک.
- تمدید سرویس، افزایش حجم، افزایش زمان، تغییر وضعیت، حذف یا بازنشانی مصرف.
- کیف پول، شارژ دستی یا آنلاین، cashback، کد تخفیف، هدیه، قرعه‌کشی و امتیازدهی.
- referral و affiliate، فروشنده/agent، محدودیت خرید و تخفیف درصدی برای کاربر.
- Telegram Mini App برای catalog، مشاهده سرویس‌ها، فاکتورها و خرید.
- پنل وب برای مدیریت کاربران، محصولات، پنل‌ها، پرداخت‌ها، فاکتورها، دسته‌بندی و تنظیمات.
- اعلان قبل از اتمام حجم یا زمان، تعلیق، on-hold و cronهای سلامت پنل/نود.
- اجبار عضویت در کانال، تأیید شماره/هویت، FAQ، آموزش و پشتیبانی.
- چندزبانگی در فایل‌های زبان و امکان ویرایش متن‌ها از تنظیمات.
- backup دوره‌ای دیتابیس و داده‌های عامل/ربات.

README پنل‌های Marzban، Marzneshin، Sanaei/Alireza، S-UI، Hiddify، WGDashboard، MikroTik، IBSng و Pasarguard را معرفی می‌کند. در کد فعلی adapterهای `Marzban.php`، `marzneshin.php`، `alireza_single.php`، `x-ui_single.php`، `s_ui.php`، `hiddify.php`، `WGDashboard.php`، `ibsng.php`، `mikrotik.php`، `mirza_agent.php` و `Rebecca.php` نیز وجود دارند؛ فعال بودن هر مورد به تنظیمات پنل و نصب وابسته است.

## ۴. مدل مفهومی داده

نام جدول‌ها در کد اصلی به شکل زیر دیده می‌شود. این فهرست مدل مفهومی است و جایگزین migration یا schema رسمی دیتابیس نیست.

### کاربر (`user`)

رکورد کاربر هنگام اولین تعامل Telegram ساخته می‌شود. فیلدهای مورد استفاده در مسیر اصلی شامل این موارد هستند:

- شناسه Telegram، نام کاربری و شماره تلفن.
- `status`، مرحله مکالمه `step`، زبان و وضعیت verification.
- موجودی `Balance` و فیلدهای موقت عملیات پرداخت مانند `Processing_value`.
- نقش agent (`f`، `n` یا `n2`)، سقف بدهی/خرید، تخفیف و وضعیت انقضای agent.
- کد دعوت، تعداد زیرمجموعه، cashback/affiliate و امتیاز.
- `cardpayment` برای مجاز بودن نمایش کارت‌به‌کارت برای آن کاربر.
- شمارنده تولید username، وضعیت نمایش پنل/محصول و پرچم‌های cron/اعلان.
- شمارنده پیام و زمان آخرین پیام برای ضداسپم.

اگر کاربر blocked باشد، پردازش update در همان مراحل ابتدایی متوقف می‌شود. در صورت رسیدن تعداد پیام‌ها به آستانه ضداسپم در یک دقیقه، کاربر مسدود و رویداد برای مدیریت گزارش می‌شود.

### محصول (`product`)

محصول، قیمت و محدودیت یک سرویس را برای یک پنل یا چند پنل تعریف می‌کند. در کد، رکوردهای پنل معمولاً از جدول `marzban_panel` خوانده می‌شوند، هرچند در این سند برای سادگی «پنل» نامیده شده‌اند. فیلدهای مهم محصول که در پنل و Mini App استفاده می‌شوند:

- نام و code محصول، قیمت و حجم/ترافیک.
- زمان سرویس و نوع reset حجم.
- `Location` برای اتصال محصول به پنل مشخص یا `/all`.
- agent مجاز، دسته‌بندی و توضیحات.
- inbound/proxyهای مجاز.
- `hide_panel` برای مخفی کردن محصول/پنل از نمایش کاربر.
- `one_buy_status` برای محصولی که فقط یک بار برای هر کاربر قابل خرید است.

### پنل (`panel`)

پنل دارای code، نام، نوع adapter، وضعیت فعال/غیرفعال، agent/مالک و تنظیمات اتصال است. `ManagePanel` با توجه به `type`، عملیات را به تابع مناسب adapter می‌فرستد.

### فاکتور و سرویس (`invoice`)

فاکتور ارتباط بین کاربر، محصول، پنل و سرویس واقعی است و اطلاعاتی مانند username سرویس، قیمت، حجم، زمان، یادداشت، referral و وضعیت سرویس را نگه می‌دارد. وضعیت‌هایی که cronها و Mini App صراحتاً می‌خوانند شامل `active`، `end_of_time`، `end_of_volume`، `sendedwarn`، `send_on_hold` و وضعیت‌های مدیریتی مانند `disablebyadmin` هستند.

### گزارش پرداخت (`Payment_report`)

این جدول ledger اصلی پرداخت‌های کیف پول/عملیات است. فیلدهای پرتکرار عبارت‌اند از:

| فیلد | کاربرد |
|---|---|
| `id_user` | صاحب پرداخت |
| `id_order` | شناسه پیگیری پرداخت |
| `time` | زمان ثبت |
| `price` | مبلغ |
| `payment_Status` | معمولاً `Unpaid`، `waiting`، `paid`، `reject` یا `expire` |
| `Payment_Method` | روش پرداخت، مانند کارت‌به‌کارت یا درگاه |
| `id_invoice` | operation context؛ در بعضی مسیرها چند مقدار با `|` جدا می‌شود |
| `dec_not_confirmed` | Authority، پاسخ gateway، متن رسید یا توضیح بررسی |
| `message_id` | پیام Telegram مرتبط |
| `at_updated` | زمان/متادیتای بروزرسانی |
| `bottype` | تفکیک برخی مسیرهای ربات/نوع پردازش |

### تنظیمات و جداول پیرامونی

- `setting`: تنظیمات عمومی فروشگاه، متن، اعلان، referral، verification و cron.
- `PaySetting`: فعال‌سازی درگاه‌ها، حداقل/حداکثر مبلغ و قواعد نمایش پرداخت.
- `service_other`: سوابق تمدید، افزایش حجم/زمان و تغییرات جانبی سرویس.
- `category` و جداول discount: دسته‌بندی و تخفیف عمومی/فروشنده.
- `admin`: مدیران پنل وب و نقش‌های Telegram.
- `card_number`: کارت‌هایی که برای مسیر کارت‌به‌کارت انتخاب می‌شوند.

## ۵. احراز هویت، ثبت‌نام و مسیر کاربر در ربات

### شروع و آماده‌سازی update

[`index.php`](../index.php) timezone را روی `Asia/Tehran` تنظیم می‌کند، config، wrapper Telegram، JDF، منطق عمومی، keyboard، vendor و adapterهای پنل را بارگذاری می‌کند. سپس زبان کاربر و تنظیمات متن را اعمال می‌کند.

برای eventهای `chat_member`، خروج یا kick/restrict کاربر شناسایی می‌شود و پیام پیوستن مجدد ارسال می‌گردد. updateهای غیرمجاز یا نامرتبط با chatهای پشتیبانی‌شده کنار گذاشته می‌شوند.

### ساخت کاربر

در اولین ورود، رکورد کاربر با وضعیت اولیه ساخته می‌شود؛ از جمله:

- وضعیت فعال، مرحله مکالمه و زبان پیش‌فرض فارسی؛
- موجودی صفر و counters مربوط به username و پیام؛
- referral و agent با مقدار پیش‌فرض؛
- وضعیت verification، عضویت کانال و اعلان cron؛
- `cardpayment` و سایر پرچم‌های قابلیت.

پس از ثبت کاربر، گزارش ثبت‌نام برای کانال گزارش ارسال می‌شود. پارامتر referral از `/start` خوانده، اعتبارسنجی و در صورت نبودن self-referral ذخیره می‌شود.

### دروازه‌های دسترسی

بسته به تنظیمات، کاربر ممکن است قبل از دیدن منوی اصلی نیاز به موارد زیر داشته باشد:

- عضویت در کانال اجباری؛
- تأیید شماره تلفن یا verification؛
- عبور از rules/roll gate؛
- نداشتن وضعیت blocked.

ضداسپم نیز در bootstrap اعمال می‌شود: اگر شمارنده پیام‌های کاربر در بازه یک‌دقیقه‌ای از حد تعیین‌شده عبور کند، وضعیت کاربر مسدود و گزارش مدیریتی ایجاد می‌شود.

## ۶. منطق کاتالوگ، محصول و خرید

### نمایش محصول در Mini App

مسیر `mini_services` در [`api/miniapp.php`](../api/miniapp.php#L615) فقط محصولاتی را برمی‌گرداند که شرایط زیر را داشته باشند:

1. پنل مورد نظر فعال باشد و code آن معتبر باشد.
2. `Location` محصول با پنل انتخابی یا `/all` منطبق باشد.
3. محصول به agent کاربر تعلق داشته باشد یا برای عموم فعال شده باشد.
4. پنل/محصول با `hide_panel` مخفی نشده باشد.
5. category و time range انتخابی، در صورت ارسال، منطبق باشند.
6. اگر `one_buy_status=1` باشد، محصول فقط برای کاربری نمایش داده شود که هنوز هیچ فاکتور غیر `Unpaid` ندارد.
7. قیمت کاربر با `pricediscount` در صورت فعال بودن تخفیف اصلاح شود.

این flag در رابط مدیر با عنوان «خرید اول» تنظیم می‌شود. query بر اساس `id_user` و `Status != 'Unpaid'` است و به شناسهٔ همان محصول محدود نیست. کیبورد خرید ربات نیز همین قاعده را دارد و این محصولات در تمدید نمایش داده نمی‌شوند.

### قیمت سفارشی

`mini_custom_price` و `mini_purchase` برای پنل‌هایی که custom volume/time فعال دارند، محدوده‌های زیر را کنترل می‌کنند:

- حداقل و حداکثر حجم؛
- حداقل و حداکثر زمان؛
- قیمت هر واحد حجم و قیمت هر واحد زمان؛
- استثنای `Manualsale` که مسیر ساخت واقعی آن متفاوت است.

قیمت سفارشی از ترکیب حجم و زمان محاسبه می‌شود:

```text
قیمت = (حجم بر حسب GB × قیمت حجم) + (تعداد روز × قیمت زمان)
```

سپس تخفیف اختصاصی کاربر اعمال می‌شود. مقادیر نامعتبر، خارج از محدوده، صفر یا منفی رد می‌شوند.

### خرید مستقیم Mini App

در `mini_purchase` ترتیب منطقی پردازش چنین است:

1. Bearer token به کاربر معتبر تبدیل می‌شود و `user_id` ارسالی کلاینت قابل اعتماد نیست؛ شناسه از token احراز‌شده استفاده می‌شود.
2. blocked بودن کاربر بررسی می‌شود.
3. پنل، محصول، agent، `Location`، `hide_panel` و `one_buy_status` دوباره سمت سرور بررسی می‌شوند.
4. برای custom purchase، محدوده حجم/زمان و قیمت مجدداً محاسبه می‌گردد؛ قیمت ارسالی کلاینت مبنای اعتماد نیست.
5. برای کاربر معمولی موجودی نباید منفی شود. برای agent سطح `n2`، حد بدهی/`maxbuyagent` می‌تواند اجازه منفی شدن محدود موجودی را بدهد.
6. مبلغ با یک `UPDATE` شرطی از موجودی کسر می‌شود تا حداقل موجودی مجاز رد نشود.
7. شناسه username ساخته و با داده پنل/دیتابیس از نظر تکراری بودن کنترل می‌شود.
8. فاکتور سرویس با وضعیت فعال ایجاد می‌شود.
9. `ManagePanel->createUser()` حساب را روی پنل می‌سازد؛ `data_limit` از GB به byte تبدیل و زمان انقضا محاسبه می‌شود.
10. در خطا، فاکتور/عملیات ناموفق جمع می‌شود و مبلغ به کیف پول برگردانده می‌شود.
11. config، subscription link یا فایل مخصوص WireGuard ارسال می‌شود.
12. affiliate، score، شمارنده username و گزارش خرید بروزرسانی می‌شوند.

این ترتیب باعث می‌شود ساخت سرویس تنها بعد از اعتبارسنجی سمت سرور و رزرو موفق مبلغ انجام شود و در خطای ساخت پنل، refund خودکار وجود داشته باشد.

## ۷. لایه اتصال پنل‌ها

### `ManagePanel`

کلاس [`ManagePanel`](../panels.php#L16) interface واحدی برای عملیات زیر ارائه می‌کند:

| عملیات | متد/کاربرد |
|---|---|
| ساخت حساب | `createUser()` |
| خواندن سرویس | `DataUser()` |
| تمدید | `extend()` |
| افزایش حجم | `extra_volume()` |
| افزایش زمان | `extra_time()` |
| تغییر مشخصات | `Modifyuser()` |
| تغییر وضعیت | `Change_status()` |
| بازنشانی مصرف | `ResetUserDataUsage()` |
| حذف/لغو | `RemoveUser()` و `Revoke_sub()` |

در `createUser`، نوع پنل dispatch می‌شود؛ برای نمونه:

| مقدار `marzban_panel.type` | adapter | ویژگی مسیر |
|---|---|---|
| `marzban`، `marzneshin` | [`Marzban.php`](../Marzban.php)، [`marzneshin.php`](../marzneshin.php) | API کاربر و subscription |
| `x-ui_single`، `alireza_single`، `s_ui` | [`x-ui_single.php`](../x-ui_single.php)، [`alireza_single.php`](../alireza_single.php)، [`s_ui.php`](../s_ui.php) | client و inbound/proxy |
| `hiddify`، `rebecca` | [`hiddify.php`](../hiddify.php)، [`Rebecca.php`](../Rebecca.php) | API اختصاصی و خروجی کانفیگ |
| `WGDashboard` | [`WGDashboard.php`](../WGDashboard.php) | peer و فایل WireGuard `.conf` |
| `ibsng`، `mikrotik` | [`ibsng.php`](../ibsng.php)، [`mikrotik.php`](../mikrotik.php) | حساب شبکه با adapter اختصاصی |
| `mirza_agent`، `Manualsale` | [`mirza_agent.php`](../mirza_agent.php)، شاخهٔ داخلی [`panels.php`](../panels.php) | فروش agent یا تحویل دستی |

نوع `Pasarguard` در README معرفی شده، اما در dispatchهای فعلی `ManagePanel` شاخه‌ای با همین نام دیده نمی‌شود؛ پشتیبانی آن برای نسخهٔ حاضر نباید صرفاً از روی README فرض شود. قابلیت هر عملیات برای همهٔ typeها یکسان نیست و شاخهٔ همان متد در `panels.php` مرجع قطعی است.

- Marzban از `adduser` و عملیات subscription استفاده می‌کند.
- Marzneshin token و output link تولید می‌کند.
- x-ui/Sanaei/S-UI از client/inboundهای پنل استفاده می‌کنند.
- Hiddify مسیر API خودش را دارد.
- WGDashboard peer ساخته و فایل `.conf` فراهم می‌کند.
- IBSng و MikroTik از adapter اختصاصی استفاده می‌کنند.
- `Manualsale` و adapterهای agent مسیرهای فروش/ساخت متفاوت دارند.

در تمدید، تنظیمات می‌تواند الگوریتم‌هایی مانند `resetVolumeTime`، `addTimeVolumeNextMonth`، `resetTimeAddVolume`، `resetVolumeAddTime` و `addTimeConvertVolume` را انتخاب کند. بنابراین تمدید فقط «اضافه کردن چند روز» نیست و ممکن است حجم، reset و زمان فعلی را هم تغییر دهد.

### تحویل کانفیگ

[`sendMessageService`](../function.php#L2480) با توجه به تنظیم پنل تصمیم می‌گیرد subscription link یا config خام ارسال شود. برای WGDashboard فایل `.conf` به‌صورت document ارسال می‌شود. در مسیرهای دیگر QR با Endroid QR ساخته می‌شود و در صورت خطا، متن کانفیگ یا لینک به‌عنوان fallback ارسال می‌گردد. مسیر QR از `createqrcode` استفاده می‌کند و keyboard سرویس نیز می‌تواند همراه پیام فرستاده شود.

## ۸. پرداخت و کیف پول

### انواع روش پرداخت

README روش‌های زیر را معرفی می‌کند و کد نیز adapter/callbackهای مرتبط دارد:

| دسته | روش‌ها | نوع تأیید |
|---|---|---|
| دستی | کارت‌به‌کارت، ارز دیجیتال آفلاین | رسید کاربر، مدیر یا cron خودکار |
| آنلاین ریالی | Zarinpal، Aqayepardakht، IranPay و AbanGateway (شناسهٔ داخلی `iranpay4`) | callback و verify درگاه |
| رمزارزی/سایر | NowPayments، Plisio، Tetraminator و UniquePay در مسیرهای مربوط | callback یا polling |
| پرداخت CubePay | مسیر داخلی `iranpay2` با API سرویس CubePay | callback امضاشده یا verify با authority |
| پرداخت ترونادو | مسیر مستقل `tronadopay` با API رسمی v5 | IPN امضاشده و تطبیق سفارش |
| Telegram | Telegram Stars | `pre_checkout` و payment موفق |
| انتقال کارت خودکار | Variza | لینک پرداخت و webhook امضاشده |
| سایر | روش‌هایی که با flag و function موجود فعال می‌شوند | وابسته به تنظیمات و provider |

### CubePay و ترونادو

در نسخهٔ فعلی، انتخاب `iranpay2` در ربات، رکوردی با `Payment_Method='Currency Rial 2'` می‌سازد و تابع با نام تاریخی `trnado()` را فراخوانی می‌کند. با وجود نام تابع و متن بعضی دکمه‌ها، این تابع اکنون مبلغ را با `cubepayPayableAmount()` محاسبه و سفارش را به `https://cubevps.ir/pay/create-order.php` با Bearer token تنظیم `apiternado` ارسال می‌کند. callback این سفارش `payment/iranpay2.php` است و لینک بازگشتی از `payment_link` یا `pay_page_url` گرفته می‌شود.

[`payment/iranpay2.php`](../payment/iranpay2.php) دو شکل تأیید دارد: callback دارای `sig` را با HMAC-SHA256 روی `order_id|status|amount` و token بررسی می‌کند، یا با `authority` به endpoint verify سرویس درخواست می‌زند. در هر دو حالت شناسهٔ سفارش و مبلغ پاسخ باید با رکورد محلی مطابق باشد؛ وضعیت `expire` پذیرفته نمی‌شود. پس از تأیید، `claimPaymentPaid()` و `DirectPayment()` اجرا می‌شوند. نام‌های `Tronado` در متن گزارش و تنظیمات تاریخی لزوماً به معنای استفاده از API ترونادو در این مسیر نیستند.

ترونادو اکنون مسیر مستقل `tronadopay` دارد. [`payment/tronado_lib.php`](../payment/tronado_lib.php) ابتدا با `POST /Tron/GetPriceToToman` قیمت تومانی ترون را می‌گیرد، مبلغ فاکتور را تا ۶ رقم اعشار به بالا تبدیل می‌کند و با `POST /api/v5/GetOrderToken?wageFromBusinessPercentage=100` سفارش می‌سازد. این مقدار ۱۰۰ همان سیاست قبلی پروژه است: کسب‌وکار کل کارمزد را جذب می‌کند. لینک پرداخت و `Payment_Method='Tronado'` مستقل از CubePay هستند. کلید API، کیف پول مقصد، کلید IPN، حدود مبلغ و کش‌بک همگی با پیشوند `tronado_` ذخیره می‌شوند؛ `apiternado` و `statustarnado` همچنان متعلق به CubePay هستند.

[`payment/tronado.php`](../payment/tronado.php) امضای HMAC-SHA512 بدنهٔ خام را با `X-Tronado-Sig` و `tronado_ipn_signing_key` بررسی می‌کند. `(PaymentId, OrderStatusID)` در `Tronado_callback` حذف تکرار می‌شود. تحویل فقط برای سفارش ترونادو، وضعیت ۳۰ یا `IsPaid=true`، کیف پول مقصد برابر کیف پول ثبت‌شده و `UserPaidTomanAmount` نزدیک به مبلغ فاکتور انجام می‌شود؛ حداکثر ۵٬۰۰۰ تومان تفاوت ناشی از گردکردن مجاز است. `TomanAmountWithoutWage` نیز باید مثبت باشد ولی در سیاست جذب کامل کارمزد معیار اعتبار کیف پول نیست. دادهٔ ایجاد سفارش در `dec_not_confirmed` نگه داشته می‌شود و callback خام در `Tronado_callback` ثبت می‌شود. [مرجع رسمی ترونادو](https://miniapp.tronado.cloud/assets/api-docs.md).

برای سفارش‌های بازِ نسخهٔ قدیمی ترونادو که تنها پاسخ provider را ذخیره کرده‌اند، callback با توکن `UniqueCode` و کیف پول تاریخی `walletaddress` تطبیق داده می‌شود. اگر کیف پول تاریخی حذف یا تغییر کرده باشد، این سفارش‌ها به آشتی دستی نیاز دارند. سفارش‌های قدیمی با `Payment_Method='Currency Rial 2'` به‌دلیل اشتراک نام با CubePay خودکار به ترونادو نسبت داده نمی‌شوند.

اگر ترونادو پس از تحویل، وضعیت ۲۰۰ (لغو) بفرستد، رویداد در جدول callback ثبت و برای آشتی دستی در log و کانال گزارش اعلام می‌شود. برگشت خودکار سرویس یا برداشت موجودی انجام نمی‌شود، چون ممکن است سرویس قبلاً مصرف شده باشد. webhook اختیاری اعتراض‌های `AmountAdjusted` در این نسخه متصل نشده و فعال‌سازی آن نیازمند طراحی چرخهٔ تعدیل سفارش است.

برای راه‌اندازی، در پنل مدیریت از تنظیمات مالی ← ترونادو، کلید API، آدرس کیف پول TRON و کلید امضای IPN را ثبت و سپس درگاه را فعال کنید. آدرس `https://DOMAIN/payment/tronado.php` و دامنهٔ آن باید نزد ترونادو مجاز شده باشند. اطلاعات قدیمی `apiternado` به ترونادو منتقل نمی‌شود، چون همین کلید در CubePay مصرف می‌شود. ترتیب در فهرست پرداخت، ترونادو، تترامیناتور و سپس زرین‌پال است؛ زرین‌پال فقط در صورت فعال‌سازی و مجاز بودن کاربر نمایش داده می‌شود و مقدار اولیهٔ آن غیرفعال است. UniquePay و CubePay در صورت فعال بودن، گزینه‌های جداگانه‌اند.

### درگاه‌های سفارشی

**Tetraminator:** `index.php` مبلغ ۵۰٬۰۰۰ تا ۱۰٬۰۰۰٬۰۰۰ تومان را می‌پذیرد، `/invoice/create` را فراخوانی می‌کند و `pay_id` را در `Payment_report.dec_not_confirmed` می‌گذارد. callback ابتدا token آدرس را بررسی می‌کند، سپس `/payment/inquiry/{pay_id}` را با کلید API استعلام می‌گیرد و `status=true`، `payment_status=paid`، `pay_id` و `amount` را با سفارش محلی تطبیق می‌دهد؛ پس از آن claim و تحویل انجام می‌شود. فایل نصب منتشرشده در [مرجع نصب تترامیناتور](https://api.tetraminator.com/installer.sh) برای مشاهدهٔ قرارداد استفاده شده است؛ اجرای دوبارهٔ نصب‌کننده روی سورس سفارشی می‌تواند فایل‌های درگاه را بازنویسی کند.

**UniquePay:** پاسخ `/api/create-invoice` شامل `hashId`، `refId` و `paymentLink` در سطح اصلی JSON است. هنگام بازگشت، `/api/check-invoice` با همان `hashId` استعلام می‌شود. `invoice.id` باید با `refId` زمان ساخت و `invoice.amount` با مبلغ سفارش برابر باشد؛ `isPaid`، `isVerified` و `invoice.status=paid` نیز لازم‌اند. کارمزد احتمالی در `payableAmount` است و مبلغ پایهٔ سفارش همان `amount` به تومان است. حداقل مبلغ طبق API بیش از ۵۰٬۰۰۰ تومان است. [مستندات UniquePay](https://uniquepay.top/api-docs).

### واریزا و آبان‌گیت‌وی

**Variza:** نمایش دکمه به `variza_status=onvariza` و وجود token و secret webhook وابسته است. `createPayVariza()` سفارش را به API واریزا می‌فرستد؛ `index.php` مبلغ را با `minbalancevariza`/`maxbalancevariza` می‌سنجد، `Payment_report` را با روش `variza` و slug در `dec_not_confirmed` ثبت و URL پرداخت را ارسال می‌کند. [`payment/variza.php`](../payment/variza.php) فقط صفحهٔ انتظار برگشت کاربر است. تسویه در [`payment/variza_webhook.php`](../payment/variza_webhook.php) انجام می‌شود: امضای HMAC-SHA256 بدنهٔ خام از `X-Webhook-Signature` بررسی می‌شود، رویداد `payment.paid` و slug/مبلغ با سفارش تطبیق داده می‌شوند و سپس `claimPaymentPaid()` و `DirectPayment()` اجرا می‌شوند. مبلغ webhook باید دست‌کم مبلغ ثبت‌شدهٔ سفارش باشد.

**AbanGateway:** شناسهٔ داخلی مسیر `iranpay4` و نام `Payment_Method` برابر `AbanGateway` است. دکمه فقط وقتی فعال است که `statusiranpay4=oniranpay4`، API key موجود و `endpointiranpay4` یک URL معتبر HTTPS باشد. `index.php` بازهٔ مبلغ و سقف روزانهٔ تنظیم‌شده را بررسی و پیش از درخواست لینک، رکورد `Unpaid` را می‌سازد. `createPayiranpay4()` به `/create` endpoint درگاه درخواست می‌زند. [`payment/iranpay4.php`](../payment/iranpay4.php) در برگشت، `/verify` را با order، authority و مبلغ صدا می‌زند و موفقیت، تطابق order و کافی بودن مبلغ پاسخ را کنترل می‌کند؛ سپس claim و تحویل را انجام می‌دهد. تنظیمات مرتبط در `PaySetting` شامل کلید، endpoint، وضعیت، حداقل/حداکثر، سقف روزانه و cashback است.

### چرخه عمومی `Payment_report`

```text
انتخاب مبلغ/عملیات
        │
        ▼
ثبت Payment_report با وضعیت Unpaid
        │
        ├── کارت‌به‌کارت: ارسال شماره کارت و مبلغ → دریافت رسید → waiting
        ├── درگاه: ساخت URL/Authority → بازگشت callback → verify
        ├── رمزارز: callback یا polling وضعیت invoice
        └── Stars: pre_checkout → successful_payment
        │
        ▼
claimPaymentPaid(id_order)
        │
        ▼
DirectPayment(id_order)
        │
        ├── ساخت کانفیگ
        ├── تمدید
        ├── افزایش حجم/زمان
        └── شارژ کیف پول
        │
        ▼
ارسال نتیجه، cashback/affiliate و گزارش مدیریتی
```

`claimPaymentPaid` وضعیت را فقط زمانی به `paid` تغییر می‌دهد که قبلاً `paid` یا `reject` نشده باشد و از دوباره‌پردازش ساده یک order جلوگیری می‌کند. پس از آن، `DirectPayment` با توجه به context داخل `id_invoice` عملیات واقعی را اجرا می‌کند. `fulfillment_status` نتیجهٔ `processing`، `fulfilled`، `refunded` یا `failed` را ثبت می‌کند. شمارش پرداخت موفق، رکوردهای بازپرداخت‌شده و تحویل ناموفق را کنار می‌گذارد و رکوردهای قدیمی با وضعیت تحویل خالی را همچنان می‌شمارد. تغییر وضعیت `paid` و تحویل سرویس یک تراکنش واحد نیستند؛ سفارش `failed` باید با وضعیت واقعی پنل آشتی داده شود و تکرار خودکار کورکورانه مجاز نیست.

### `DirectPayment` و انواع عملیات

تابع [`DirectPayment`](../function.php#L1069) عملیات پرداخت را به چند مسیر تقسیم می‌کند:

- `getconfigafterpay`: ساخت حساب جدید، محاسبه زمان/حجم، ساخت config و ارسال به کاربر.
- `getextenduser`: تمدید سرویس از طریق `ManagePanel->extend()` و ثبت `service_other`.
- `getextravolumeuser`: افزایش حجم و ثبت مقدار قبلی/جدید.
- `getextratimeuser`: افزایش زمان و ثبت نتیجه.
- مسیر پیش‌فرض: شارژ مبلغ به `Balance` کاربر.

اگر ساخت یا تغییر پنل شکست بخورد، در مسیرهای اصلی مبلغ refund می‌شود، پیام خطا ارسال و گزارش مدیریتی ثبت می‌گردد. برای موفقیت سرویس، وضعیت invoice، cashback، affiliate و score مطابق تنظیمات بروزرسانی می‌شوند.

## ۹. قاعده دقیق نمایش درگاه‌ها و مثال کارت‌به‌کارت

این بخش قاعدهٔ فعلی نمایش گزینه‌های پرداخت در ربات را شرح می‌دهد؛ شرط نمایش دکمه با شرط پذیرش و تسویهٔ پرداخت یکسان فرض نمی‌شود.

در [`keyboard.php`](../keyboard.php#L279)، ابتدا تعداد پرداخت‌های موفق کاربر محاسبه می‌شود:

```sql
SELECT COUNT(*)
FROM Payment_report
WHERE id_user = :user_id
  AND payment_Status = 'paid'
```

نام این شمارنده در کد `paymentexits` است. سپس قواعد زیر اعمال می‌شوند:

```text
paymentexits = تعداد رکوردهای paid در Payment_report برای کاربر

کارت‌به‌کارت:
  PaySetting مربوط به کارت‌به‌کارت = oncard
  و user.cardpayment = 1

قفل مرحله اول پرداخت:
  اگر checkpaycartfirst = onpayverify
  و paymentexits = 0
  آنگاه گزینه‌هایی که تا این نقطه به کیبورد اضافه شده‌اند حذف می‌شوند.

IranPay3:
  statusiranpay3 = oniranpay3
  و paymentexits >= 2
  آنگاه گزینه نمایش داده می‌شود.
```

### تفسیر کسب‌وکاری

- **کارت‌به‌کارت مستقیماً شرط «بیشتر از یک خرید» ندارد.** نمایش مستقیم آن به فعال بودن setting سراسری و `user.cardpayment=1` وابسته است.
- اگر `checkpaycartfirst` فعال باشد، کاربری که هنوز هیچ پرداخت موفقی (`paymentexits=0`) ندارد، گزینه‌هایی را که قبل از این شرط به کیبورد اضافه شده‌اند نمی‌بیند؛ کارت‌به‌کارت نیز در همین بخش قبل از شرط اضافه شده است. درگاه‌هایی که بعدتر در کد به کیبورد اضافه می‌شوند ممکن است همچنان نمایش داده شوند. پس از حداقل یک پرداخت موفق، این قفل مرحله اول برداشته می‌شود و کارت‌به‌کارت می‌تواند طبق دو flag اصلی نمایش داده شود.
- **IranPay3 واقعاً شرط حداقل دو پرداخت موفق دارد** (`paymentexits >= 2`). بنابراین عبارت «بیشتر از یک بار» برای این درگاه دقیق است، نه برای کارت‌به‌کارت.
- شمارش بر اساس **رکورد پرداخت موفق در `Payment_report`** است، نه الزاماً تعداد سفارش‌های جدول `invoice`. یک رکورد paid می‌تواند شارژ کیف پول یا پرداخت یک عملیات سرویس باشد؛ بنابراین در مستندات کسب‌وکاری بهتر است از عبارت «تعداد پرداخت موفق» استفاده شود، مگر اینکه محصول صریحاً count سفارش‌ها را بخواهد.

مدیر می‌تواند از مسیر مدیریت نمایش کارت، مقدار `cardpayment` کاربران را تغییر دهد؛ حالت فعال‌سازی سراسری مقدار این flag را برای کاربران تنظیم می‌کند و حالت غیرفعال‌سازی می‌تواند همه کاربران یا فقط agentهای عادی را پوشش دهد.

### تفاوت با قاعدهٔ احتمالی «کارت‌به‌کارت فقط بعد از دو خرید»

کد فعلی این acceptance criterion را پیاده نکرده است. برای پیاده‌سازی دقیق باید شرط مستقل زیر به مسیر ساخت keyboard کارت‌به‌کارت اضافه شود و همان شرط در handler ایجاد Payment_report نیز سمت سرور enforce شود:

```text
نمایش/پذیرش کارت‌به‌کارت فقط وقتی:
  paymentexits >= 2
  و PaySettingcard = oncard
  و user.cardpayment = 1
```

فقط پنهان کردن دکمه کافی نیست؛ چون کلاینت Telegram یا callback قدیمی می‌تواند مستقیماً handler را فراخوانی کند. این تغییر در snapshot فعلی انجام نشده و این سند صرفاً رفتار موجود را ثبت می‌کند.

### کارت‌به‌کارت و رسید

در مسیر `cart_to_offline` در [`index.php`](../index.php#L4647):

1. حداقل و حداکثر مبلغ بررسی می‌شود.
2. یک کارت از `card_number` انتخاب می‌شود.
3. در حالت auto-confirm، مبلغ ممکن است برای تطبیق خودکار با رقم پایانی ویژه ارسال شود؛ در حالت عادی مبلغ دقیق نمایش داده می‌شود.
4. `Payment_report` با روش `cart to cart` و وضعیت `Unpaid` ساخته می‌شود.
5. `id_invoice` context عملیات را نگه می‌دارد.
6. پیام کارت، مبلغ و دکمه ارسال رسید برای کاربر فرستاده می‌شود.

رسید پس از کنترل cooldown و وضعیت order به `waiting` یا وضعیت بررسی مربوط منتقل می‌شود. مدیر از [`admin.php`](../admin.php#L1936) می‌تواند پرداخت را تأیید یا رد کند. `cronbot/croncard.php` نیز پرداخت‌های waiting کارت‌به‌کارت/ارز آفلاین را طبق زمان و exceptionها بررسی و در شرایط مجاز auto-confirm می‌کند.

### قانون نمایش زرین‌پال

زرین‌پال علاوه بر فعال بودن خود درگاه (`zarinpalstatus=onzarinpal`)، یک قانون مستقل برای نمایش و استفاده دارد:

```text
زرین‌پال قابل استفاده است اگر:
  zarinpalstatus = onzarinpal
  و user.zarinpalpayment != 0
  و یکی از این دو حالت برقرار باشد:
      zarinpal_payment_gate_enabled = 0
      یا paymentexits >= zarinpal_min_successful_payments
```

- مقدار پیش‌فرض `zarinpal_payment_gate_enabled` برابر `1` و مقدار پیش‌فرض `zarinpal_min_successful_payments` برابر `2` است.
- `paymentexits` همان تعداد رکوردهای `Payment_report` با `payment_Status='paid'` برای کاربر است؛ شمارش سفارش‌های `invoice` نیست.
- `user.zarinpalpayment` به‌صورت پیش‌فرض `1` است. مقدار `0` زرین‌پال را فقط برای همان کاربر پنهان و غیرقابل استفاده می‌کند؛ حتی اگر شرط تعداد پرداخت سراسری خاموش باشد.
- شرط در هر دو نقطه اعمال می‌شود: هنگام ساخت keyboard و هنگام اجرای callback `zarinpal`. بنابراین callback ذخیره‌شده یا درخواست مستقیم نمی‌تواند محدودیت را دور بزند.

در تنظیمات زرین‌پال Telegram Admin، مدیر اصلی می‌تواند شرط تعداد پرداخت را روشن/خاموش کند، حداقل تعداد پرداخت موفق را تغییر دهد و با وارد کردن شناسه عددی هر کاربر، نمایش زرین‌پال او را فعال یا مخفی کند. پنل وب نیز در صفحه هر کاربر یک toggle زرین‌پال دارد. API کاربر action `manage_show_zarinpal` را برای همین کنترل تک‌کاربره ارائه می‌دهد.

## ۱۰. مدیریت چرخه عمر سرویس

### وضعیت‌ها و اعلان‌ها

`NoticationsService.php` فاکتورهای فعال، پایان زمان، پایان حجم، هشدار ارسال‌شده و on-hold را انتخاب می‌کند. برای جلوگیری از ارسال تکراری، زمان آخرین cron/اعلان و throttle حدودی بررسی می‌شود.

رفتارهای خودکار شامل موارد زیر است:

- هشدار نزدیک شدن به اتمام حجم با threshold تنظیم `volumewarn`؛
- هشدار نزدیک شدن به انقضا و حذف/غیرفعال‌سازی بعد از `removedayc`؛
- حذف سرویس از پنل و اطلاع‌رسانی به کاربر در شرایط انقضا؛
- ارسال reminder و انتقال به وضعیت `send_on_hold` برای panelهای Marzban در on-hold؛
- غیرفعال‌سازی سرویس‌هایی که مدیر disable کرده و فعال‌سازی مجدد موارد مشخص‌شده؛
- اعمال هدیه حجم/زمان از صف `gift.php`؛
- بررسی سرویس‌های test و حذف config نامعتبر با `configtest.php`.

### تمدید و افزایش منابع

هر تمدید یا افزایش حجم/زمان می‌تواند در `service_other` ثبت شود تا تاریخچه عملیات، مقدار قبلی و مقدار جدید قابل گزارش باشد. این مسیرها از `ManagePanel` استفاده می‌کنند و پس از موفقیت، invoice، balance، score/cashback و پیام کاربر بروزرسانی می‌شود.

## ۱۱. Mini App و APIهای کاربر

### احراز هویت Mini App

[`api/verify.php`](../api/verify.php) داده Telegram Web App را با HMAC-SHA256 بررسی می‌کند:

- داده از header/body/POST/GET خوانده می‌شود؛
- secret بر اساس bot token و `WebAppData` ساخته می‌شود؛
- data-check-string مرتب و hash با `hash_equals` مقایسه می‌شود؛
- `auth_date` بیش از بازه مجاز رد می‌شود؛
- شناسه کاربر فقط از داده معتبر Telegram استخراج می‌شود.

در `api/miniapp.php` نیز Bearer token کاربر با `hash_equals` بررسی می‌شود، `user_id` کلاینت نادیده گرفته می‌شود و شناسه احراز‌شده جایگزین آن می‌گردد. کاربر blocked با پاسخ خطا متوقف می‌شود.

### actionهای Mini App

| action | متد غالب | خروجی/کاربرد |
|---|---|---|
| `invoices` | GET | فهرست حداکثر ۱۰ سرویس/فاکتور فعال یا در وضعیت‌های مرتبط |
| `service` | GET | جزئیات سرویس مشخص |
| `user_info` | GET | موجودی، نقش، تاریخ عضویت، referral، تعداد سرویس/پرداخت |
| `countries` | GET | پنل‌های قابل نمایش و پرچم‌های custom/name |
| `categories` | GET | دسته‌بندی‌های دارای محصول برای پنل/agent |
| `time_ranges` | GET | زمان‌های موجود از روی محصولات، مانند ۱، ۷، ۳۰/۳۱، ۶۰/۶۱ و حجم‌محور |
| `services` | GET | catalog فیلترشده بر اساس پنل، category، زمان و agent |
| `custom_price` | GET | محدوده و قیمت حجم/زمان سفارشی |
| `purchase` | POST | اعتبارسنجی و ساخت سرویس با کسر موجودی |

پاسخ API معمولاً ساختار `status`، `msg` و `obj` دارد. API مدیریتی از `api/utils.php` برای token، session مدیر، method، pagination، فیلدهای اجباری، sanitization و ثبت log درخواست استفاده می‌کند.

### API مدیریتی

| ماژول | قابلیت‌های شاخص |
|---|---|
| `api/panels.php` | فهرست، جزئیات، افزودن/ویرایش/حذف پنل و inbound |
| `api/product.php` | فهرست/جزئیات، افزودن/ویرایش/حذف محصول، inbound/proxy |
| `api/category.php` | فهرست، افزودن، ویرایش و حذف دسته‌بندی |
| `api/discount.php` | تخفیف عمومی و تخفیف فروشنده |
| `api/invoice.php` | فاکتور، سرویس، حذف/تغییر وضعیت و تمدید مدیریتی |
| `api/payment.php` | فهرست پرداخت و جزئیات پرداخت |
| `api/users.php` | کاربران، block/verify/status، موجودی، withdrawal، agent، تخفیف و استثناهای عضویت |
| `api/service.php` | فهرست سرویس‌ها |
| `api/settings.php` | خواندن/ذخیره تنظیمات و shop configuration |

Pagination به‌صورت پیش‌فرض محدود است و حداکثر ثبت‌شده در utils برابر ۱۰۰۰ است. tokenهای API از config و فایل hash پشتیبانی می‌کنند؛ secretهای واقعی نباید داخل مستند یا commit عمومی قرار بگیرند.

## ۱۲. پنل مدیریت وب و نقش‌ها

### ورود و session

[`panel/login.php`](../panel/login.php) از session، regenerate کردن شناسه session، CSRF token و `password_verify` استفاده می‌کند. حداکثر تلاش‌های login بر اساس IP در بازه زمانی محدود می‌شود؛ گذرواژه‌های قدیمی plaintext در صورت امکان به hash Bcrypt مهاجرت داده می‌شوند.

### صفحه‌ها و عملیات

پنل وب داشبوردی از تعداد کاربران، سرویس‌های فعال، فروش، پرداخت‌های pending و تراکنش‌های روز دارد. صفحه‌های اصلی شامل این موارد هستند:

- کاربران و عملیات کاربر؛
- پنل‌ها و تنظیم اتصال؛
- محصولات، دسته‌بندی و inbound؛
- سرویس‌ها و فاکتورها؛
- پرداخت‌ها و بررسی رسید؛
- keyboard و تنظیمات فروشگاه؛
- متن‌ها، زبان و feature flagها.

در Telegram نیز نقش‌های `administrator`، `Seller` و `support` وجود دارد. تغییر تنظیمات حساس، مدیریت مدیران، درگاه‌ها و فعال‌سازی‌های گسترده به administrator محدود است؛ تأیید پرداخت برای administrator/Seller در مسیرهای مربوط مجاز است و support در برخی عملیات فقط مشاهده/پشتیبانی دارد.

نقشهٔ صفحه‌های وب از منوی [`panel/inc/layout_head.php`](../panel/inc/layout_head.php) به دست می‌آید:

| صفحه | کار اصلی |
|---|---|
| `panel/index.php` | داشبورد، آمار و خلاصهٔ فاکتورها/کاربران |
| `panel/users.php`, `user.php`, `user_action.php` | فهرست و جزئیات کاربر، موجودی، وضعیت، agent و عملیات فردی |
| `panel/invoice.php`, `service.php` | فاکتور و وضعیت سرویس |
| `panel/product.php`, `category.php` | محصول، دسته‌بندی و تنظیمات فروش |
| `panel/payment.php` | فهرست پرداخت‌ها و فیلتر وضعیت تحویل (`processing`، `failed`، `fulfilled`، `refunded`) |
| `panel/keyboard.php`, `bottext.php` | ترتیب دکمه‌های ربات و متن‌های قابل ویرایش |
| `panel/settings.php` | تنظیمات عمومی و حساب مدیر |

صفحه‌ها از `panel/inc/config.php` برای bootstrap و session و از `panel/inc/layout_head.php`/`layout_foot.php` برای چیدمان مشترک استفاده می‌کنند. فایل‌های `panel/js/` و `panel/css/` رابط را تشکیل می‌دهند؛ منطق معتبرسازی نهایی و تغییر DB در PHP/API است.

## ۱۳. Cronها و عملیات خودکار

تابع `activecron()` در [`function.php`](../function.php) ابتدا cronهای قدیمی تک‌فایلی را حذف می‌کند و فقط یک فرمان دقیقه‌ای برای [`cronbot/run.php`](../cronbot/run.php) ثبت می‌کند. dispatcher زمان‌بندی jobها را از [`cronbot/jobs.php`](../cronbot/jobs.php) می‌خواند و jobهای موعدرسیده را اجرا می‌کند. جدول زیر از رجیستری نسخهٔ فعلی استخراج شده است:

| فایل | تناوب | مسئولیت |
|---|---:|---|
| `cronbot/statusday.php` | هر ۱۵ دقیقه | وضعیت/گزارش روزانه |
| `cronbot/croncard.php` | هر ۱ دقیقه | رسید و auto-confirm کارت/آفلاین |
| `cronbot/NoticationsService.php` | هر ۱ دقیقه | هشدار حجم/زمان و انقضا |
| `cronbot/payment_expire.php` | هر ۵ دقیقه | expire کردن پرداخت‌های قدیمی |
| `cronbot/sendmessage.php` | هر ۱ دقیقه | صف پیام‌ها و پاک‌سازی‌های مرتبط |
| `cronbot/plisio.php` | هر ۳ دقیقه | polling پرداخت Plisio |
| `cronbot/activeconfig.php` | هر ۱ دقیقه | فعال‌سازی configهای مشخص‌شده |
| `cronbot/disableconfig.php` | هر ۱ دقیقه | غیرفعال‌سازی configهای مدیریتی |
| `cronbot/iranpay1.php` | هر ۱ دقیقه | بررسی پرداخت‌های IranPay1 |
| `cronbot/backupbot.php` | ساعت‌های ۰، ۵، ۱۰، ۱۵ و ۲۰ | ساخت archive و dump دیتابیس و ارسال backup |
| `cronbot/gift.php` | هر ۲ دقیقه | اعمال هدیه حجم/زمان |
| `cronbot/expireagent.php` | هر ۳۰ دقیقه | پایان agent و بازگردانی نقش |
| `cronbot/on_hold.php` | هر ۱۵ دقیقه | reminder و مدیریت on-hold |
| `cronbot/configtest.php` | هر ۲ دقیقه | بررسی configهای test |
| `cronbot/uptime_node.php` | هر ۱۵ دقیقه | سلامت node |
| `cronbot/uptime_panel.php` | هر ۱۵ دقیقه | سلامت panel |
| `cronbot/lottery.php` | هر ۱ دقیقه در صورت فعال بودن `scorestatus` | قرعه‌کشی و امتیاز |

`run.php` با فایل `.run.lock` اجرای هم‌زمان همان نصب را محدود می‌کند، برای حداکثر سه جایگاه سراسری هاست تلاش می‌کند و آخرین زمان/خطای هر job را در `storage/cron_status.json` می‌نویسد. فرمان cron با تأخیر ثابت صفر تا ۱۹ ثانیه بر اساس دامنه ساخته می‌شود. [`cronbot/migrate-crontab.php`](../cronbot/migrate-crontab.php) ابزار CLI برای مهاجرت cronهای قدیمی است؛ حالت پیش‌فرض آن dry-run است و فقط `--apply` crontab را تغییر می‌دهد.

پرداخت‌های callbackمحور نیز فایل‌های مستقل زیر `payment/` دارند؛ مانند `zarinpal.php`، `aqayepardakht.php`، `iranpay1.php`، `iranpay2.php`، `nowpayment.php`، `tetraminator.php` و `uniquepay.php`.

نکته عملی: در کد `payment_expire.php` مقدار زمانی بررسی‌شده حدود ۸۶۴۰۰ ثانیه است؛ نام متغیرهای داخلی ممکن است «ماه» را تداعی کند، اما تصمیم واقعی بر اساس همین مقدار یک‌روزه در snapshot فعلی است. اگر سیاست کسب‌وکار تغییر کرده، این فایل باید جداگانه بازبینی شود.

## ۱۴. امنیت، صحت داده و قابلیت اطمینان

### کنترل‌های موجود

- درخواست‌های API مدیریتی با token و در برخی مسیرها session مدیر محافظت می‌شوند.
- Telegram Web App با HMAC و اعتبار زمانی بررسی می‌شود.
- مقایسه secretها در مسیرهای مهم با `hash_equals` انجام می‌شود.
- login پنل rate limit، session regeneration، CSRF و password hash دارد.
- دسترسی دیتابیس از PDO، prepared statement، `ERRMODE_EXCEPTION`، `FETCH_ASSOC` و `utf8mb4` استفاده می‌کند؛ این تنظیمات در [`config.php`](../config.php) تعریف شده‌اند.
- `claimPaymentPaid` بروزرسانی paid را به شرط paid نبودن محدود می‌کند.
- Mini App مبلغ، product، agent، location و محدوده custom را سمت سرور دوباره بررسی می‌کند.
- در خطای ساخت سرویس، refund در مسیر Mini App و `DirectPayment` در نظر گرفته شده است.
- wrapper Telegram، timeout، اعتبار chat ID، لاگ خطا و بررسی پاسخ JSON دارد.
- update تکراری با cache فایل و TTL محدود کنترل می‌شود.
- user blocked و ضداسپم از پردازش بی‌رویه جلوگیری می‌کنند.

### نکات عملیاتی امنیتی

1. مقادیر `$APIKEY`، token ربات، گذرواژه DB، merchant key و secret درگاه فقط در config امن نگهداری شوند.
2. callback تمام درگاه‌ها باید HTTPS و فقط به endpoint همان نصب اشاره کند.
3. endpointهای API و فایل‌های داده/backup نباید از وب عمومی قابل دانلود باشند.
4. برای gatewayهایی که callback امضاشده دارند، token/HMAC باید قبل از تغییر وضعیت order بررسی شود.
5. `id_order` و status پرداخت باید در گزارش‌ها با هم تطبیق داده شوند؛ صرفاً اعتماد به مقدار برگشتی کلاینت کافی نیست.
6. بعد از تغییر schema یا code پرداخت، retry و callback تکراری باید با یک order آزمایشی بررسی شود.
7. backup دوره‌ای فقط زمانی مفید است که restore آن نیز به‌صورت دوره‌ای آزمایش شود.

## ۱۵. نصب و پیکربندی

README محیط پیشنهادی را Ubuntu 22.04/24.04، دامنه، PHP 8.2، Apache، MySQL و SSL معرفی می‌کند. `composer.json` حداقل PHP `8.2` و وابستگی‌هایی مانند Endroid QR و PhpSpreadsheet را تعریف می‌کند.

نصب‌کننده رسمی README از این مسیر معرفی شده است:

```bash
curl -o install.sh -L https://raw.githubusercontent.com/Rezah95/mirzabot/master/install.sh && bash install.sh
```

منوی نصب/به‌روزرسانی/حذف، migration، تمدید SSL و مدیریت نسخه را پوشش می‌دهد. مقادیر ضروری `config.php` شامل host/name/user/password دیتابیس، API key ربات، شناسه مدیر، domain و username ربات است؛ شناسهٔ کانال گزارش در `setting.Channel_Report` نگهداری می‌شود. timeout درخواست پنل برای نصب‌هایی که latency بالا دارند قابل تنظیم است.

بعد از نصب باید این موارد کنترل شوند:

- webhook و دسترسی HTTPS؛
- token ربات و شناسه کانال گزارش؛
- اتصال دیتابیس و collation `utf8mb4`؛
- تنظیم پنل و تست ساخت یک کاربر؛
- فعال‌سازی درگاه‌ها و callback؛
- اجرای `activecron()` و مشاهده logها؛
- تست Mini App و اعتبارسنجی Telegram init data؛
- restore آزمایشی backup.

## ۱۶. سناریوهای کامل عملیاتی

### سناریو A: خرید سرویس در Mini App

```text
ورود Telegram Web App
  → verify init data / Bearer token
  → دریافت countries و categories
  → دریافت services یا custom_price
  → ارسال purchase
  → اعتبارسنجی دوباره product و قیمت
  → کسر موجودی
  → ساخت username و invoice
  → createUser روی panel
  → ارسال QR/link/config
  → affiliate/score/report
```

اگر هر مرحله بعد از کسر موجودی شکست بخورد، refund و خطای قابل گزارش باید انجام شود.

### سناریو B: شارژ با کارت‌به‌کارت

```text
کاربر مبلغ را انتخاب می‌کند
  → keyboard قواعد نمایش درگاه را اعمال می‌کند
  → Payment_report = Unpaid
  → شماره کارت و مبلغ ارسال می‌شود
  → کاربر رسید می‌فرستد
  → waiting / بررسی مدیر
  → تأیید اتمیک order
  → DirectPayment
  → شارژ کیف پول یا اجرای operation داخل id_invoice
```

در صورت رد، وضعیت `reject` و توضیح بررسی ثبت می‌شود. `payment_expire` رکوردهای `Unpaid` قدیمی‌تر از حدود یک روز را expire می‌کند؛ وضعیت‌های دیگر در شرط همان job نیستند.

### سناریو C: پرداخت درگاه آنلاین

```text
ساخت order و URL gateway
  → بازگشت کاربر به callback
  → verify با provider
  → تطبیق مبلغ و شناسه
  → claimPaymentPaid
  → DirectPayment
  → cashback/report/پیام نتیجه
```

درگاه‌های رمزارزی ممکن است به‌جای callback، با cron polling شوند. Telegram Stars مسیر pre-checkout و successful payment مخصوص خود را دارد.

### سناریو D: هشدار و انقضا

```text
NoticationsService
  → انتخاب invoiceهای active-like
  → بررسی حجم/زمان و throttle
  → ارسال warning
  → پایان مهلت
  → حذف/disable روی panel
  → تغییر وضعیت invoice
  → اطلاع به کاربر و گزارش
```

## ۱۷. راهنمای توسعه و نگهداری

### افزودن پنل جدید

1. adapter مستقل با naming و قرارداد مشابه adapterهای موجود ایجاد شود.
2. نوع پنل در dispatchهای `ManagePanel` برای create/read/update/remove/extend ثبت شود.
3. رفتار subscription link، config، QR و error response مشخص شود.
4. عملیات ساخت، تمدید، افزایش حجم/زمان، disable و remove با یک پنل آزمایشی تست شود.
5. اثر cronهای اعلان، on-hold، health check و backup بررسی شود.

### افزودن درگاه جدید

1. setting فعال/غیرفعال، حداقل/حداکثر، callback URL و secret تعریف شود.
2. در ساخت `Payment_report`، method و `id_invoice` دقیق و قابل بازیابی ذخیره شود.
3. callback یا polling، order، مبلغ، status و امضای provider را validate کند.
4. انتقال به paid اتمیک و idempotent باشد.
5. `DirectPayment` برای wallet/config/renew/volume/time context تست شود.
6. callback تکراری، پرداخت منقضی، mismatch مبلغ و خطای ساخت panel تست شوند.

### تغییر rule نمایش درگاه

قواعد نمایش در `keyboard.php` صرفاً UI نیستند. همان business rule باید در handler ثبت پرداخت و endpointهای مرتبط نیز enforce شود. مخصوصاً برای قواعدی مانند «حداقل دو پرداخت موفق»، count و status باید از دیتابیس خوانده شوند و به پارامتر کلاینت اعتماد نشود.

### تغییر قیمت و catalog

قیمت باید در server-side از product و setting محاسبه شود. برای custom price، حداقل/حداکثر حجم و زمان، agent، `Manualsale`، `one_buy_status` و تخفیف کاربر هم در لایه نمایش و هم در `mini_purchase` بررسی شوند.

## ۱۸. ابهام‌ها و ریسک‌های شناخته‌شده در snapshot

- Graphify برخی edgeها را inferred اعلام می‌کند و nodeهای JavaScript فشرده، نام‌های عمومی مانند `select` و `sendJsonResponse` را به hub تبدیل کرده‌اند؛ برای تصمیم امنیتی یا تغییر قرارداد، باید خود کد PHP بررسی شود.
- درخت `vpnbot/Default` و `vpnbot/update` مسیرهای قدیمی/جایگزین دارد. تغییر در آن‌ها لزوماً مسیر runtime ریشه را تغییر نمی‌دهد.
- نام `paymentexits` به‌تنهایی نشان نمی‌دهد که شمارش «خرید» است؛ query واقعی شمارش `Payment_report`های paid است.
- کارت‌به‌کارت شرط حداقل دو پرداخت ندارد، اما IranPay3 دارد. این دو rule نباید در UI یا مستند محصول با هم ادغام شوند.
- بعضی callbackها علاوه بر claim، منطق cashback/report مخصوص همان provider دارند. هنگام refactor باید order idempotency و عدم دوباره‌شارژ کیف پول حفظ شود.
- gatewayها و adapterهای پنل وابسته به سرویس بیرونی، credential و schema پاسخ provider هستند؛ تست unit بدون mock کافی نیست و تست integration لازم است.
- `payment_expire` بر اساس مقدار زمانی موجود در کد، حدود یک روز را بررسی می‌کند؛ سیاست کسب‌وکار باید با این مقدار تطبیق داده شود.
- شِمای ماژولار جدید در `db/tables/` و مهاجرت‌های `db/migrations/` وجود دارد، اما `table.php` قدیمی نیز همچنان در مسیر نصب shell و بعضی مسیرهای بروزرسانی استفاده می‌شود. هر تغییر شِما باید در هر دو مسیر نصب بررسی شود.
- ستون `zarinpalpayment`، تنظیمات gate زرین‌پال و کلید IPN ترونادو در شِمای ماژولار افزوده شده‌اند. چهار فایل زبان موجود `fa`، `en`، `ru` و `zh` هستند و کد `ar` به `fa` برمی‌گردد.

## ۱۹. چک‌لیست پذیرش و تست رگرسیون

### کاربران و دسترسی

- [ ] کاربر جدید ساخته و پیام ثبت‌نام گزارش می‌شود.
- [ ] نصب تازه با wizard وب ستون‌های لازم ثبت‌نام، از جمله `zarinpalpayment`، را دارد و `api/users.php` بدون خطای ستون اجرا می‌شود.
- [ ] referral معتبر ثبت و self-referral رد می‌شود.
- [ ] blocked، عضویت کانال و verification درست اعمال می‌شوند.
- [ ] ضداسپم کاربر را بیش از حد مجاز block می‌کند.

### catalog و سرویس

- [ ] محصول فقط برای panel/location/agent صحیح نمایش داده می‌شود.
- [ ] `hide_panel` و `one_buy_status` هم در نمایش و هم در purchase اعمال می‌شوند.
- [ ] custom price خارج از min/max رد می‌شود.
- [ ] تخفیف، موجودی، بدهی agent و refund درست محاسبه می‌شوند.
- [ ] ساخت سرویس روی هر adapter پشتیبانی‌شده، لینک/QR/config صحیح می‌دهد.

### پرداخت

- [ ] پرداخت Unpaid، waiting، paid، reject و expire قابل ردیابی است.
- [ ] مسیر `iranpay2` با callback CubePay و مسیر `tronadopay` با سفارش v5 و IPN امضاشدهٔ ترونادو، هر کدام جداگانه در محیط عملیاتی آزمایش می‌شوند.
- [ ] callback تکراری باعث دوباره‌شارژ یا دوباره‌ساختن سرویس نمی‌شود.
- [ ] mismatch مبلغ و signature رد می‌شود.
- [ ] کارت‌به‌کارت فقط بر اساس setting و `cardpayment` نمایش داده می‌شود.
- [ ] gate مرحله اول با صفر پرداخت موفق بررسی می‌شود.
- [ ] IranPay3 با یک پرداخت و با دو پرداخت موفق تست می‌شود.
- [ ] اگر نیاز محصول «حداقل دو خرید برای کارت‌به‌کارت» است، handler سمت سرور نیز تغییر کرده باشد.

### cron و عملیات مدیر

- [ ] cronها نصب و بدون اجرای هم‌زمان مخرب اجرا می‌شوند.
- [ ] اعلان حجم/زمان throttled است.
- [ ] انقضا و disable روی پنل و invoice هر دو اعمال می‌شوند.
- [ ] backup ساخته و restore می‌شود.
- [ ] نقش administrator، Seller و support جداگانه تست می‌شود.

## ۲۰. نقشهٔ پوشه‌ها و ورودی‌های اجرا

| مسیر | نقش و نقطهٔ ورود | وابستگی/خروجی اصلی |
|---|---|---|
| [`index.php`](../index.php)، [`admin.php`](../admin.php)، [`keyboard.php`](../keyboard.php) | webhook ربات اصلی، state machine کاربران و مدیران، ساخت دکمه‌ها | `config.php`، `function.php`، Telegram API، دیتابیس |
| [`botapi.php`](../botapi.php)، [`request.php`](../request.php)، [`jdf.php`](../jdf.php) | wrapper تلگرام، درخواست HTTP و تاریخ جلالی | پاسخ API و متن/رسانه |
| [`panels.php`](../panels.php) و adapterهای ریشه | قرارداد واحد `ManagePanel` برای پنل‌های VPN | API پنل بیرونی، لینک و کانفیگ |
| [`api/`](../api/) | endpointهای JSON مدیریت و Mini App | token مدیریتی یا Bearer کاربر، دیتابیس |
| [`panel/`](../panel/) | رابط وب مدیریت، login، صفحه‌ها و assetهای CSS/JS | session مدیر، APIهای مدیریت |
| [`app/`](../app/) | Mini App آمادهٔ انتشار؛ `index.php` صفحهٔ HTML و فایل‌های hashدار JS/CSS و فونت | `api/verify.php` و `api/miniapp.php` |
| [`payment/`](../payment/) | برگشت کاربر، callback و webhook درگاه‌ها | `Payment_report` و `DirectPayment` |
| [`cronbot/`](../cronbot/) | dispatcher و jobهای زمان‌بندی‌شده | اعلان، تسویه، انقضا، backup و سلامت |
| [`db/`](../db/) و [`table.php`](../table.php) | مسیر ماژولار و مسیر قدیمی ساخت/تغییر جداول | MySQL/MariaDB |
| [`install.sh`](../install.sh)، [`install/`](../install/) | نصب VPS و راه‌انداز وب هاست اشتراکی | config، جداول، cron، webhook |
| [`sub/index.php`](../sub/index.php) | دریافت محتوای subscription از روی شناسهٔ فاکتور | `invoice` و `ManagePanel->DataUser()` |
| [`vpnbot/`](../vpnbot/) | کپی‌های ربات ساخته‌شده برای agent؛ `Default` الگو و `update` نسخهٔ جایگزین | جدول `botsaz` و توابع ریشه |
| [`lang/`](../lang/) | ترجمه‌های `fa`، `en`، `ru` و `zh` و overrideهای نصب | `languagechange()` و متن کیبورد |
| [`storage/`](../storage/) | وضعیت runtime مانند cron، cache و فایل‌های موقت | قابل نوشتن برای فرایند PHP |

`app/assets/` خروجی build فرانت‌اند است؛ سورس React/Vite در همین snapshot دیده نمی‌شود. ویرایش مستقیم فایل‌های hashدار پس از انتشار با تغییر نام assetها و cache همراه است. `.htaccess` ریشه و پوشه‌ها دسترسی وب به فایل‌های حساس و listing را محدود می‌کنند و در زمان وجود `install/index.php`، سایر مسیرهای سایت را می‌بندند.

متن‌های پایه از `lang/<code>.php` در `languagechange()` بارگذاری می‌شوند. `bottext_apply_overrides()` متن‌های ویرایش‌شدهٔ نصب را روی آن‌ها می‌نشاند؛ منوی اصلی نیز از JSON ستون `setting.keyboardmain` ساخته می‌شود. بنابراین تغییر متن و جابه‌جایی دکمه‌ها لزوماً نیاز به ویرایش فایل زبان ندارد. ربات‌های ساخته‌شده زیر `vpnbot/Default` و `vpnbot/update` کد/تنظیمات محلی خود را با `function.php`، `panels.php` و دیتابیس ریشه ترکیب می‌کنند؛ رکورد `botsaz` و webhook مخصوص agent هویت هر نمونه را تعیین می‌کند.

## ۲۱. قرارداد endpointهای داخلی

### احراز هویت و شکل درخواست

- APIهای مدیریت مانند `users.php`، `panels.php` و `payment.php` از `apiRequestContext()` استفاده می‌کنند: JSON body خوانده می‌شود، action از کلید `actions` می‌آید و header `Token` با مقدار `api/hash.txt` یا در نبود آن با `$APIKEY` مقایسه می‌شود. این endpointها معمولاً حتی برای actionهای خواندنی با body JSON کار می‌کنند؛ متد مجاز داخل هر handler بررسی می‌شود.
- `api/keyboard.php` استثنا است: token یا session مدیر با نقش `administrator` را می‌پذیرد. `api/statbot.php` و `api/log.php` token می‌خواهند. `api/index.php` endpoint عملیاتی نیست و دسترسی مستقیم را 404 می‌کند.
- `api/verify.php` دادهٔ Telegram Web App و `auth_date` را بررسی و token کاربر را برمی‌گرداند. `api/miniapp.php` از `Authorization: Bearer <token>` استفاده می‌کند؛ شناسهٔ کاربر ارسالی کلاینت را با شناسهٔ رکورد token جایگزین می‌کند.
- پاسخ رایج مدیریت `{"status": true|false, "msg": "...", "obj": ...}` است. خروجی موفق `purchase` در Mini App شکل اختصاصی `success`، `order_id` و `service` دارد؛ مصرف‌کننده نباید همهٔ endpointها را یکسان parse کند.

### فهرست ماژول‌های مدیریت

| فایل | actionهای اصلی | داده/اثر |
|---|---|---|
| [`api/panels.php`](../api/panels.php) | `panels`, `panel`, `panel_add`, `panel_edit`, `panel_delete`, `set_inbounds`, `remove_inbounds` | پنل، اعتبار اتصال، inbound و proxy؛ رمز پنل از پاسخ فهرست/جزئیات حذف می‌شود |
| [`api/product.php`](../api/product.php) | `products`, `product`, `product_add`, `product_edit`, `product_delete`, `set_inbounds`, `remove_inbounds` | محصول، location، قیمت و پروتکل |
| [`api/category.php`](../api/category.php) | `categorys`, `category`, `category_add`, `category_edit`, `category_delete` | دسته‌بندی محصول |
| [`api/discount.php`](../api/discount.php) | `discounts`, `discount`, `discount_add`, `discount_delete`, `discount_sell_lists`, `discount_sell`, `discount_sell_delete`, `discount_sell_add` | تخفیف عمومی و فروشنده |
| [`api/invoice.php`](../api/invoice.php) | `invoices`, `services`, `invoice`, `remove_service`, `invoice_add`, `change_status_config`, `extend_service_admin` | فاکتور و عملیات مدیریتی سرویس |
| [`api/payment.php`](../api/payment.php) | `payments`, `payment` | گزارش تراکنش‌ها و جزئیات سفارش |
| [`api/service.php`](../api/service.php) | `services` | فهرست سرویس‌ها |
| [`api/users.php`](../api/users.php) | `users`, `user`, `user_add`, `block_user`, `verify_user`, `change_status_user`, `add_balance`, `withdrawal`, `send_message` و actionهای agent/affiliate/نمایش درگاه | کاربران، موجودی، نقش و استثناها |
| [`api/settings.php`](../api/settings.php) | `keyboard_set`, `setting_info`, `save_setting_shop` | چیدمان منو و تنظیمات فروشگاه |
| [`api/keyboard.php`](../api/keyboard.php)، [`api/statbot.php`](../api/statbot.php)، [`api/log.php`](../api/log.php) | بدون router مشترک | فهرست دکمه‌ها، آمار و گزارش API |

برای تمام actionها، قرارداد دقیق فیلد اجباری و متد را باید از همان handler خواند. `api/utils.php` اعتبارسنجی متد، ورودی‌های الزامی، صفحه‌بندی با پیش‌فرض ۵۰ و سقف ۱۰۰۰، پاک‌سازی ورودی و ثبت `logs_api` را متمرکز کرده است.

`api/users.php` علاوه بر عملیات پایه، actionهای `accept_number`، `set_limit_test`، `transfer_account`، `join_channel_exception`، `cron_notif`، `manage_show_cart`، `manage_show_zarinpal` و `zero_balance` را برای کنترل حساب دارد. گروه دعوت/نماینده شامل `affiliates_users`، `remove_affiliates`، `remove_affiliate_user`، `set_agent`، `set_expire_agent`، `set_becoming_negative`، `set_percentage_discount`، `active_bot_agent`، `remove_agent_bot`، `set_price_volume_agent_bot`، `set_price_time_agent_bot`، `SetPanelAgentShow` و `SetLimitChangeLocation` است. املای بزرگ/کوچک و غلط‌های تاریخی مثل `categorys` بخشی از نام action فعلی‌اند و در کلاینت باید همان مقدار ارسال شود.

### مسیرهای عمومی وب

| endpoint | ورودی | نتیجه |
|---|---|---|
| `/index.php` | Telegram update و webhook secret نصب | پردازش پیام/دکمه/پرداخت ربات |
| `/app/` | Telegram WebView | رابط Mini App آمادهٔ build |
| `/api/verify.php` | init data تلگرام | شناسایی کاربر و token |
| `/api/miniapp.php` | `actions` در GET یا JSON POST + Bearer | سرویس، کاتالوگ، پروفایل و خرید |
| `/panel/login.php` | session و فرم ورود | ورود مدیر وب |
| `/sub/<id_invoice>` | شناسهٔ فاکتور در URL | متن لینک‌های subscription؛ در نبود فاکتور `ERROR!` |
| `/payment/*.php` | شناسهٔ سفارش و دادهٔ برگشت/وبهوک | verify و سپس تسویهٔ سفارش |

## ۲۲. مرجع شِما و مهاجرت دیتابیس

### مسیر ایجاد و ارتقا

[`db/bootstrap.php`](../db/bootstrap.php) شیء `Schema` را می‌سازد، جدول‌ها را به ترتیب [`db/tables.php`](../db/tables.php) اعمال می‌کند، migrationهای شماره‌دار را اجرا و ایندکس‌های [`db/indexes.php`](../db/indexes.php) را اضافه می‌کند. تعریف هر جدول در `db/tables/<Table>.php` شامل SQL ساخت، ستون‌های الحاقی، seed و گاهی `after` است. `Schema` وجود جدول/ستون/ایندکس را از `information_schema` می‌سنجد و خطای هر مورد را log کرده و به نصب‌کننده منتقل می‌کند.

[`table.php`](../table.php) مسیر قدیمی و بزرگ bootstrap است که هنوز `install.sh` هنگام نصب و بروزرسانی اجرا می‌کند و اکنون `db/bootstrap.php` را نیز فراخوانی می‌کند. wizard وب از `db/bootstrap.php` استفاده می‌کند. تغییر شِما در هر دو مسیر باید بررسی شود.

ستون `zarinpalpayment`، تنظیمات gate زرین‌پال، ردیف‌های درگاه‌های اختصاصی و `Tronado_callback` در شِمای ماژولار موجودند. migration شمارهٔ ۰۱۳ برای سفارش‌های فاقد تکرار، شاخص یکتا می‌سازد؛ اگر شناسه یا پیشوند ۱۹۱ نویسه‌ای تکراری باشد، رکوردها را خودکار دست‌کاری نمی‌کند و رفع داده را به آشتی دستی واگذار می‌کند.

### گروه‌بندی جدول‌ها

| حوزه | جدول‌ها | مالکیت داده |
|---|---|---|
| هویت، مدیریت و پشتیبانی | `user`, `admin`, `channels`, `departman`, `support_message`, `help`, `topicid` | کاربر Telegram، مدیر، عضویت، تیکت/پیام و موضوع گزارش |
| کاتالوگ و سرویس | `marzban_panel`, `product`, `category`, `invoice`, `service_other`, `manualsell`, `cancel_service` | پنل، محصول، فاکتور، تاریخچه و فروش دستی |
| پرداخت و رشد | `Payment_report`, `PaySetting`, `card_number`, `Discount`, `DiscountSell`, `Giftcodeconsumed`, `affiliates`, `wheel_list`, `reagent_report`, `Requestagent` | تراکنش، درگاه، کارت، تخفیف، دعوت، قرعه‌کشی و درخواست agent |
| تنظیمات و زیرسامانه | `setting`, `shopSetting`, `botsaz`, `app`, `logs_api` | feature flag، متن/کیبورد، ربات‌های ساخته‌شده، Mini App و لاگ API |
| ثبت callback | `Tronado_callback` | حذف تکرار IPN ترونادو در نصب تازه و نصب قدیمی |

رابطهٔ اصلی فروش `user.id` ← `invoice.id_user` و `Payment_report.id_user` است. `invoice.Service_location` به نام/شناسهٔ مکان پنل متکی است و محصول از `product.Location` به پنل یا `/all` وصل می‌شود. `Payment_report.id_order` شناسهٔ تسویه و `id_invoice` آن context عملیات خرید/تمدید/شارژ را نگه می‌دارد؛ این فیلد در همهٔ مسیرها یک کلید خارجی ساده نیست. schema فعلی عمدتاً از `VARCHAR` برای برخی قیمت‌ها و زمان‌ها استفاده می‌کند؛ مقایسهٔ عددی/زمانی را باید مطابق تبدیل‌های کد انجام داد.

### migrationها و ایندکس‌ها

| نسخه | هدف |
|---|---|
| 001 و 009 | نرمال‌سازی کلیدهای روش تمدید و ساخت username پنل |
| 002 | پر کردن `code_panel`های خالی |
| 003 و 004 | اصلاح charset کارت و نوع چند ستون |
| 005 | بازسازی پیش‌فرض min/max پرداخت بر اساس نوع agent |
| 006، 010 و 011 | حذف فیلدهای قدیمی کاربر/تنظیمات و انتقال وضعیت دکمه‌ها به `keyboardmain` |
| 007 | حذف `departman` تکراری پیش از ایندکس یکتا |
| 008 | افزودن تنظیمات پیش‌فرض AbanGateway به نصب‌های موجود |
| 012 | آماده‌سازی اعتبارنامه‌های مدیر پنل |

ایندکس‌های کاربردی روی شناسهٔ کاربر/وضعیت/نام سرویس در `invoice`، شناسهٔ کاربر/سفارش/وضعیت در `Payment_report`، code پنل و محصول و شناسهٔ فروش دستی تعریف شده‌اند. [`db/indexes.php`](../db/indexes.php) مرجع نام و ستون دقیق ایندکس‌هاست.

## ۲۳. نصب، راه‌اندازی و ارتقا

### نصب روی سرور اختصاصی با shell

`install.sh` برای Ubuntu 22.04/24.04 و اجرای root طراحی شده است. منوی آن نصب، بروزرسانی، حذف، مهاجرت Free به Pro، تمدید SSL و راهنما را دارد؛ دستور CLI `mirza install|update|remove|migrate|renew` نیز در README توضیح داده شده است. پیش‌نیازهای runtime شامل PHP 8.2، Apache، MySQL/MariaDB، HTTPS و Composer است. `composer.json` وابستگی‌های Endroid QR و PhpSpreadsheet را تعریف می‌کند. اسکریپت shell فایل‌ها، دیتابیس، SSL، webhook، cron و وابستگی‌ها را آماده می‌کند و برای شِما از `table.php` استفاده می‌کند.

### نصب وب روی هاست بدون دسترسی shell

[`install/index.php`](../install/index.php) wizard مرحله‌ای است. ترتیب منطقی آن: بررسی PHP و افزونه‌ها، وب‌سرور/SSL و فایل‌ها؛ تست دیتابیس؛ بررسی token با `getMe`؛ نوشتن `config.php`؛ bootstrap جداول از `db/bootstrap.php`؛ نمایش یک فرمان cron dispatcher و اجرای probe؛ حذف پوشهٔ نصب؛ ثبت Telegram webhook و پیام آغاز به مدیر. اگر `shell_exec` قابل استفاده نباشد، پایان نصب به probe موفق cron و تأیید مورد لازم وابسته است.

تا وقتی `install/index.php` وجود دارد، قواعد `.htaccess` درخواست‌های خارج از پوشهٔ نصب را رد می‌کنند. wizard برای نصب پیکربندی‌شده، احراز هویت session می‌خواهد و در پایان تلاش می‌کند پوشهٔ `install/` را حذف کند. اگر حذف نشود، webhook را غیرفعال می‌کند و دستور حذف دستی و فعال‌سازی مجدد ارائه می‌دهد. این رفتار بخشی از چرخهٔ نصب است؛ فایل نصب نباید بعد از راه‌اندازی روی وب باقی بماند.

### پیکربندی و کنترل پس از نصب

`config.php` متغیرهای اتصال DB، `$APIKEY`، شناسهٔ مدیر، دامنه، نام ربات و timeout درخواست را نگه می‌دارد. `setting` و `shopSetting` رفتار فروشگاه و `PaySetting` وضعیت، بازهٔ مبلغ، کارمزد و کلید درگاه‌ها را نگه می‌دارند. در آغاز باید اتصال DB، HTTPS، `getWebhookInfo` تلگرام، یک ساخت سرویس آزمایشی، cron دقیقه‌ای، `storage/cron_status.json`، پرداخت آزمایشی و restore backup کنترل شود. مقادیر محرمانه نباید در مستند یا log عمومی کپی شوند.

## ۲۴. عملیات، عیب‌یابی و بازیابی

| نشانه | مسیر بررسی |
|---|---|
| پیام ربات پاسخ نمی‌دهد | وجود `install/`، وضعیت webhook، secret، `error_log` و دسترسی Telegram API |
| Mini App ورود نمی‌کند | اعتبار و تازگی `initData` در `api/verify.php`، Bearer token کاربر و status کاربر |
| محصول دیده نمی‌شود | `marzban_panel.status`، `product.Location`، agent، `hide_panel`، دسته، زمان و `one_buy_status` |
| پول پرداخت شده ولی سرویس تحویل نشده | `Payment_report` بر اساس `id_order`، وضعیت `paid`، log callback و نتیجهٔ `DirectPayment`/`ManagePanel`؛ قبل از اجرای دستی، وجود invoice و سرویس بیرونی بررسی شود |
| اعلان یا انقضا اجرا نمی‌شود | crontab تک‌خطی dispatcher، `.run.lock`، `storage/cron_status.json`، تنظیمات `setting.cron_status` و log هر job |
| لینک اشتراک خالی است | شناسهٔ `/sub/`، فاکتور متناظر، پنل و خروجی `DataUser().links` |
| پنل وب ورود نمی‌دهد | `admin`، session، CSRF، rate limit IP و log خطا |

`cronbot/backupbot.php` archive را با `ZipArchive`، ابزار `zip` یا `PharData` می‌سازد و برای دادهٔ DB از dump استفاده می‌کند. backup بدون آزمون restore تضمین بازیابی نیست. هنگام خطای callback پس از claim شدن `paid`، تکرار همان callback لزوماً `DirectPayment` را دوباره اجرا نمی‌کند؛ باید وضعیت واقعی سرویس و فاکتور دستی آشتی داده شود. لاگ و فایل‌های وضعیت runtime زیر `storage/` و پوشه‌های cron نگهداری می‌شوند و از وب باید بسته باشند.

## ۲۵. انتشار، محدودیت‌ها و مسیر توسعه

[`composer.json`](../composer.json) حداقل PHP 8.2 و دو وابستگی اصلی QR/Spreadsheet را ثبت کرده است. workflow [`release.yml`](../.github/workflows/release.yml) روی tag یا اجرای دستی، PHP 8.2 و افزونه‌های لازم را آماده می‌کند، `composer install --no-dev` می‌زند و ZIP میزبانی را به release پیوست می‌کند. در ZIP، `.git`، workflow، `graphify-out`، `.gitignore` و `install.sh` حذف می‌شوند؛ `vendor/` نصب‌شده داخل بسته می‌ماند. در مخزن، `vendor/` و فایل‌های runtime در `.gitignore` هستند.

کد PHP عمدتاً monolith تابعی است و تست خودکار جامع در این snapshot دیده نمی‌شود. برای تغییر پنل، درگاه، قیمت یا cron باید مسیر UI/handler، DB، callback یا job و اثر آن روی سرویس بیرونی با هم بررسی شوند. دو مسیر `table.php` و `db/bootstrap.php` همچنان نیازمند کنترل سازگاری در هر تغییر شِما هستند. سند قابلیت‌های معرفی‌شده در README را از رفتار قطعی همهٔ نصب‌ها جدا می‌کند؛ فعال بودن هر گزینه به تنظیمات و سرویس بیرونی وابسته است.

## ۲۶. نقشه ارجاع سریع فایل‌ها

| موضوع | مرجع |
|---|---|
| معماری و نصب | [`README.md`](../README.md) |
| خروجی و محدودیت گراف | [`graphify-out/GRAPH_REPORT.md`](../graphify-out/GRAPH_REPORT.md) |
| bootstrap و state machine ربات | [`index.php`](../index.php) |
| Telegram API و update handling | [`botapi.php`](../botapi.php) |
| دیتابیس، پرداخت و تحویل سرویس | [`function.php`](../function.php) |
| قواعد نمایش keyboard و درگاه | [`keyboard.php`](../keyboard.php) |
| abstraction پنل | [`panels.php`](../panels.php) |
| احراز هویت Web App | [`api/verify.php`](../api/verify.php) |
| catalog و خرید Mini App | [`api/miniapp.php`](../api/miniapp.php) |
| احراز هویت/پاسخ API | [`api/utils.php`](../api/utils.php) |
| API پنل/محصول/فاکتور/کاربر | [`api/panels.php`](../api/panels.php)، [`api/product.php`](../api/product.php)، [`api/invoice.php`](../api/invoice.php)، [`api/users.php`](../api/users.php) |
| ورود و داشبورد مدیریت | [`panel/login.php`](../panel/login.php)، [`panel/index.php`](../panel/index.php) |
| پرداخت کارت/انقضا/اعلان | [`cronbot/croncard.php`](../cronbot/croncard.php)، [`cronbot/payment_expire.php`](../cronbot/payment_expire.php)، [`cronbot/NoticationsService.php`](../cronbot/NoticationsService.php) |
| callbackهای درگاه | پوشه [`payment/`](../payment/) |

### جمع‌بندی نهایی

Mirza Bot یک monolith PHP با سه رابط کاربری و یک abstraction چندپنلی است. نقطه اتصال تقریباً همه جریان‌های مالی، `Payment_report`، `claimPaymentPaid` و `DirectPayment` است؛ نقطه اتصال همه عملیات VPN نیز `ManagePanel` است. برای تحلیل یا تغییر هر قابلیت، باید هر دو زنجیره هم‌زمان بررسی شوند: **قواعد نمایش/ورودی** و **اعتبارسنجی و اجرای سمت سرور**.

برای پیگیری هر قابلیت، ابتدا جدول‌ها و action مربوط را در بخش‌های ۲۱ و ۲۲ پیدا کنید؛ سپس مسیر ورودی، منطق پرداخت/سرویس و jobهای مرتبط را با هم بخوانید. خروجی Graphify مسیر شروع است و قرارداد نهایی از کد و تنظیمات نصب به دست می‌آید.
