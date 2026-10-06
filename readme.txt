=== MagePeople Ferry Booking System ===
Contributors: magepeopleteam
Tags: ferry, booking, ticketing, woocommerce, transport
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell ferry tickets on your own website: ports, vessels, routes, timetables, fares and bookings, all from one dashboard.

== Description ==

MagePeople Ferry Booking System turns your WordPress site into a ferry
reservation system. Set up your terminals and vessels, publish a timetable,
and let customers search crossings, choose a sailing and pay online. Your
staff run the whole operation from a single dashboard inside WordPress.

It is built for operators who carry both passengers and vehicles: one crossing
can sell seats, cars, vans and motorcycles at the same time, each with its own
fare and its own space on board.

Everything described here works in this plugin. There is nothing to unlock, no
trial period and no licence key.

= Set up once, then run your timetable =

* Add your **ports**, with check-in times and boarding instructions
* Add your **vessels**, with passenger seats, vehicle spaces and deck metres
* Connect them with **routes**, in both directions
* Create a whole season of **sailings** in one step from a simple weekday and
  time pattern, and see exactly what will be created before it is created
* Override the capacity of any single sailing when you swap a vessel or hold
  seats back

= Sell passenger and vehicle tickets =

* Set a fare for every passenger type and vehicle type, per route
* Create your own **passenger types** — adult, child, senior, student,
  resident — with age rules, priced as a fixed fare, a percentage of the adult
  fare, or free
* Create your own **vehicle types** — car, van, motorcycle, bicycle — each
  taking the deck space you give it
* Add **taxes, booking fees, return discounts and group discounts**
* Choose what the booking form asks for: names, phone, date of birth,
  nationality, travel document details, vehicle registration and more

= Take bookings and payments =

* A booking form for your customers: search, pick a crossing, enter who is
  travelling, pay, done
* One-way and return journeys
* **Built-in checkout** with offline payment methods — cash, bank transfer,
  pay at the port, or a manual arrangement
* **WooCommerce checkout** instead, when you want card payments, coupons,
  taxes and orders handled by WooCommerce
* Seats are held while a customer pays, and released automatically if they do
  not finish
* Customers receive a confirmation email, can look up a booking with their
  reference and email address, and signed-in customers get a "My Bookings"
  page
* Your staff can take bookings at the counter or over the phone from the
  dashboard

= Run the quayside =

* A dashboard showing today's sailings, how full each one is, passengers and
  vehicles travelling, money taken and payments still outstanding
* A bookings list you can search by reference, customer or email, filter by
  status, and export to CSV
* Staff roles — **Ferry Manager** for everything, **Ferry Booking Manager**
  for sailings and bookings, **Ferry Cashier** for taking bookings and
  payments — plus a screen to fine-tune what each role may do
* Overbooking is prevented in one place, so your website and your counter can
  never sell the same seat twice

= Built the WordPress way =

* **No custom database tables.** Everything is stored as ordinary WordPress
  posts, meta and options, so your usual backup, staging and migration tools
  already understand your data
* **Translation ready**, and tested with WPML, Polylang and TranslatePress
* Works with any theme, and the booking form is available as a block as well
  as a shortcode
* Developer friendly: a REST API and documented `mpfbs_*` hooks and filters

= Try it with demo data first =

On a new install the dashboard offers to set up a complete demo ferry
operation — terminals, vessels, routes, fares and ten days of sailings, ready
to book. It is the quickest way to see how everything fits together, and it
can be removed again in one click without touching anything you created
yourself.

= Up and running in five minutes =

1. Activate the plugin and open **Ferry Manager** in your admin menu
2. Follow the **Get started** checklist, or install the demo data to explore
3. Add your ports, vessels and routes, then create your sailings
4. Set your fares, taxes and payment methods
5. Open the **Book a Crossing** page the plugin made for you and try a booking

== Installation ==

= From your WordPress dashboard =

1. Go to **Plugins > Add New** and search for "MagePeople Ferry Booking System"
2. Click **Install Now**, then **Activate**
3. Open **Ferry Manager** in the admin menu and follow the **Get started**
   checklist

= Uploading the plugin =

1. Download the plugin zip file
2. Go to **Plugins > Add New > Upload Plugin** and choose the zip
3. Click **Install Now**, then **Activate**
4. Open **Ferry Manager** in the admin menu

= What activation sets up for you =

Four pages are created automatically, so the booking flow works straight away:

* **Book a Crossing** — the booking form
* **Booking Confirmation** — what a customer sees after paying
* **My Bookings** — a signed-in customer's own bookings
* **Find My Booking** — looks a booking up from a reference and email address

Activation also adds the three staff roles and a starter set of passenger
types you can rename or replace.

= Putting the booking form on your own pages =

Use the blocks — search for "ferry" in the block inserter — or these
shortcodes:

