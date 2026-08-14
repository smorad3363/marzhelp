# معماری MarzHelp v2

## مالکیت داده

Marzban v4 مالک واحد schema است. migration با revision
`9f4a1c2d7e31` جدول‌های `marzhelp_*` را در همان دیتابیس Marzban ایجاد
می‌کند. MarzHelp فقط این جدول‌ها را می‌خواند یا دادهٔ اجرایی آن‌ها را تغییر
می‌دهد؛ `table.php` صرفاً سازگاری را بررسی و دادهٔ دیتابیس قدیمی را import
می‌کند و هیچ DDL اجرا نمی‌کند.

marker سازگاری در `marzhelp_metadata`:

- `source_id = smorad3363-marzban`
- `schema_version = 1`
- `minimum_marzhelp_version = 2`

installer و updater پیش از تغییر نصب، همین marker را بررسی می‌کنند.

## مهاجرت نسخه قدیمی

اگر `botDbName` قدیمی با دیتابیس Marzban متفاوت باشد، `table.php` چهار جدول
قدیمی `admin_settings`، `user_states`، `user_temporaries` و `admin_usage` را
داخل جدول‌های canonical import می‌کند. import در transaction اجرا، تعداد
ردیف‌ها بررسی و marker هش‌شده ثبت می‌شود. دیتابیس قدیمی حذف نمی‌شود، اما پس
از موفقیت دیگر source of truth نیست.

## سیاست و حسابداری

تمام مسیرهای ساخت، تمدید، ویرایش، فعال‌سازی، انتقال و حذف کاربر از
`app/utils/marzhelp_policy.py` در Marzban عبور می‌کنند. allowance با UPDATE
اتمیک مصرف می‌شود. محدودیت ترافیک نامحدود و حداکثر زمان روی نتیجه نهایی plan
اعمال می‌شوند.

هنگام حذف کاربر:

`refundable = max(data_limit - lifetime_used_traffic, 0)`

رکورد یکتای کاربر در `marzhelp_deleted_users` و رویداد یکتا در
`marzhelp_accounting_transactions` ثبت می‌شود. مصرف واقعی هرگز refund نمی‌شود.

## Backup

چون تمام داده‌ها داخل دیتابیس Marzban هستند، مسیر backup موجود Marzban بدون
backup جداگانه کافی است: در MySQL کل دیتابیس `marzban` dump می‌شود و در SQLite
کل فایل دیتابیس کپی می‌شود.

## دسترسی اجرایی

کاربر `marzhelp_app` فقط `SELECT`, `INSERT`, `UPDATE`, `DELETE` روی دیتابیس
Marzban دارد. MarzHelp مجوز ساخت/تغییر جدول، trigger یا event دریافت نمی‌کند.
همگام‌سازی inbound با DML و cron دارای lock انجام می‌شود.
