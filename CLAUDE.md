# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Acadoc is an academic document platform for a Ukrainian college (Konotop), used as a team/student project. It runs on Laravel 12 (PHP 8.2+), with Filament 5 as the admin panel. The UI text, comments, validation messages and commit messages are in **Ukrainian**. Keep writing new user-facing strings and commit messages in Ukrainian.

## Commands

- `composer setup`: full first-time setup (install, `.env`, key, migrate, npm build)
- `composer dev`: runs `artisan serve`, the queue listener, `pail` logs and Vite all at once
- `php artisan serve`: just the web server (the app lives under XAMPP at `G:\xampp\htdocs\acadoc`)
- `php artisan migrate --seed`: creates the tables and seeds `test@example.com`
- `composer test` or `php artisan test`: runs all tests
- `php artisan test --filter=TestName`: runs a single test
- `vendor/bin/pint`: code style (Laravel Pint)
- `php artisan shield:generate --all`: regenerates Filament Shield permissions and policies after you add a Filament resource

Local dev uses MySQL from XAMPP (`DB_DATABASE=acadoc`, user `root`, no password; see `INSTRUCTIONS.md`), even though `.env.example` defaults to sqlite. Tests run on in-memory sqlite (`phpunit.xml`). The PHP extensions `intl` and `zip` must be enabled in php.ini. `zip` is required by the norm-control checker.

## Architecture

There are two separate UIs that share the same `users` table and session auth:

1. **Public frontend** (`routes/web.php`, Blade views in `resources/views/`, layout `layouts/app.blade.php`)
   - The layout loads Tailwind and Alpine **from CDN**. It does not use the Vite build, which only covers `resources/css|js/app.*`.
   - Login is a modal on `/` that posts to `FrontendAuthController`. The named route `login` just redirects to `/`, so the `auth` middleware sends guests back to the home page.
   - **Norm-control** (`NormControlController`, behind `auth`) checks a student's `.docx` against ДСТУ 3008:2015 / ЄСКД and the college's rules. It opens the file with `ZipArchive` and runs regex checks over the raw OOXML, split into paragraphs with `preg_split('/(?=<w:p[ >])/')`. Text boxes (`w:txbxContent`, e.g. the ЄСКД frame) are stripped first, because their inner paragraphs would otherwise split the paragraph they are anchored in. Units: twips (÷56.7 → mm), font size in half-points (28 = 14pt), line spacing in 240ths when `lineRule=auto` (360 = 1.5).
   - Helper classes live in `app/Services/NormControl/`:
     - `WordStyles` resolves *effective* paragraph and run properties: direct formatting → character/paragraph style → `basedOn` chain → `docDefaults`, including theme fonts from `theme1.xml`. Always use it instead of regexing direct properties, since most formatting comes from styles.
     - `PageSetupChecks` checks every `<w:sectPr>` (A4, margins, page numbering via header/footer rels).
     - `CitationChecks` matches `[n]` citations against the reference list.
     - `TitlePageInfo` parses the title page (student, topic, group, year…).
     - `CaptionChecks` checks figure and table captions (format, numbering, placement next to the object via `objectNear()` in the controller) and text references to them. `FormulaChecks` checks display formulas (`m:oMath` / `Equation` OLE objects) and their numbers. `AppendixChecks` checks appendix letters, titles and references to them.
     - `FrameChecks` reads the ЄСКД frame (form 2) from the shape group or header that contains its labels and compares it with `TitlePageInfo`.
     - The reference-list entry format (ДСТУ 8302) is checked in `CitationChecks::entryErrors()`.
   - In Ukrainian double-quoted PHP strings, write `{$var}`. PHP accepts UTF-8 bytes in identifiers, so `"$var»"` would read a variable named `var»`.
   - Body text checks apply only between `ВСТУП` and the reference list. Tables, captions, TOC entries and monospace-font code listings are skipped. Headings are recognised case-insensitively, and `caps`/`smallCaps` effects are resolved through styles. Repeated body violations are grouped as 5 examples plus a summary row.
   - Text extraction must use `<w:t(?:\s[^>]*)?>`. A plain `<w:t[^>]*>` also matches `<w:tab>`, `<w:tabs>` and `<w:tbl>`.
   - Uploaded files are never stored. Only a row in `norm_control_checks` is kept (file name, SHA-256, error count). The one exception is **Premium auto-fix**: for users with `User::isPremium()` (role `Преміум` or `super_admin`), `analyze()` builds a fix plan (`$fixPlan`: paragraph chunk index → violated rules) and `DocxFixer::fix()` applies it with DOM. Paragraph indices match the analyzer's chunks, and the fixer skips a paragraph if its text differs. The fixer also corrects page size and margins in every `sectPr`. The fixed copy is kept in `storage/app/private/fixed/{token}.docx` (token in session `norm_control_fixed`) and served by `downloadFixed()`. It is deleted on the user's next check or after 24 h. When adding a fixable rule, add its op to `DocxFixer`, its label to `LABELS` and its report type to `FIXABLE_TYPES`, and insert new OOXML elements in schema order (`PPR_ORDER`/`RPR_ORDER`). `process()` stores the computed `$report` array in the session (`norm_control_report`). `downloadReport()` builds a Word report from it with `ReportDocx::build()`, which writes raw OOXML through `ZipArchive`; there is no PhpWord, because it needs the `gd` extension that XAMPP lacks. New report data goes into `$report`, not into form fields.
   - To preview a generated report, convert it to PDF through Word COM in PowerShell (`Word.Application` → `SaveAs(..., 17)`). Word merges identical borders on adjacent paragraphs, so lines are drawn as table-cell borders. Field runs (`PAGE`/`NUMPAGES`) need their own `rPr`.
   - Tests in `tests/Feature/NormControlTest.php` build real `.docx` files in memory (`document()` helper with styles, sections, headers, theme). Use that helper for new rules.

