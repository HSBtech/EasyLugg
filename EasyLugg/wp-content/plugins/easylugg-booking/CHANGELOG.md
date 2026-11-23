# Changelog

All notable changes to the EasyLugg Delivery Booking plugin.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed
- Staff Dashboard (`[easylugg_dashboard]`) inherits the theme/Elementor font
  instead of forcing Plus Jakarta Sans.

### Removed
- Google Fonts stylesheet for Plus Jakarta Sans. It was only used by the
  Dashboard, which no longer loads it.
- `README.md`, the two DRAFT spec documents and `tests/` are no longer in the
  plugin folder. They are development and internal material, not runtime code.

## [1.0.1] - 2026-11-20

### Added
- Staff Dashboard, shortcode `[easylugg_dashboard]`, with WordPress roles and
  capabilities (`easylugg_staff`, `easylugg_admin`): booking list, detail,
  internal create/edit, and settings.
- Booking state machine (confirm, check-in, depart, deliver, complete, cancel,
  no-show) with atomic transitions, `row_version` concurrency control and an
  append-only audit log.
- Database schema v2: `source`, `created_by`, `invoice_no`, `needs_review`,
  `row_version`, `updated_at`, plus audit and photos tables.
- Rules unit tests (53 checks).

### Changed
- Old admin pricing and bookings pages are read-only.

## Initial release - 2026-11-19

The plugin header and `VERSION` constant already read 1.0.1 in this commit, so
this entry has no separate version number.

### Added
- EasyLugg Delivery Booking plugin: booking form for delivery and storage,
  `[easylugg_booking]` / `[bookingPlugin]` shortcodes and the `[easylugg_tracking]`
  lookup page.
