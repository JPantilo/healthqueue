# HealthQueue Iligan

Appointment and queue management system for health centers in Iligan City, Lanao del Norte. Patients book a slot and receive a queue number before leaving home. Health workers run the day's queue from a live board. Admins see clinic analytics and manage staff assignments.

Built with plain PHP 8 and MySQL — no Composer, no framework.

## Features

| Feature | Details |
|---|---|
| **Google OAuth sign-in** | One-click login; no passwords stored |
| **Appointment booking** | Choose center, service, date and time; get a queue number |
| **5-day weather forecast** | Shows forecast for the chosen center and date (OpenWeatherMap) |
| **Google Calendar sync** | Adds, updates and removes the visit on the patient's calendar |
| **Confirmation emails** | Confirmation, reschedule, cancellation and "you're next" notifications (Resend) |
| **Live queue board** | Workers call the next patient, mark done/no-show, or reschedule |
| **Admin analytics dashboard** | 7-day booking trend chart (Chart.js), status breakdown, per-center load, top services |
| **Staff assignments** | Admin assigns each worker a clinic and the departments they handle |
| **Interactive center map** | Leaflet + OpenStreetMap on the booking form |
| **Dev login bar** | Quick-switch between patient/worker/admin on localhost (hidden in production) |

## External APIs

| API | Used for | Files |
|---|---|---|
| Google OAuth 2.0 | Sign-in | `oauth_callback.php`, `includes/google.php` |
| Google Calendar | Adds, updates and removes the visit on the patient's calendar | `includes/google.php`, `appointments/*` |
| Resend | Confirmation, reschedule, cancellation and "you're next" emails | `includes/mailer.php` |
| Leaflet + OpenStreetMap | Center map on the booking form | `appointments/_form.php` |
| OpenWeatherMap | 5-day forecast for the chosen center and date | `includes/weather.php`, `api/weather.php` |
| Chart.js (CDN) | 7-day booking trend bar chart on the admin dashboard | `admin/dashboard.php` |

## Roles

- **patient** (default): book, edit and cancel their own appointments.
- **worker**: see all appointments and use the queue board (call next, done, no-show, reschedule).
- **admin**: everything above, plus clinic analytics, user role management, and staff department assignments. Emails listed in `admin_emails` in `config.php` become admin on first login.

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
│   ├── layout.php         # page_header() / page_footer(), nav, dev bar
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

1. Create a MySQL database and import `schema.sql` in phpMyAdmin.
2. Copy `config.sample.php` to `config.php` and fill in the database credentials, API keys, `base_url` and `admin_emails`. **Never commit `config.php`.**
3. **Google Cloud Console**: enable the Calendar API, set up the OAuth consent screen in Testing mode, add your demo Gmail accounts as test users, and create a Web OAuth client. Add `<base_url>/oauth_callback.php` as the redirect URI.
4. **Resend**: without a verified domain, mail can only be sent from `onboarding@resend.dev` to the email of your Resend account. Use that address for the demo patient.
5. **OpenWeatherMap**: put the key in `config.php`. New keys can take up to two hours to activate. Forecasts are cached for 30 minutes in the `weather_cache` table.
6. Place files in your web root (e.g. `c:\xampp\htdocs\healthqueue`) and open `http://localhost/healthqueue`.

## Health Centers

The seeded centers are in Pala-o, Tubod, Tibanga and Santiago. Their pin locations are approximate — confirm each on OpenStreetMap and correct the coordinates, address and hours in the `centers` table before the demo.

