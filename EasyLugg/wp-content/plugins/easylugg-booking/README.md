# EasyLugg Delivery Booking Muse

This guide describes the plugin as it currently works. It is intended to give a
future maintainer enough context to trace a booking from the page form through
validation, storage, email, and tracking.

## What this plugin does

The plugin adds a three-step luggage-delivery booking experience for Da Nang
airport and hotel transfers. It provides:

- A customer booking form and booking-status page.
- Per-bag VND pricing configurable in WordPress admin.
- Booking records in a custom database table.
- Admin booking review, status updates, and private photo downloads.
- A confirmation email and token-protected tracking link.

This is a demo booking flow, not a live payment or dispatch integration. No
payment is charged or processed, and promotion codes are recorded but do not
apply discounts.

## Files and responsibilities

| File or directory | Responsibility |
| --- | --- |
| `easylugg-booking.php` | Plugin bootstrap, hooks, database schema, settings, page/form rendering, admin screens, AJAX submission, validation, uploads, email, and tracking. Most server-side behavior lives in the `EasyLugg_Booking_Plugin` class. |
| `texts.php` | User-facing strings, grouped by page and section. PHP reads these through `text()`; the same array is localized to JavaScript. |
| `assets/booking.js` | Three-step interaction, client-side checks, hotel suggestions, price/summary rendering, AJAX submission, and confirmation UI. |
| `assets/booking.css` | Booking form, summary, progress, and result presentation. Typography inherits from the active site theme; the plugin does not load an external font. |
| `assets/icons/*.svg` | SVG icons embedded by PHP or passed to the browser. |
| `assets/fonts/` | Bundled font asset directory. The booking stylesheet currently inherits the site font instead of applying a bundled font. |

There are no separate PHP templates or plugin-specific automated test files
currently; the form and admin markup are rendered in the main PHP file.

## Entry points and page setup

The plugin supports both `[bookingPlugin]` and `[easylugg_booking]` shortcodes.
It also appends the booking flow to the page whose slug is `bookings`. For an
Elementor-built bookings page, it uses Elementor's content filter to append the
same markup. A guard prevents the form from being added twice to one page.

The booking assets are enqueued on the `bookings` page or a singular page
containing either shortcode. JavaScript settings (AJAX URL, nonce, prices,
hotel suggestions, text, and icons) are passed through the
`EasyLuggBooking` localized object.

On the `bookings` page, the plugin sends no-cache headers and asks LiteSpeed not
to cache the page. This matters because the page contains a submission nonce
and may display a private tracking link.

## Customer booking flow

1. **Service:** choose a route, enter its itinerary and hotel details, select
   bag counts, and optionally enter a promotion code, note, or photo.
2. **Contact:** enter contact information and accept both required terms.
3. **Review:** check the summary and estimated price, then submit. A payment
   method is required unless the booking is marked as an on-request enquiry.

The three route codes are:

| Code | Route | Route-specific data |
| --- | --- | --- |
| `AH` | Airport to hotel | Service date, DAD Terminal 2 pickup slot (08:00–11:00 in 30-minute increments), flight number, destination hotel. A pickup slot earlier than the current time is rejected for same-day service. |
| `HA` | Hotel to airport | Service date, origin hotel, flight number, airport delivery time. |
| `HH` | Hotel to hotel | Service date, origin and destination hotels, optional hotel booking references, and `same_day` or `next_day` delivery. |

The hotel suggestion list is a hard-coded mock directory in `mock_hotels()`,
not a live Google Places integration. Selecting a suggestion fills its address
and coordinates. Server validation still checks hotel name/address and, when
coordinates are supplied, requires a valid latitude/longitude pair.

Hotel reception fields are accepted by server validation. However, the current
form renderer does not display reception selectors. If a submitted
`*_reception` value is `no`, the booking is treated as on-request; this choice
is therefore not currently available through the rendered form.

Bag sizes are `cabin`, `medium`, and `oversize`. Each count must be an integer
from 0 to 20, and at least one bag is required. The server calculates the
authoritative total; the browser also calculates a live estimate for display.

## Pricing

Pricing is stored in the `easylugg_booking_prices` WordPress option. Defaults
are:

| Bag size | Default price per bag |
| --- | ---: |
| Cabin | 150,000 VND |
| Medium | 180,000 VND |
| Oversize | 250,000 VND |

Administrators with `manage_options` can edit prices under **EasyLugg Bookings
→ Luggage Pricing**. Each value is sanitized to a whole-number amount from 0
through 100,000,000 VND. The total is the sum of each bag count multiplied by
its configured unit price.

