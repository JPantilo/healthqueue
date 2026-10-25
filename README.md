# HealthQueue Iligan

Appointment and queue management system for health centers in Iligan City, Lanao del Norte. Patients book a slot and receive a queue number before leaving home. Health workers run the day's queue from a live board. Admins see clinic analytics and manage staff assignments.

Built with plain PHP 8 and MySQL — no Composer, no framework.

## Features

| Feature | Details |
|---|---|
| **Google OAuth sign-in** | One-click Google login; Google accounts do not require a local password |
| **Email/password sign-in** | Patients can create an account, verify their email, and sign in securely with a hashed password |
| **Patient contact details** | Registration stores an 11-digit Philippine mobile number and selected network (Smart, TNT, Globe, TM, DITO, GOMO or Other) |
| **Appointment booking** | Patients choose center, service, date and time; each booking receives a clinic/day queue number |
| **Booking safeguards** | Only patients can book; one active appointment per patient; same department appointments on one day must be at least one hour apart |
| **5-day weather forecast** | Shows forecast for the chosen center and date (OpenWeatherMap) |
| **Google Calendar sync** | Adds, updates and removes the visit on the patient's calendar |
| **Calendar connection** | Patients can connect Google Calendar from My Appointments; future bookings then sync automatically |
| **Confirmation emails** | Confirmation, reschedule, cancellation and "you're next" notifications through the Resend API key over HTTPS |
| **Live queue board** | Workers accept, call, mark done/no-show, or reschedule; completed patients automatically promote the next waiting patient |
| **Patient call details** | Clicking Call patient opens a worker-only modal with the patient's name, email, mobile number and network |
| **Public queue display** | Logged-out visitors can switch between clinics and see the current active queue number |
| **Admin analytics dashboard** | 7-day booking trend chart (Chart.js), status breakdown, per-center load, top services |
| **Staff assignments** | Admin assigns each worker a clinic and the departments they handle |
| **Interactive center map** | Leaflet + OpenStreetMap on the booking form |

## External APIs

| API | Used for | Files |
|---|---|---|
| Google OAuth 2.0 | Sign-in | `oauth_callback.php`, `includes/google.php` |
| Google Calendar | Adds, updates and removes the visit on the patient's calendar | `includes/google.php`, `appointments/*` |
| Resend Email API | Confirmation, reschedule, cancellation and "you're next" emails; this uses an API key over HTTPS, not PHP SMTP | `includes/mailer.php` |
| Leaflet + OpenStreetMap | Center map on the booking form | `appointments/_form.php` |
| OpenWeatherMap | 5-day forecast for the chosen center and date | `includes/weather.php`, `api/weather.php` |
| Chart.js (CDN) | 7-day booking trend bar chart on the admin dashboard | `admin/dashboard.php` |

## Roles

- **patient** (default): register with contact details; book, edit and cancel their own appointments.
- **worker**: see assigned clinic/department appointments and use the queue board (accept, call next, done, no-show, reschedule).
- **admin**: everything above, plus clinic analytics, user role management, and staff department assignments. Emails listed in `admin_emails` in `config.php` become admin on first login.

## Queue rules

- Queue numbers reset per clinic and appointment date.
- Active statuses are `pending`, `waiting` and `in_consultation`; cancelled, no-show and done records are not shown in the active queue.
- A worker can accept future bookings; they appear under upcoming accepted queues until their appointment date.
- When a worker marks a patient done, the earliest waiting patient for today is automatically moved into consultation, even if the next appointment time has not arrived.
- Same-department bookings at the same clinic on the same date must be at least 60 minutes apart.

## Appointments (CRUD)

| Action | File |
|---|---|
| Create | `appointments/create.php` |
| List | `appointments/index.php` |
| Edit | `appointments/edit.php` |
| Cancel | `appointments/delete.php` |

## File Structure

