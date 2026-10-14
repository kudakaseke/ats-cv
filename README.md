# ATS CV

Laravel 12 / PHP 8.2+, MySQL, Livewire 4, Blade, Tailwind CSS 4, Alpine, and Lucide. The application foundation and guest-first CV builder are implemented; remaining features are documented below.

## CV builder

Homepage **Get Started Free** opens `/get-started`: existing CV question, target role, template gallery, then the editor. There are 20 template styles across five layout families with role-based suggestions and category filters. Users can enter personal details, summary, experience, education, skills, achievements, courses, and languages; repeatable entries support adding, removing, and reordering. The preview updates while typing, and changing templates preserves the content.

Guests can edit before creating an account. Debounced draft saving uses the private Laravel session rather than browser local storage. Guest drafts last for the session, not indefinitely; signing in and verifying email attaches the draft to the account. **Done — save my CV** sends guests to login, with a registration option, and returns to the editor afterward. Saved CVs can be reopened under **My CVs**. Account ownership is checked on reads and updates.

PDF/DOCX imports extract reference text and an email address; users review and transfer the text to the appropriate fields. Imports do not infer qualifications or automatically map every section. Scanned PDFs need OCR and cannot be imported yet. Files are limited to 5 MB; extracted text and DOCX XML have size bounds, PDF decoding has limits, and import requests are throttled. Originals are not retained. Uploaded DOCX content is never executed, and XML entity declarations are rejected.

Verified account holders can use **Print / PDF** to open the browser print dialog and save as PDF. Editable Word export and AI rewriting remain future features. Run `npm.cmd run test:builder` for Chrome checks covering onboarding, templates, editing, mobile widths, escaping, draft reload, login handoff, saved CVs, and PDF import.

## Phase 1 features

