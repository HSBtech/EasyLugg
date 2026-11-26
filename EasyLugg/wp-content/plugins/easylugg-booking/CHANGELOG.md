# Changelog

All notable changes to the **EasyLugg Delivery Booking** plugin (`easylugg-booking`) will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### 2026-11-26 — Dashboard 2 (new staff dashboard, main dashboard)

- Added `includes/dashboard2/` and `assets/dashboard2/`: KPI row, booking list with tabs, date range, route filter and search, booking detail, timeline, exception alert and proof strip. Read-only; the only write is the `pii_view` audit row on opening a booking (same as Dashboard 1).
- Bookings (menu) opens the Create a booking form and Settings shows the configuration only, both restyled from the Stitch design. They reuse the Dashboard 1 screens and save handlers unchanged (hosted inside the Dashboard 2 shell).
- Dashboard 2 replaces the old dashboard under the same shortcode `[easylugg_dashboard]` (the page content does not change). Setting `easylugg_d2_enabled` to 0 serves the old dashboard again.
- One touchpoint in `easylugg-booking.php`: a single `require_once` for the Dashboard 2 bootstrap. Dashboard 1 files are not modified.
- Deploy note: nothing to change on live pages; the `dashboard` page keeps `[easylugg_dashboard]`.

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