2. **Admin panel** (`/admin`, `app/Providers/Filament/AdminPanelProvider.php`)
   - Resources are auto-discovered from `app/Filament/Resources/<Plural>/`. Each resource follows the Filament v4/v5 split layout: `XResource.php` plus `Pages/`, `Schemas/` (Form, Infolist), `Tables/` and `RelationManagers/`.
   - Authorization uses **Filament Shield** on top of **spatie/laravel-permission** (`User` uses `HasRoles`). Permissions follow the `Action:Model` naming (e.g. `ViewAny:Role`), as in `app/Policies/RolePolicy.php`.
   - `app/Filament/Imports/UserImporter.php` bulk-imports users from CSV. It matches `department` and `group` by name, assigns comma-separated `roles` in `afterSave`, and gives new users the default password `acadoc123!`. Imports run on the **database queue**, so a queue worker must be running (`composer dev` starts one).
   - The language switcher (`bezhansalleh/filament-language-switch`) offers `uk` and `en` (`AppServiceProvider`). Labels for models and fields come from `lang/uk/models.php` through `__('models.*')`.

Roles and access:
- Role names are constants on `User`: `ROLE_STUDENT = 'Student'`, `ROLE_TEACHER = 'Викладач'`, `ROLE_ADMIN = 'super_admin'`. Premium is not a role (see below). In the users table, the «Статус» action group lets an admin assign or remove the teacher role, add a month of Premium, grant lifetime Premium, or revoke Premium.
- Self-registration (`FrontendAuthController::register`, a modal in the layout, validation errors in the `register` bag) always assigns `Student`. The role the user picked is stored in `users.requested_role` (`student`/`teacher`), and an admin approves teachers in Filament with the "Призначити викладачем" table action. Only `super_admin` sees the roles field.
- `User::canAccessPanel()` lets only `super_admin` into `/admin`. Students and teachers use the public site only.
- Students without the teacher, Premium or admin role may run `User::STUDENT_DAILY_CHECKS` (1) norm-control checks per day, counted in `norm_control_checks` from 00:00 Europe/Kyiv (the app itself runs in UTC). `process()` reserves the check under `lockForUpdate` before analysing, and deletes the reservation if the file turns out to be broken.

Premium by donation:
- Premium is stored in `users.premium_until` (one month per donation, extended from the end of a still-active period by `User::extendPremium()`) and `users.premium_lifetime` (`grantLifetimePremium()`). `User::isPremium()` is true when either applies, and always for `super_admin`. Premium removes the daily limit, enables auto-fix and raises the upload limit to `NormControlController::PREMIUM_MAX_UPLOAD_MB` (20 MB, see `maxUploadMbFor()`).
- There are two plans, `Payment::MONTH` (`PREMIUM_PRICE_UAH`, 10) and `Payment::LIFETIME` (`PREMIUM_LIFETIME_PRICE_UAH`, 100). For monobank the plan is decided by the transfer amount (`Payment::planForUah()`). For PayPal the user picks it (`PAYPAL_AMOUNT` / `PAYPAL_LIFETIME_AMOUNT`). `Payment::markPaid()` applies the plan; it is idempotent and runs under a row lock.
- The `/premium` page (`PremiumController`) offers two providers, each shown only if configured in `.env` (`config/services.php`: `premium`, `monobank`, `paypal`). Both are off unless `PREMIUM_PAYMENTS_ENABLED=true` (`Payment::paymentsEnabled()`, default false). While it is off, every page hides prices and payment blocks behind `@if (Payment::paymentsEnabled())` and says Premium is granted personally by the administrator (Telegram `Payment::TELEGRAM_URL`; plain-text sentence in `Payment::howToGet()`). Keep both branches when you edit Premium texts.
  - `MonobankJar`: a monobank jar donation. The user gets a 6-digit code to put in the transfer comment. A personal-API webhook (`/payments/monobank/{MONOBANK_WEBHOOK_SECRET}`, CSRF-exempt in `bootstrap/app.php`) or the "перевірити" button (jar statement, at most once per 60 s) matches the code and grants Premium. `php artisan monobank:setup [--webhook]` lists jars and registers the webhook.
  - `PayPal`: Orders API v2 (create order → approve → capture on `/premium/paypal/return`).
- Admins see donations in Filament (`PaymentResource`) and can confirm a pending one manually.

Domain models: `Department` 1→N `Group`, and `User` belongs to both a `Department` and a `Group` (`department_id`, `group_id`).

## Workflow (from INSTRUCTIONS.md)

Never commit directly to `master`. Create a `feature/<task>` branch, push it, and open a PR for the teacher to review.