- Responsive public homepage: Home, About, Features, Pricing, Sign In, Sign Up, how it works, FAQ, and footer.
- Plus Jakarta Sans with light 300-weight headings, blue `#346DE1`, and white page surfaces, following the supplied Sander screenshots and [jobs page](https://www.wearesander.com/en/jobs). The hero uses the reference’s blue background, large white/black headline, and pill actions. Three feature cards group the complete CV toolset, with thin blue outlines and opposite rounded corners. About follows the [about page](https://www.wearesander.com/en/about-us), with a large left heading and stacked right panels describing our tools and approach. How It Works and Pricing use open layouts rather than extra boxes.
- Laravel Breeze registration, login, logout, email verification, password recovery/reset, profile editing, password updates, and account deletion.
- Email verification is required for dashboard and workspace access. Authentication requests have server-enforced limits per IP, email address, and account, backed by the configured shared cache.
- Livewire dashboard with user-scoped database counts, recent CVs/analyses, latest estimated score, current plan, and remaining monthly allowance. New accounts show zero CVs/analyses and no invented match score.
- Configurable database-backed Free ($0), Basic ($3), and Pro ($7) plans. Active subscription dates are checked; pending and expired subscriptions never grant paid access. No live checkout or payment activation.
- Dedicated `ats_cv` MySQL database, migrations, idempotent plan seeder, models, relationships, indexes, cascading account-record deletion, and reusable plan/usage service.
- Privacy, terms, and contact pages. Operator contact and final legal text require review before public launch.
- The CV Builder and saved CV library are available. AI analysis and revamping have explicit upcoming-release screens; no invented score or company performance metrics are displayed.

## Local installation (PowerShell / XAMPP)

Start MySQL in XAMPP. Existing `.env` credentials are preserved; do not commit this file.

```powershell
composer install
npm.cmd ci
# On a new checkout only: Copy-Item .env.example .env
# On a new checkout only: php artisan key:generate
php scripts/create-database.php
php artisan migrate --seed
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
```

Open **http://127.0.0.1:8000**. Set `APP_URL` to your actual server URL. npm is called as `npm.cmd` to avoid the machine’s PowerShell script execution restriction.

Database creation uses Laravel’s environment configuration without printing credentials. `CREATE DATABASE IF NOT EXISTS` preserves any existing database. If your database account lacks creation privileges, create `ats_cv` manually and run migrations. No migration refresh or destructive reset is required.

For Vite hot reload, run `npm.cmd run dev` in another terminal. For XAMPP Apache, configure the virtual host document root to this project’s **public/** directory, enable rewrite support, and update `APP_URL`; never expose the project root or `.env`.

## Saving changes to GitHub

Use VS Code's Source Control panel to review your changes, enter a commit message, select **Commit**, then **Sync Changes** (or **Push**) to upload commits to the connected GitHub repository. Saving files locally does not upload them automatically.

From PowerShell in this project, the equivalent commands are:

```powershell
git status
git add .
git commit -m "Describe your changes"
git push
```

Review the changes before committing. `.env` and its variants, dependencies, generated assets, logs, uploaded files, and local database files are excluded. Keep `.env.example` as a template with placeholders; never add real passwords or API keys to it. A new checkout needs the local installation steps above.

## Environment settings

| Variable | Local setting / purpose |
| --- | --- |
| `APP_NAME` | `ATS CV` |
| `APP_URL` | `http://127.0.0.1:8000`, used for verification and reset links |
| `APP_TIMEZONE` | `Africa/Johannesburg` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST`, `DB_PORT` | `127.0.0.1`, `3306` |
| `DB_DATABASE` | `ats_cv` |
| `DB_USERNAME`, `DB_PASSWORD` | Your MySQL credentials; local XAMPP defaults must not be used in production |
| `MAIL_MAILER` | `log` locally; configure a real mail provider for delivery |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | Provider-specific email settings |
| `QUEUE_CONNECTION` | `database`; AI jobs arrive in Phase 2 |
| `FILESYSTEM_DISK` | `local`, rooted at `storage/app/private`; automatic signed local serving is disabled |
| `GEMINI_API_KEY`, `GEMINI_MODEL` | Reserved for Phase 2; no AI API calls occur in Phase 1 |

**Email verification:** after registration, enter the six-digit code from the email on `/verify-email`. Codes expire after 10 minutes, allow five incorrect attempts, and are stored as hashes tied to the account's email address. Sending is limited per recipient to one per minute, five per hour, and ten per day, including signup and failed delivery attempts. A shared cache lock makes reservation of email quotas atomic. Resending replaces the previous code. Mailtrap sandbox SMTP captures messages in Mailtrap rather than delivering to recipient inboxes; configure a sending SMTP service for real delivery. With the log mailer, read the code privately in `storage/logs/laravel.log`. Do not publish logs or verification/reset codes. No seeded user or shared default password is created.

**Authentication request limits:** login allows 20 requests per minute and 100 per hour per IP, and 10 per 15 minutes per normalized email across IPs. The existing five failed attempts per email/IP lockout remains. Verification submissions allow six per minute and 20 per hour per account, with a separate IP limit; resending has additional IP limits of ten per hour and 30 per day. Signup allows three requests per minute and ten per hour per IP. Password reset requests have IP limits and recipient limits of three per hour and five per day; existing and unknown accounts receive the same response text. Password reset submissions, password confirmation, and password updates are also throttled. Rate limits use the configured cache (database locally); use a shared persistent cache across production servers. HTTP 429 responses include a retry interval and a wait page. These controls reduce application abuse; deployment still requires HTTPS and infrastructure protection against large distributed traffic floods.

Plan prices and allowances are in `subscription_plans`. Seeders insert missing plans and preserve subsequent edits. The admin editing interface is scheduled for Phase 5. Monthly usage currently counts queued, processing, and completed analyses; failed jobs do not consume allowance. Atomic limit enforcement for job submissions belongs to Phase 2.

## Verification

```powershell
php artisan test
php vendor/bin/pint --test
npm.cmd run build
npm.cmd audit
composer audit
# With the local PHP server running and Google Chrome installed:
npm.cmd run test:browser
```

Backend tests use isolated in-memory SQLite, not the application’s MySQL data. The 33 tests cover auth, email verification, reset tokens, password/profile updates, account deletion, database pricing, ownership isolation, usage periods, pending/expired plans, and route access.

The browser smoke test uses real MySQL login with a temporary verified local fixture. It always deletes the fixture in `finally`. It checks desktop/mobile layout, no horizontal overflow at 320/390/768/1024/1440/1920 px, typography (including the 106 px desktop headline and 300 font weight), colours, icons, mobile menus, FAQ expansion, dashboard, profile modal, auth forms, legal pages, and JavaScript errors. It requires `APP_ENV=local` and Chrome. Override the URL with `$env:APP_TEST_URL = 'http://127.0.0.1:8000'`. Screenshots are saved in private `storage/app/previews/` and are ignored by Git. Reference-sized previews are `reference-home-1920.png`, `reference-features-1920.png`, and `reference-about-1920.png`.

Validated in the current workspace: migrations and seeding against MySQL; 33 passing tests (107 assertions); production build; browser smoke test; Composer and npm audits with zero advisories.

## Main created / modified files

| Area | Files |
| --- | --- |
| Authentication | `app/Http/Controllers/Auth/*`, `app/Http/Requests/Auth/LoginRequest.php`, `routes/auth.php`, `resources/views/auth/*` (Breeze) |
| Profile | `ProfileController.php`, `ProfileUpdateRequest.php`, `resources/views/profile/*` |
| Public website | `HomeController.php`, `welcome.blade.php`, `components/public-layout.blade.php`, `legal/*` |
| Workspace | `WorkspaceController.php`, `app/Livewire/Dashboard.php`, `livewire/dashboard.blade.php`, `dashboard.blade.php`, `workspace/*` |
| Reusable UI / design | `layouts/app.blade.php`, `layouts/guest.blade.php`, `components/brand.blade.php`, `components/icon.blade.php`, form components, `resources/css/app.css`, `resources/js/app.js`, `public/favicon.svg` |
| Database | `2026_10_13_000001_create_workspace_tables.php`, `SubscriptionPlanSeeder.php`, `DatabaseSeeder.php`, models for users/resumes/analyses/plans/subscriptions |
| Services / routes | `app/Services/PlanService.php`, `routes/web.php` |
| Config / dependencies | `.env` (untracked), `.env.example`, `composer.json/lock`, `package.json/lock`, `vite.config.js`, `tailwind.config.js`, `config/app.php`, `config/filesystems.php`, `.gitignore` |
| Checks / setup | `tests/Feature/WorkspaceTest.php`, Breeze auth/profile tests, `scripts/create-database.php`, `scripts/browser-smoke.mjs`, `scripts/browser-fixture.php` |

Commands executed during implementation include `composer require laravel/breeze --dev`, `php artisan breeze:install blade --no-interaction`, `composer require livewire/livewire`, npm dependency installation, MySQL setup/migrations/seeding, `git init`, production builds, automated tests, browser checks, Pint, and dependency audits. Both lockfiles are retained for reproducible installs.

## Next phases

1. **Complete:** project foundation, public design, MySQL, authentication, Livewire dashboard, profile, and subscription display.
2. **CV Checker:** upload validation (PDF/DOCX, 5 MB), private storage and ownership policies, extraction library, consent, Gemini HTTP service and validated structured responses, queues, atomic usage limits, reusable rule-based scoring, results/history/report. Weights: skills 35%, experience 25%, education 15%, responsibilities 15%, structure 10%.
3. **AI revamp:** preserve original, structured CV version model, evidence-based rewriting, editable review, shared renderer, selectable-text PDF and editable DOCX generation.
4. **CV Builder:** ten steps, repeatable and reorderable entries, autosave, live preview, shared template, exports, duplication and deletion.
5. **Admin/subscriptions:** roles/policies, Filament if compatible, plan/feature editing, user management, usage/error/payment statistics; live payments remain disabled until configured.
6. **Production validation:** document workflow tests, privacy/retention configuration, HTTPS, non-root DB credentials, `APP_DEBUG=false`, real email provider, supervised queue workers, storage permissions, backups, and deployment instructions.

The supplied sample CV document was not present in the workspace or message attachments. The described single-column structure is recorded as the template requirement; inspect the actual sample when available before implementing document rendering. Gemini integration, extraction, generated documents, full database child tables, ownership download policies, and the admin panel are **not implemented in Phase 1**.
