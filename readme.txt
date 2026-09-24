=== MagePeople Ferry Booking System ===
Contributors: magepeopleteam
Tags: ferry, booking, ticketing, woocommerce, transport
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ferry booking and operations management for WordPress: vessels, routes, sailings, availability, pricing and bookings.

== Description ==

MagePeople Ferry Booking System turns WordPress into a ferry reservation and operations
system. Manage your fleet and terminals, publish a timetable, sell passenger and
vehicle tickets through WooCommerce or a built-in checkout, and run the quayside
from one dashboard.

**Source code for the compiled files**

Nothing in this plugin is minified-only. The complete, human-readable source
for every compiled file in `assets/` ships inside the plugin itself, under
`apps/`, and is also published at
https://github.com/magepeopleteam/ferry-booking-manager

Build instructions are in "Source code and build tools" below and in the
README.md file included with the plugin.

**What the free plugin includes**

* Unlimited vessels, ports, routes and sailings
* Bulk schedule generator with conflict detection and a preview before anything
  is created
* Per-sailing capacity overrides for passengers, vehicles and lane metres
* Operational roles for ferry managers, booking managers and cashiers
* Passenger and vehicle type management with server-validated configuration
* Booking workflows that use the same availability and pricing rules in admin,
  checkout and API requests
* Native checkout support, with WooCommerce available when you want its taxes,
  coupons, payment gateways and order workflows
* REST endpoints for dashboard, search, availability, bookings, setup,
  settings, pricing and reference data

**Built for real operations**

* One authoritative availability engine - the storefront and staff bookings
  can never disagree
* Server-calculated prices; the interface only ever previews them
* A vessel cannot be scheduled in two places at once
* Records still in use cannot be deleted out from under a booking
* Temporary holds and cached aggregates use transients and object cache, not
  custom tables or hidden background services
* Route, sailing and booking data are stored in normal WordPress post and meta
  structures so site migration, backup and replication tools keep working

**Built for WordPress**

* No custom database tables. Your existing backups, migrations and multisite
  tooling already understand the data.
* No Node.js process on your server. The dashboard ships pre-built.
* Translation-ready, RTL-ready, and compatible with WPML, Polylang and
  TranslatePress.
* Public `mpfbs_*` hooks let developers extend entities, validation,
  capabilities, REST controllers, runtime configuration and dashboard
  translations without patching core files

**Data model**

The free plugin manages these core records:

* Ports
* Vessels
* Routes
* Sailings
* Passenger types
* Vehicle types
* Bookings

Each property that needs filtering, sorting or reporting is stored in dedicated
meta keys, with derived timestamps and date projections generated from authored
values for reliable queries and calendar views.

**Admin experience**

The admin workspace is a static Next.js export served from plugin assets inside
wp-admin. That gives the plugin a modern application-style UI without needing a
Node.js process on the production site. The exported shell is intentionally a
structural skeleton so it can hydrate cleanly with live WordPress data such as
permissions, translations and settings.

**Frontend and checkout**

The free plugin supports:

* Search and availability queries for passenger and vehicle transport
* Server-authoritative booking validation
* Native checkout without WooCommerce
* Optional WooCommerce integration for stores that want its order, tax,
  coupon and payment ecosystem
* Booking, pricing and availability services shared across frontend,
  admin and REST APIs

**Developer and extension details**

The public hook surface is versioned under the `mpfbs_` prefix. Extension points
include:

* `mpfbs_service_providers`
* `mpfbs_entity_fields`
* `mpfbs_validate_entity`
* `mpfbs_rest_controllers`
* `mpfbs_admin_config`
* `mpfbs_js_translations`

