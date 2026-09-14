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

**Management**

* Unlimited vessels, ports, routes and sailings
* Bulk schedule generator with conflict detection and a preview before anything
  is created
* Per-sailing capacity overrides for passengers, vehicles and lane metres
* Operational roles for booking staff, cashiers and check-in crews

**Built for real operations**

* One authoritative availability engine — the storefront and the counter can
  never disagree
* Server-calculated prices; the interface only ever previews them
* A vessel cannot be scheduled in two places at once
* Records still in use cannot be deleted out from under a booking

**Built for WordPress**

* No custom database tables. Your existing backups, migrations and multisite
  tooling already understand the data.
* No Node.js process on your server. The dashboard ships pre-built.
* Translation-ready, RTL-ready, and compatible with WPML, Polylang and
  TranslatePress.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/ferry-booking-manager/`.
2. Activate it through the Plugins screen.
3. Open **Ferry Manager** in the admin menu.
4. Add your ports, then your vessels, then the routes between them.

== Frequently Asked Questions ==

= Does this require WooCommerce? =

No. WooCommerce is supported for checkout, coupons and taxes, and the plugin
works without it using its own checkout.

= Does it create database tables? =

No. Everything is stored in WordPress posts, post meta and options.

= Do I need Node.js on my server? =

No. Node is only used to build the dashboard before release.

== Changelog ==

See CHANGELOG.md for the full history.