```
healthqueue/
├── admin/
│   ├── dashboard.php      # Clinic analytics (Chart.js trend, stats, center load)
│   └── users.php          # Role management
├── api/
│   └── weather.php        # Proxies OpenWeatherMap; caches 30 min
├── appointments/
│   ├── _form.php          # Shared booking form (Leaflet map, weather widget)
│   ├── create.php
│   ├── delete.php
│   ├── edit.php
│   └── index.php
├── assets/
│   └── style.css          # Main stylesheet (Inter + Fraunces fonts, design tokens)
├── includes/
│   ├── auth.php           # require_role(), current_user()
│   ├── db.php             # PDO singleton
│   ├── google.php         # OAuth + Calendar helpers
│   ├── init.php           # Bootstraps config, session, helpers
│   ├── layout.php         # page_header() / page_footer(), navigation
│   ├── mailer.php         # Resend email helpers
│   └── weather.php        # OpenWeatherMap fetch + cache
├── .htaccess              # Blocks sensitive files from web
├── config.sample.php      # Copy to config.php and fill in keys
├── index.php              # Landing page
├── logout.php
├── oauth_callback.php
├── queue.php              # Live queue board for workers/admins
├── schema.sql             # Full DB schema with seed data
└── staff.php              # Admin: assign workers to clinics and departments
```

## Setup

1. Create a MySQL database and import `schema.sql` in phpMyAdmin. If upgrading an existing database, run:
   ```sql
   ALTER TABLE users
     ADD email_verified_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
     ADD email_verification_token_hash CHAR(64) DEFAULT NULL,
     ADD email_verification_expires_at DATETIME DEFAULT NULL;
   ```
   Existing accounts remain verified; new password accounts must verify their email before signing in or opening appointments.
2. Copy `config.sample.php` to `config.php` and fill in the database credentials, API keys, `base_url` and `admin_emails`. **Never commit `config.php`.**
3. **Google Cloud Console**: enable the Calendar API, set up the OAuth consent screen in Testing mode, add your demo Gmail accounts as test users, and create a Web OAuth client. Add `<base_url>/oauth_callback.php` as the redirect URI.
4. **Resend Email API**: create a Resend API key and place it in `resend.api_key` in `config.php`. Set `resend.from` to a sender allowed by Resend. Without a verified domain, use `HealthQueue <onboarding@resend.dev>` and send test mail only to the email address authorized by Resend. The application sends verification and appointment mail through `https://api.resend.com/emails`; no SMTP server or PHP `mail()` configuration is required.
5. **OpenWeatherMap**: put the key in `config.php`. New keys can take up to two hours to activate. Forecasts are cached for 30 minutes in the `weather_cache` table.
6. Place files in your web root (e.g. `c:\xampp\htdocs\healthqueue`) and open `http://localhost/healthqueue`.

## Microsoft Azure hosting

The application can be hosted on **Azure App Service for PHP** with **Azure Database for MySQL - Flexible Server**:

1. Create an Azure App Service running a supported PHP 8.x runtime and deploy the project files.
2. Create an Azure Database for MySQL Flexible Server, create the application database, and import `schema.sql`.
3. Configure the production database credentials, Google credentials, Resend API key, OpenWeatherMap key, `base_url`, and `admin_emails` in the deployed `config.php` or through Azure App Service application settings. Do not commit API keys.
4. Set `base_url` to the public HTTPS App Service URL, for example `https://your-app.azurewebsites.net`.
5. Add the production OAuth callback URL (`<base_url>/oauth_callback.php`) to Google Cloud Console.
6. Use a verified Resend sending domain for production email. Keep the Resend API key server-side and never expose it in browser code.
7. Enable HTTPS and confirm the Azure MySQL server accepts connections from the App Service networking configuration.

For production, use HTTPS, restrict the database firewall to the application, enable backups, and keep `config.php` out of source control. The Resend integration is API-based, so Azure does not need an SMTP relay for HealthQueue email.

## Health Centers

The seeded centers are in Pala-o, Tubod, Tibanga and Santiago. Their pin locations are approximate — confirm each on OpenStreetMap and correct the coordinates, address and hours in the `centers` table before the demo.