This keeps customisations inside normal WordPress hooks instead of requiring
direct edits to the plugin.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/magepeople-ferry-booking-system/`.
2. Activate it through the Plugins screen.
3. Open **Ferry Manager** in the admin menu.
4. Add your ports, then your vessels, then the routes between them.
5. Configure passenger types, vehicle types and checkout settings.
6. Publish sailings and test a booking flow before going live.

== Source code and build tools ==

Every compiled file shipped in `assets/` is built from source that is included
in this plugin, and is also public at
https://github.com/magepeopleteam/ferry-booking-manager

**What is compiled, and where its source is**

* `assets/admin/app/` (the admin dashboard, including the files under
  `assets/admin/app/_next/static/chunks/`) is the production build of
  `apps/admin/`. Our source is `apps/admin/src/` (TypeScript and React); the
  build is produced by Next.js, whose bundler Turbopack also emits its own
  runtime and the React library code into those chunk files.
* `assets/frontend/` (the customer booking form) is the production build of
  `apps/booking/`. Our source is `apps/booking/src/` (TypeScript and Preact),
  bundled by Vite.
* `assets/admin/css/`, `assets/admin/js/` and everything in `src/` are written
  by hand and are not compiled or minified.

**Build tools and how to use them**

The builds need Node.js 20 or newer and npm 10 or newer. From the plugin
folder:

1. `cd apps/admin && npm ci && npm run build` - type-checks the sources,
   builds the Next.js static export, and writes it to `assets/admin/app/`.
2. `cd apps/booking && npm ci && npm run build` - type-checks the sources,
   builds the Vite bundle, and writes it to `assets/frontend/`.

`npm ci` installs the exact dependency versions pinned in each
`package-lock.json`, which is included, so the build is reproducible.

**Third-party libraries in the compiled files**

These libraries are compiled into the bundles above. All are MIT licensed,
which is GPL compatible, and all are publicly maintained. The exact versions
used are pinned in `apps/admin/package-lock.json` and
`apps/booking/package-lock.json`.

* Next.js, including its Turbopack bundler runtime - https://github.com/vercel/next.js
* React and React DOM - https://github.com/facebook/react
* Preact - https://github.com/preactjs/preact
* TypeScript (build only) - https://github.com/microsoft/TypeScript
* Vite (build only) - https://github.com/vitejs/vite

== Privacy ==

The plugin stores the booking details a customer or member of staff enters
(names, email, phone, travel document fields you choose to collect) as
WordPress posts and post meta on your own site. It does not send any data to
external services and does not contact any server of its own.

An optional setting on Settings > Integrations pushes a purchase event to the
page's `dataLayer` when a booking completes, for a tag manager you already
run. It is off by default and sends nothing by itself.

Booking data is only deleted on uninstall when "Delete all ferry data when the
plugin is deleted" is switched on under Settings > Advanced.

== Frequently Asked Questions ==

= Does this require WooCommerce? =

No. WooCommerce is supported for checkout, coupons and taxes, and the plugin
works without it using its own checkout.

= Does it create database tables? =

No. Everything is stored in WordPress posts, post meta and options.

= Where is the source code for the compiled JavaScript and CSS? =

In the plugin, under `apps/admin/` and `apps/booking/`, and also at
https://github.com/magepeopleteam/ferry-booking-manager - `apps/admin/src/`
builds to `assets/admin/app/` and `apps/booking/src/` builds to
`assets/frontend/`. The libraries compiled into those bundles (Next.js with
its Turbopack runtime, React, Preact) are listed with links under "Source code
and build tools". Build steps are in that section and in the included README.md.

= Do I need Node.js on my server? =

No. Node is only used to build the dashboard before release.

= Can developers extend the data model and dashboard? =

Yes. The plugin exposes a public `mpfbs_*` hook surface for entities,
validation, capabilities, REST controllers, runtime config and translated UI
strings.

== Changelog ==

= 1.0.0 =
* Initial release: vessels, ports, routes, sailings, passenger and vehicle
  types, a bulk schedule generator, and a capacity-and-hold-aware
  availability engine shared by search, the admin booking wizard and the
  REST API.
* Native checkout and optional WooCommerce integration, both driven by the
  same server-authoritative pricing engine.
* REST API (`mpfbs/v1`) with capability-gated endpoints for dashboard,
  search, availability, bookings, setup, settings, pricing and reference
  data.
* Admin dashboard: a single "Ferry Manager" screen hosting a pre-built
  Next.js application, with working Ports, Vessels, Routes and Sailings
  management.
* Eleven `mpfbs_` capabilities and three operational roles (Ferry Manager,
  Booking Manager, Cashier) provisioned on activation.
* No custom database tables; everything is stored in normal WordPress
  posts, post meta and options.