* `[mpfbs_booking]` — the full booking flow
* `[mpfbs_search]` — a compact search form that sends people to the booking page
* `[mpfbs_confirmation]` — the confirmation page
* `[mpfbs_my_bookings]` — a customer's own bookings
* `[mpfbs_lookup]` — find a booking by reference and email

= Requirements =

WordPress 6.0 or newer and PHP 8.0 or newer. WooCommerce is optional.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. The plugin has its own checkout with offline payment methods. Add
WooCommerce only if you want card payments, coupons or taxes handled by it,
then switch to WooCommerce mode on the **Checkout** settings tab.

= Can I sell vehicle spaces as well as passenger seats? =

Yes. Vehicle types have their own fares and take up the deck space you give
them, so a sailing stops selling cars once the deck is full even when seats
are still available.

= Can customers book a return journey? =

Yes. Both legs are priced together, and you can offer a return discount.

= Does it prevent overbooking? =

Yes. Availability is worked out in one place for your website, your counter
and the API, and seats are held while a customer is paying. You can also hold
seats back from online sale on any sailing.

= Do I have to create every sailing by hand? =

No. Tell the schedule generator which days and times a route runs and between
which dates, and it creates the whole timetable. It previews everything first
and will not book one vessel into two places at once.

= Will it work with my theme? =

Yes. The booking form follows your theme's layout, and you can set an accent
colour to match your branding in the settings.

= Does it create database tables? =

No. Everything is stored using standard WordPress posts, meta and options.

= Is my customer data sent anywhere? =

No. Booking details stay in your own WordPress database. There is an optional
setting that pushes a purchase event to a tag manager you already run, and it
is off by default. The plugin also includes an opt-in telemetry SDK (see
External Services below) that only starts sending anything after you
confirm it in the admin notice it shows.

= What happens to my bookings if I delete the plugin? =

They stay. Deleting the plugin leaves your bookings and timetable in place,
unless you first switch on "Delete all ferry data when the plugin is deleted"
under **Settings > Advanced**.

= Can I translate it? =

Yes. The plugin is fully translation-ready and works with WPML, Polylang and
TranslatePress.

= Can developers extend it? =

Yes. There is a REST API under `mpfbs/v1` and documented `mpfbs_*` hooks and
filters for entities, validation, capabilities, pricing, REST controllers and
the dashboard. The plugin's complete source, including the readable source of
its compiled JavaScript and CSS, ships inside the plugin under `apps/` and is
published at https://github.com/magepeopleteam/ferry-booking-manager

= Where can I get help? =

In the support forum on this plugin's page. Please tell us what you were
doing, what you expected, and what happened instead.

== Screenshots ==

1. The dashboard: today's crossings, how full each one is, and takings so far
2. The booking form your customers use, from search through to payment
3. Fleet and schedule: ports, vessels, routes and sailings in one place
4. The schedule generator previewing a season of crossings before creating them
5. Fares for every passenger and vehicle type on a route
6. Bookings: search, filter, open a booking, export to CSV
7. Settings for checkout, payments, emails and staff permissions

== External Services ==

This plugin can connect to third-party services when you turn them on. Nothing is sent to these services unless you enable the related feature.

= Appneck =

This Plugin uses [Appneck](https://appneck.com) SDK to collect some telemetry data upon the user's confirmation to troubleshoot problems faster & make product improvements.
Appneck SDK **does not gather any data by default.** The SDK only starts gathering basic telemetry data **when a user allows it via the admin notice**. We collect the data to ensure a great user experience for all our users. Integrating Appneck SDK **DOES NOT IMMEDIATELY** start gathering data, **without confirmation from users in any case.**
Learn more about how [Appneck collects and uses this data](https://appneck.com/privacy-policy/).

== Changelog ==

= 1.0.4 =
* Fix: Updated the bundled Appneck SDK to the latest version.

= 1.0.3 =
* New: Updated the bundled Appneck SDK to the latest version.

= 1.0.2 =
* Fix: Corrected the Appneck privacy disclosure text in External Services to match the standard wording used across our plugins.

= 1.0.1 =
* New: added the Appneck SDK for opt-in telemetry and update tracking. It does not collect any data by default; it only starts after you confirm via the admin notice. See External services in this readme.

= 1.0.0 =
* First release.
* Ports, vessels, routes, sailings, passenger types and vehicle types.
* Schedule generator with a preview and vessel conflict detection.
* Availability with capacity limits, held-back seats, booking windows, and
  temporary holds while a customer pays.
* Fares per route and per type, taxes, booking fees, return and group
  discounts.
* Customer booking form with one-way and return journeys, booking lookup and
  a My Bookings page.
* Built-in checkout with offline payment methods, plus optional WooCommerce
  checkout.
* Staff bookings, a bookings list with CSV export, and a dashboard of the
  day's operation.
* Three staff roles with editable permissions.
* Confirmation, cancellation and staff notification emails.
* A demo ferry operation that installs and removes in one click.
* REST API and `mpfbs_*` hooks for developers.
* No custom database tables.

== Upgrade Notice ==

= 1.0.0 =
First release.
