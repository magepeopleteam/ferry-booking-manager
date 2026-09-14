# Architecture

## Layers

```
REST controller      validate, authorise, serialise — no business rules
      ↓
Service              booking, availability, pricing, scheduling
      ↓
Repository           the only code that touches WordPress storage APIs
      ↓
WordPress            posts, post meta, options, transients, users
```

`FBM\Core\Plugin` owns a small container and a list of service providers. A
provider binds services in `register()` and attaches hooks in `boot()`. Pro adds
its own providers through the `fbm_service_providers` filter; it never replaces
or duplicates a Free service.

## Storage

The plugin creates **no database tables**. Everything lives in the structures
WordPress already backs up, migrates and replicates:

| Data | Stored as |
| --- | --- |
| Vessels, ports, routes, sailings, bookings | Custom post types |
| Their properties | Post meta, one dedicated scalar key per property |
| Configuration | Options, all prefixed `fbm_` |
| Temporary holds and cached aggregates | Transients + object cache |

### Why one meta key per property

Serialised arrays cannot be filtered with an indexed query. Every property a
listing sorts, filters or reports on therefore gets its own scalar key, and
list-valued relationships are additionally mirrored into repeated meta rows so
they can be searched in reverse:

- `_fbm_route_port` — every port a route calls at, one row each
- `_fbm_booking_sailing` — every sailing a booking travels on

### Derived keys

Authored values and the projections used for sorting are stored separately, and
the projection is always recomputed from its source in `Entity::derive()`:

- `_fbm_departure_datetime` — what the operator typed, site-local wall clock
- `_fbm_departure_ts` — UTC timestamp, for numeric range queries
- `_fbm_departure_date` — local date, for day grouping and the calendar

Sailing times are stored as local wall-clock values on purpose: an 08:00
departure must stay 08:00 if the site timezone is later corrected.

## The admin application

The dashboard is a Next.js application exported to static HTML and JavaScript at
build time (`output: 'export'`) and served by WordPress from `assets/admin/app/`.
Node is a build-time dependency only; nothing runs on the customer's host.

`apps/admin/scripts/prepare-wp-build.mjs` post-processes the export into
`fbm-app.json`: the stylesheets, the script chunks in document order, the
pre-rendered shell markup, and the hydration payload. `FBM\Admin\AppRenderer`
enqueues those with real URLs and prints the shell, and
`FBM\Core\Assets` sets the bundler's chunk base path so lazily imported chunks
resolve against the plugin rather than the site root.

### Pages Router, not App Router

The specification prefers the App Router. It is not usable here: an App Router
export hydrates `document`, because its root layout owns `<html>` and `<body>`.
Embedding that inside an existing wp-admin page means React reconciling
WordPress's entire document. The Pages Router hydrates a single container, which
is exactly what embedding requires. The hard constraint — a static export served
from plugin assets with no Node runtime — is met either way, so the router
choice follows the constraint.

### Why the exported shell is a skeleton

The export has no access to the WordPress runtime configuration, so anything
derived from it — capabilities, translations, the signed-in user — would differ
between the server-rendered markup and the first client render, and React would
discard the pre-rendered output. The export therefore contains a pure structural
skeleton, which paints instantly and hydrates without a mismatch.

## Styling

Every rule is scoped under `.fbm-app`. wp-admin styles elements directly
(`input[type="text"]`, specificity 0-1-1), which outranks a bare component class
(0-1-0); scoping raises every component rule to 0-2-0 so the dashboard's controls
are never restyled from underneath. All overlays — drawers, dialogs, the command
palette, toasts — render inside that root for the same reason.

## Translations

Interface strings are translated in PHP and delivered in the runtime
configuration, keyed by their English source text. That keeps every string in the
plugin's `.pot` file and working with WPML, Polylang and TranslatePress without a
second catalogue. `apps/admin/scripts/check-translations.mjs` runs on every build
and fails it if the two sides disagree.

## Money

Amounts are integers of minor units everywhere — storage, calculation and the
API. Binary floating point cannot represent most decimal fractions exactly, and a
fare assembled from a passenger price, a vehicle price and a percentage discount
in floats eventually lands a cent out. `FBM\Support\Money` owns the conversions,
percentage rounding and remainder-preserving allocation.
