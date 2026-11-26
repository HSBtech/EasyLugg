# Changelog

All notable changes to the **EasyLugg Delivery Booking** plugin (`easylugg-booking`) will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### 2026-11-26 — New dashboard interface
The staff dashboard has a new interface, designed in Stitch with the EasyLugg palette (Teal `#0294BB`, Dark Blue `#025282`, Light Blue `#A9DFEA`, Soft Blue `#EAF4F7`, Border `#D9E3E8`). It is served by the **same shortcode `[easylugg_dashboard]`**, so no page content changes on live. There is only one dashboard.

#### Added
- **Shell:** navy sidebar (Dashboard, Bookings, Settings; Settings is Admin-only), white header with title, search and account chip. It sits inside the theme page (site header/footer stay) at full viewport width, and never uses `position: fixed`, so the WordPress admin bar does not overlap it.
- **Overview (Dashboard):**
  - KPI row: Today Bookings (with % change versus the previous period of the same length), In Transit, Delivered, Exceptions, Pending Pickup (each with % of the total), and a "Last update / LIVE" tile. Auto-refreshes every 60 seconds while the tab is visible; shows "Connection lost" after two failed refreshes.
  - Booking list: tabs with counts (All, Pickup, In Transit, Delivered, Exception; the counts match the KPI tiles), **date range picker (From – To)**, route filter (AH, HA, HH, ST), search by booking code, guest name or email (a search overrides date and tab), 10 rows per page, row highlight for the selected booking and a red dot for bookings with an exception.
  - Booking detail (loaded without reloading the page, URL gets `?code=`): booking information, customer (the passport number is never shown), luggage (total and sizes, tag code), pickup and delivery, "handled by", and quick links (call, email, edit, full detail).
  - Live Tracking timeline built from the audit log and photos; Exception Alert (a booking is an exception when `needs_review = 1`, or an `incident` audit exists, or it is `no_show` with luggage still held); Proof & Support strip (pickup, delivery and customer photo presence) and tracked information.
- **Bookings menu** opens the *Create a booking* form only, restyled from the Stitch design (segmented Route and Source controls, section headings, +/- bag steppers, sticky Cancel/Create bar). **Settings** shows the configuration only (Pricing, Hours & slots, Operations & capacity, Hub & meeting point, Change history) as one card per section with a section index on the right. Both reuse the previous dashboard screens and their save handlers unchanged (field names, validation and saving are not modified).
- New files: `includes/dashboard-app/` (`bootstrap.php`, `class-app.php`, `class-app-metrics.php`, `class-app-data.php`, `class-app-render.php`, `class-app-ajax.php`, `texts.php`) and `assets/dashboard-app/` (`dashboard-app.css`, `dashboard-app.js`, `dashboard-app-embed.js`).
- AJAX actions `easylugg_app_refresh` and `easylugg_app_detail` (logged-in users only, nonce `easylugg_app`, permission re-checked on the server, no `nopriv` action).
- Emergency switch: option `easylugg_app_enabled` = `0` serves the previous dashboard code again (default `1`).
- All dashboard text goes through `__()` with the `easylugg-booking` domain, cached per language, in `includes/dashboard-app/texts.php`.

#### Changed
- Shortcode `[easylugg_dashboard]` is now rendered by the new interface. The previous dashboard code (`includes/dashboard*.php`, `assets/booking-dashboard.*`) is unchanged and still renders the Bookings detail, edit, create and Settings screens inside the new shell.
- `easylugg-booking.php`: one added `require_once` for the dashboard bootstrap.

#### Security and data
- Read-only: every query is a `SELECT` through `$wpdb->prepare` (search uses `esc_like`). The only write is one `pii_view` audit row each time a booking is opened, as in the previous dashboard.
- Output is escaped (`esc_html`, `esc_attr`, `esc_url`); HTML is built on the server, the script only swaps regions.
- The page is served with no-store and noindex headers.
- Times are stored in UTC and shown in the site time zone; unknown statuses (for example a future status) do not break the lists.
- No database change. No new options except `easylugg_app_enabled` (optional).

#### Known limits
- Proof & Support shows whether photos exist, without thumbnails or a lightbox (photos open in the full detail screen).
- The list is sorted by service date then creation time, not by service time.
- Korean and Vietnamese strings of the new interface are not translated yet.
- Not covered by automated tests; verified by HTTP checks on the local site and DOM checks of the form script. Needs a visual and a save/create check on the live site after deployment.

#### Upgrade notes
- Install the ZIP over the plugin. No page content or database change is needed.
- After deployment sign in as Admin and check: the overview, a booking detail, *Create a booking* and *Settings* (save once).

