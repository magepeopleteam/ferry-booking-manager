# Changelog

All notable changes to MagePeople Ferry Booking System are documented here.
This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **Foundation.** Plugin bootstrap with a PHP/WordPress version gate that warns
  instead of fatalling, a bundled PSR-4 autoloader so Composer is never a runtime
  dependency, a small explicit service container, service providers, a redacting
  file logger with rotation and retention, and a group-versioned cache manager.
- **Permissions.** Eleven `mpfbs_` capabilities and three operational roles — Ferry
  Manager, Booking Manager, Cashier — provisioned on
  activation and refreshed automatically when the capability map changes.
- **Dashboard.** A single `Ferry Manager` admin page hosting a Next.js
  application exported at build time, with client-side hash routing, a sticky
  sidebar, a command palette on Ctrl/Cmd+K, toasts, skeleton loading and a
  responsive layout down to 320px.
- **Storage.** Five custom post types — `mpfbs_port`, `mpfbs_vessel`, `mpfbs_route`,
  `mpfbs_sailing`, `mpfbs_booking` — with schema-driven meta registration. No custom
  database tables and no direct SQL.
- **Schema layer.** One declaration per entity drives sanitisation, validation,
  persistence, search indexing, the REST arguments and the JSON schema.
- **REST API.** `mpfbs/v1` with a consistent success/error envelope, per-field
  validation messages, server pagination and capability-gated endpoints for
  ports, vessels, routes, sailings, references and health.
- **Admin CRUD.** Working Ports, Vessels and Routes screens: searchable
  server-paginated tables, sortable columns, status filters, slide-over forms
  with inline validation, and confirmation before deletion.
- **Sailings and timetable.** Dated departures with capacity overrides, booking
  windows and operational status, filtered by route, vessel, status and date
  range.
- **Bulk schedule generator.** Expands a weekday-and-time pattern across a date
  range, always previewing first and classifying every candidate as new, already
  scheduled, or conflicting with another sailing on the same vessel.
- **Passenger types.** A configurable catalogue — Adult, Senior, Student, Child
  and Infant are installed on first run — where the age band that qualifies a
  traveller, the fare they pay (fixed, a percentage of the base type, or free)
  and the capacity they consume are three independent settings, so a lap infant
  can travel free without occupying a seat.
- **Vehicle types.** Ten shipped types from bicycle to articulated truck, each
  declaring both the vehicle slots and the lane metres it consumes, because a
  deck is limited by length rather than by a count of vehicles.
- **Configurable booking form.** Which details a booking records about each
  traveller and vehicle is a three-state choice per field — not collected,
  optional, required — with operator-defined custom fields, and one validator
  that every booking path shares.
- **Availability engine.** One authority — `MPFBS\Availability\AvailabilityService`
  — that every path consults: search, the booking wizard and the REST API.
  It resolves capacity (sailing override over vessel figure), subtracts sold and
  held inventory, drops lapsed holds the instant they lapse, and reports
  passengers, vehicle slots and lane metres in the same shape.
- **Capacity holds.** A hold is an ordinary booking in `on_hold` with an expiry,
  not a row in a side table, so it appears in the bookings list and survives a
  backup. Confirming a lapsed hold is re-checked against everyone who booked
  since, and a five-minute sweep tidies abandoned checkouts away.
- **Overbooking protection.** Reservations are written first and verified
  second, against a booking order every concurrent request agrees on, and a
  request that finds it took space it could not have rolls itself back.
  Idempotency keys mean a double-clicked Pay button produces one booking.
- **Pricing engine.** `MPFBS\Pricing\PricingService` produces an itemised,
  deterministic quote: passenger and vehicle fares, route fare overrides,
  per-departure adjustments, return and group discounts, per-booking and
  per-head fees, and inclusive or exclusive tax. The server is authoritative;
  the browser only previews.
- **Money handling.** Integer minor units throughout, with remainder-preserving
  allocation, so totals cannot drift by a cent. Money crosses the REST boundary
  as minor units in both directions, typed as an integer so a decimal is
  rejected rather than silently reinterpreted.
- **Documentation.** `docs/architecture.md`, `docs/rest-api.md`, `docs/hooks.md`.

### Security

- Every write endpoint verifies authentication, capability and object type, and
  sanitises through the entity schema; unknown fields are dropped rather than
  stored, so a client cannot write arbitrary post meta.
- Registered post meta carries an `auth_callback` bound to the owning
  capability, closing the generic meta write path.
- Log files live in a protected uploads directory under a per-site hashed
  filename, and context keys that look like credentials are redacted.
- The pre-rendered dashboard shell is rejected outright if it contains anything
  executable.
- Referential integrity is enforced on delete: a port in use by a route, a
  vessel assigned to a sailing, a route with sailings, or a sailing with
  bookings cannot be removed.
- Identifier uniqueness accounts for trashed records, so restoring one cannot
  produce two records sharing a code that is printed on tickets.

### Fixed

- Vessel double-booking detection compared the wrong interval and missed a
  crossing that was already at sea when the next departure was due.
- A success toast was rendered over the drawer's own Save and Cancel buttons.
- Disabling the submit button during a save dropped keyboard focus to the
  document body; focus now moves to the first field the server rejected.
- Navigating between resource screens briefly showed the previous resource's
  rows under the new heading.
- wp-admin's element styles outranked the dashboard's component classes, leaving
  buttons and inputs unstyled.
- A boolean field could never be stored as false when its default was true.
  `update_post_meta()` writes `false` as an empty string, which the hydrator
  could not tell apart from a row that was never written, so it fell back to the
  default — meaning a passenger-only crossing silently went on accepting
  vehicles. Booleans now store `1` and `0`, and the hydrator distinguishes an
  empty value from a missing one.
- `mpfbsFormat()` did not treat `%%` as an escaped percent sign the way its PHP
  counterpart does, so a percentage fare rendered as `80%% of base`.
- Table loading placeholders were exposed to assistive technology as five empty
  data rows.
- A sticky panel header sat over its own content: a panel clips its overflow,
  which makes it the containing block for sticky descendants, so the header was
  offset from the panel's top edge rather than pinned to the viewport. The save
  action now lives in a bar pinned outside the panel.
- A malformed fare in a route's price table was stored as zero — reading as "this
  crossing is free" — instead of falling back to the type's own fare.

### Performance

- Listings fetch ids first and prime post and meta caches in one pass, so a page
  of fifty rows costs two queries rather than fifty-one.
- Searchable identifiers are written to a plain-text index on the post so
  WordPress's own search finds them without scanning serialised meta.
- Admin assets load only on the Ferry Manager screen; nothing is enqueued
  globally.

### Changed

- The Save button on a configuration panel sticks below the header, so it stays
  reachable from the bottom of a list longer than the screen.
