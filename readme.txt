=== Ferry Booking Manager ===
Contributors: magepeople
Tags: ferry, booking, ticketing, woocommerce, transport
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ferry booking and ferry operations management for WordPress. Vessels, ports,
routes, sailings, passengers, vehicles, availability, pricing and bookings.

== Description ==

Ferry Booking Manager turns WordPress into a ferry reservation and operations
system. Manage your fleet and terminals, publish a timetable, sell passenger and
vehicle tickets through WooCommerce or a built-in checkout, and run the quayside
from one dashboard.

**What the free plugin includes**

* Unlimited vessels, ports, routes and sailings
* Bulk schedule generator with conflict detection and a preview before anything
  is created
* Per-sailing capacity overrides for passengers, vehicles and lane metres
* Operational roles for booking staff, cashiers and check-in crews
* Passenger and vehicle type management with server-validated configuration
* Booking workflows that use the same availability and pricing rules in admin,
  checkout and API requests
* Native checkout support, with WooCommerce available when you want its taxes,
  coupons, payment gateways and order workflows
* REST endpoints for dashboard, search, availability, bookings, setup,
  settings, pricing and reference data

**Built for real operations**

* One authoritative availability engine - the storefront and the counter can
  never disagree
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
* Public `fbm_*` hooks let developers extend entities, validation,
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

The public hook surface is versioned under the `fbm_` prefix. Extension points
include:

* `fbm_service_providers`
* `fbm_entity_fields`
* `fbm_validate_entity`
* `fbm_rest_controllers`
* `fbm_admin_config`
* `fbm_js_translations`
* `fbm_pro_active`

This keeps customisations inside normal WordPress hooks instead of requiring
direct edits to the plugin.

**Upgrade to Pro**

Ferry Booking Manager Pro extends the same free-plugin container and services.
It adds advanced commercial and operational tools including:

* PDF tickets and ticket download links
* QR token generation and check-in workflows
* Sailing manifests and export tools
* Cabins and deck-capacity management
* Dynamic pricing rules and extras pricing
* Booking modification, transfer and refund workflows
* POS and receipt rendering
* Agent accounts, commission handling and wallet tools
* Reports, automations, webhooks, calendars and email templates

If you need that expanded toolset, install Ferry Booking Manager Pro alongside
this plugin.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/ferry-booking-manager/`.
2. Activate it through the Plugins screen.
3. Open **Ferry Manager** in the admin menu.
4. Add your ports, then your vessels, then the routes between them.
5. Configure passenger types, vehicle types and checkout settings.
6. Publish sailings and test a booking flow before going live.

== Development ==

* Runtime code lives in `src/`, templates in `templates/`, and translations in
  `languages/`.
* The admin application source lives in `apps/admin/` and its production export
  is committed under `assets/admin/app/`.
* The booking frontend application source lives in `apps/booking/` and its
  built assets are committed under `assets/frontend/`.
* `build-zip.sh` creates a clean production zip and excludes developer-only
  directories such as `apps/` dependency trees, test scaffolding and Composer
  tooling.

== Frequently Asked Questions ==

= Does this require WooCommerce? =

No. WooCommerce is supported for checkout, coupons and taxes, and the plugin
works without it using its own checkout.

= Does it create database tables? =

No. Everything is stored in WordPress posts, post meta and options.

= Do I need Node.js on my server? =

No. Node is only used to build the dashboard before release.

= Can developers extend the data model and dashboard? =

Yes. The plugin exposes a public `fbm_*` hook surface for entities,
validation, capabilities, REST controllers, runtime config and translated UI
strings.

= What does Pro add on top of Free? =

Pro adds advanced ticketing, operations and commerce features such as PDF
tickets, QR check-in, manifests, cabins, dynamic pricing, extras, refunds,
POS, agent tools, reports, automations and webhooks.

== Changelog ==

See CHANGELOG.md for the full history.
