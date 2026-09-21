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

== Source code and build ==

The JavaScript and CSS in `assets/` are compiled. Their full, human-readable
source ships with the plugin, and is also public at
https://github.com/magepeopleteam/ferry-booking-manager

* `apps/admin/` - the admin dashboard (Next.js, React, TypeScript). Its static
  export is written to `assets/admin/app/`.
* `apps/booking/` - the customer booking form (Preact, TypeScript, Vite). Its
  bundle is written to `assets/frontend/`.
* PHP runtime code lives in `src/` and is not compiled.

To rebuild the assets (Node.js 20 or newer):

`cd apps/admin && npm ci && npm run build`
`cd apps/booking && npm ci && npm run build`

== Frequently Asked Questions ==

= Does this require WooCommerce? =

No. WooCommerce is supported for checkout, coupons and taxes, and the plugin
works without it using its own checkout.

= Does it create database tables? =

No. Everything is stored in WordPress posts, post meta and options.

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
