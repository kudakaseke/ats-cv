# ATS CV Detector — original application connector

This is a WordPress connector for the existing application in this repository. It is **not a standalone native WordPress port**. The original Laravel application remains required. No interface, scoring, CV template, billing or subscription logic is replaced. The earlier `ats-cv-detector` plugin is a different native implementation and does not reproduce the original application exactly.

## Installation

1. Keep the original application running with its existing database, private storage, environment configuration, PHP dependencies, compiled frontend, mail, queue workers and scheduler. Its web server document root must point to Laravel's `public` directory. Do not upload Laravel's root, `.env`, private storage or database backups into the public WordPress plugins directory.
2. Install `ats-cv-original-1.0.0.zip` through WordPress Plugins → Add New → Upload Plugin, then activate it. This creates an **ATS CV Detector** page containing `[ats_cv_original]`. It does not alter existing WordPress pages or import customer data.
3. In Settings → ATS CV Original, enter the original application's public URL, for example `https://app.example.com`. Alternatively set `define('ATSCV_ORIGINAL_URL', 'https://app.example.com');` in `wp-config.php` before `wp-settings.php`. The constant overrides the saved setting. Set Laravel's `APP_URL` to its actual public URL. A private LAN address only works for visitors on that LAN.
4. Add the generated page to the WordPress navigation. Visiting it redirects to the original app in the full browser window. Its own layout, login, CV editor, downloads and Paynow checkout operate as before. WordPress theme styles do not modify the application.
5. Exclude the generated page from page caching and CDN redirect caching. The plugin sends a temporary redirect and no-cache headers. The configured destination cannot be the WordPress home page or the generated connector page.
6. Verify the actual deployment: register/verify/login; open existing CVs; import, edit and export each supported format; use ATS analysis/revamp; inspect credit deductions; start a Paynow test checkout; exercise successful, cancelled and failed payments, delayed callbacks, duplicate notifications and expiry; verify receipts and subscription access against the original app. Keep Laravel test mode enabled until merchant testing and public callbacks are confirmed. These live flows have not been newly certified by this connector's automated tests.

## What is preserved

All application behaviour comes from the original deployed Laravel source and data. Existing customers retain their original application accounts and passwords. Paystack remains hidden according to that application's existing configuration/code. Paynow remains configured in the Laravel `.env`; no provider or AI secrets are copied into this plugin. Existing pending payments remain governed by the original billing rules.

This does not create WordPress accounts for application customers, provide single sign-on, run Laravel within WordPress, migrate data, change payment return URLs or embed checkout in an iframe. Application logout and navigation retain their original destination. The browser address changes to the application URL. A WordPress installation alone cannot run this connector's application features.

For the application to stay at a path on the same public hostname, arrange an ordinary web-server reverse proxy or a separate Laravel virtual host with the hosting provider. That deployment requires routing, asset paths, forwarded headers and session-cookie validation; the plugin does not silently configure the server. Running the entire system natively inside WordPress requires a separate full port and equivalence testing.

## Maintenance

Update and back up the Laravel app and WordPress independently. Deactivation leaves the generated page and configuration in place; the shortcode no longer operates until reactivation. Deleting the plugin does not erase application or WordPress customer data. No real credentials, CV files or production database are included in the ZIP.