### 2026-11-25 — WPML readiness (language handling)
### Added
- `wpml-config.xml` (translatable hub texts) and `languages/easylugg-booking.pot`; `Domain Path` in the plugin header.
- Bookings table column `lang` (`DB_VERSION` 3). The `dbDelta` upgrade runs on the first request after the plugin is updated. It stores the WPML language of each new booking.
- `EasyLugg_Booking_Plugin::current_lang()`, `tracking_page_url()`, `with_language()` helpers.

### Changed
- AJAX URL carries `?lang=`, so validation errors and lookup results follow the page language.
- Tracking links (confirmation email, Dashboard) point to the translated "bookings" page; translated pages also load the booking assets.
- Dashboard "resend confirmation" email is sent in the booking's language.
- Hub name, address and hours (Dashboard → Settings) are registered with WPML String Translation.
- Hardcoded ` VND` and the Dashboard `aria-label` are now translatable; `texts.php` placeholders `bag_price` (new) and `bags_days` use numbered placeholders.
- `texts()` is cached per language, not once per request.

### 2026-11-24 — Dashboard font and dev-file cleanup (commit e8e891a)
### Changed
- **Staff Dashboard (`[easylugg_dashboard]`):** Now inherits the active WordPress theme / Elementor font instead of forcing Plus Jakarta Sans.

### Removed
- Removed Google Fonts stylesheet enqueue for Plus Jakarta Sans (no longer used by the Dashboard).
- Removed non-runtime development files from the plugin folder: `README.md`, the two draft SRS/system spec documents, and `tests/`. The plugin folder now holds runtime code only.

---

## [1.0.1] - 2026-11-20

### Added
- **Staff Dashboard (`[easylugg_dashboard]`):** Internal operations portal featuring booking list (with route/status filters and day mode), detailed booking view with status timeline and payment block, and internal booking creation (reusing customer form validation and pricing logic).
- **Booking State Machine & Gates:**
  - Atomic transitions (`confirm` → `check-in` → `depart` → `deliver` → `complete`), plus terminal states (`cancel` and `no_show`).
  - **Check-in gate:** Requires 2 luggage photos before proceeding.
  - **Completion gate:** Requires an invoice number (`invoice_no`) before marking Cash bookings as complete.
  - **Time validation:** New bookings/service times must be scheduled at least 30 minutes in the future.
  - Concurrency control via `row_version` and an append-only audit log recording all actions.
- **Roles, Permissions & Settings:**
  - Added WordPress roles and capabilities (`easylugg_staff`, `easylugg_admin`) with per-account permissions in wp-admin.
  - Added Admin-only **Settings** tab on the Dashboard (prices, time slots, capacity, hubs) backed by a configuration change log.
- **Database Schema v2:** Added 6 columns (`source`, `created_by`, `invoice_no`, `needs_review`, `row_version`, `updated_at`) along with dedicated `audit` and `photos` tables.

- **Testing:** Added 53 unit tests covering booking and dashboard rules. The test file has since moved out of the plugin folder (see Unreleased).

### Changed
- Legacy wp-admin pricing and bookings screens are now set to **read-only** mode as a fallback.

---

## [1.0.0] - 2026-11-19

*Initial release. The plugin header and `VERSION` constant already read `1.0.1` in this commit, so there is no separate version number.*

### Added
- **Customer Booking Form (`[easylugg_booking]` / `[bookingPlugin]`):**
  - Supports **Delivery** (Airport ↔ Hotel, Hotel ↔ Airport) across 10 fixed airport slots (`08:00–11:00` and `19:00`, `19:30`, `20:00`), automatic Delivery/Departure date sync, and overnight fee calculation.
  - Supports **Storage** at the Hoi An hub (97 Nguyen Tat Thanh) with 30-minute slots (`08:00–20:00`), priced at `bags × days × 100,000 VND/bag/day` (1-day minimum).
- **Order Tracking Page (`[easylugg_tracking]`):** Public lookup page displaying booking details and status timeline, protected by IP rate-limiting (8 lookups per IP per 10 minutes).
- **Dynamic Pricing & Localization:**
  - Live pricing stored in the `easylugg_booking_prices` option (Delivery: Cabin 150,000 / Medium 180,000 / Oversize 250,000 VND; Storage: 100,000 VND/bag/day).
  - Centralized UI copy in `texts.php` with WPML support (`load_textdomain` on `init`) and Executive Transit styling.

### Changed
- Booking forms now render strictly where the shortcode is placed (removed automatic content appending).