## Submission, validation, and persistence

The browser posts a `FormData` request to `admin-ajax.php` with action
`easylugg_booking_submit`. Both logged-in and anonymous visitors use the same
handler.

The server-side submission path in `submit_booking()` is:

1. Verify the WordPress AJAX nonce, rate limit, honeypot, and version-4 UUID
   request key.
2. Reuse an existing booking for a repeated request key, if one exists.
3. Validate route/date/schedule/hotel data, bag counts, customer details,
   terms, and payment method.
4. Calculate the total from saved server-side prices.
5. Validate and privately store an optional/required luggage photo.
6. Store the booking and its JSON detail record.
7. Send a plain-text confirmation email and return a tracking URL and booking
   reference.

The rate limit allows up to eight attempts per IP-derived transient in a
10-minute window. Input helpers sanitize scalar text and keys; structured
fields are validated against the accepted route, channel, payment, and
schedule values.

Luggage photos are optional for `AH` and required for `HA`/`HH`. Only JPEG or
PNG images up to 3 MB are accepted. Files are stored under
`wp-content/uploads/easylugg-booking-private/`; the plugin adds access-denial
and index files, checks the resolved path, and provides downloads only to
administrators.

Repeated submissions use the same request key from `sessionStorage`. The
database has a unique key on that value, so retries return the existing
booking rather than creating another. The emailed tracking URL contains a
bearer token; only its hash is stored with the booking.

## Database and booking data

The table name is `$wpdb->prefix . 'easylugg_bookings'`. Its row stores the
booking code, unique request key, tracking-token hash, creation/service dates,
route, status, demo payment method/status, total, customer name/email, JSON
booking details, and private photo path.

The JSON detail field contains the route-specific itinerary, hotel records,
bag counts, promotion code, note, customer contact details, payment selection,
estimate, terms timestamp, and any hotel booking references.

Activation creates/updates the table with `dbDelta()`, creates default prices
if missing, and records the database schema version. The `init` hook compares
the stored schema version and runs the same table upgrade when versions differ.
When changing the table schema, update `DB_VERSION` and keep the `dbDelta()`
definition aligned.

There is no uninstall handler in this plugin. Deactivation or uninstall should
not be assumed to delete booking records or settings.

## Admin and status tracking

The **EasyLugg Bookings** admin page lists the most recent 100 bookings.
Opening a booking shows its stored details, allows an administrator to update
its status, and provides a protected photo-download link. Status changes use
an admin nonce and the `manage_options` capability.

Statuses used by the plugin:

- `requested` — manual confirmation is required.
- `confirmed` — initial booking state for a standard demo booking.
- `collected`, `in_transit`, `delivered` — operational tracking milestones.
- `cancelled` — cancelled booking.

Customers open the tracking link from their confirmation email. The link's
booking code and token are checked against the stored token hash. Tracking
shows the status timeline, or a specific message for requested/cancelled
bookings. Customers cannot change or cancel bookings online.

## Current limitations and integration boundaries

- Payment choices are demonstrations only; `payment_status` is stored as
  `demo`. Do not describe this as a payment gateway.
- Promotion codes are saved but no discount validation/calculation exists.
- Hotel suggestions are static mock data.
- Email is sent with `wp_mail()` after saving. If email fails, the booking
  remains saved and the AJAX response includes a notice.
- No live dispatch, map lookup, booking edit, or customer cancellation
  integration is implemented.
- `SHOW_TRUST_BADGES` is disabled; the related live GPS/tamper-proof claims
  are intentionally hidden.

## Maintenance and verification

When changing the booking flow:

- Keep client-side convenience checks and authoritative PHP validation in
  sync. Never rely on JavaScript validation for submitted data.
- Add or update visible copy in `texts.php` so PHP and JavaScript use the same
  translation keys.
- If changing submitted fields, update the form markup, `booking.js`, PHP
  validation/data construction, and the admin detail view as needed.
- If changing storage schema, increment `DB_VERSION`.
- Keep new PHP compatible with PHP 7.4, the plugin's declared minimum.

There is no plugin-local PHPUnit/PHPCS configuration. Basic syntax checks:

```sh
php -l easylugg-booking.php
php -l texts.php
node --check assets/booking.js
```

For behavior changes, also test each route, a repeated submission, an
on-request booking if the reception flow is enabled, photo upload/download,
email failure handling, and tracking links on the local site.
