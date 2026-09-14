# REST API

Namespace: `/wp-json/fbm/v1/`

Every endpoint has a permission callback, argument sanitisation, schema
validation and a predictable response shape. Nothing is public: the current
endpoints all require an authenticated user holding the relevant capability.

## Response shape

Success:

```json
{ "success": true, "data": {}, "meta": { "page": 1, "per_page": 20, "total": 120, "total_pages": 6 } }
```

Failure:

```json
{ "success": false, "code": "fbm_validation_failed", "message": "Port code is required.", "data": { "fields": { "code": "Port code is required." } } }
```

`data.fields` maps a field name to the message the interface renders beside that
input. `rest_post_dispatch` normalises failures raised by WordPress itself into
the same shape, so a client never has to handle two error formats.

## Endpoints

| Method | Route | Capability |
| --- | --- | --- |
| GET | `/health` | `fbm_access_dashboard` |
| GET | `/references` | `fbm_access_dashboard` |
| GET POST | `/ports` | `fbm_manage_ports` |
| GET PUT PATCH DELETE | `/ports/{id}` | `fbm_manage_ports` |
| GET POST | `/vessels` | `fbm_manage_vessels` |
| GET PUT PATCH DELETE | `/vessels/{id}` | `fbm_manage_vessels` |
| GET POST | `/routes` | `fbm_manage_routes` |
| GET PUT PATCH DELETE | `/routes/{id}` | `fbm_manage_routes` |
| GET POST | `/sailings` | `fbm_manage_sailings` |
| GET PUT PATCH DELETE | `/sailings/{id}` | `fbm_manage_sailings` |
| POST | `/sailings/schedule` | `fbm_manage_sailings` |
| GET POST | `/passenger-types` | `fbm_manage_settings` |
| GET PUT PATCH DELETE | `/passenger-types/{id}` | `fbm_manage_settings` |
| GET POST | `/vehicle-types` | `fbm_manage_settings` |
| GET PUT PATCH DELETE | `/vehicle-types/{id}` | `fbm_manage_settings` |
| GET PUT PATCH POST | `/field-config/{passenger\|vehicle}` | `fbm_manage_settings` |
| GET | `/availability/{sailing}` | `fbm_access_dashboard` |
| GET | `/availability?sailings=1,2,3` | `fbm_access_dashboard` |
| POST | `/quote` | `fbm_access_dashboard` |
| GET PUT PATCH POST | `/pricing/settings` | `fbm_manage_pricing` |

### Collection parameters

`page`, `per_page` (20, 50 or 100), `search`, `status`, `orderby`, `order`.
Listings are always server-paginated; no endpoint returns an unbounded set.

Sailings additionally accept `route_id`, `vessel_id`, `from`, `to` and `date`.
Routes accept `origin_port`, `destination_port` and `serves_port`.

Passenger and vehicle types default to `orderby=sort_order&order=asc`, because
they are authored in the order they appear on the booking form.

### Money

Every money field carries **an integer number of minor units** — cents, not
euros — in both directions. `1250` is €12.50. The REST schema types these fields
as integers, so a decimal is rejected rather than quietly reinterpreted: without
that rule `12` and `12.5` would have to mean minor and major units respectively,
and the same payload could price a fare two orders of magnitude apart.

Callers that are not the dashboard — the importer, WP-CLI, a CSV column — may
pass a string instead (`"12.50"`, `"12,50"`, `"1 250,00"`), which is parsed as a
major-unit amount. Only strings get that treatment.

### GET /field-config/{group}

Returns the capture fields for `passenger` or `vehicle`, each with a `mode` of
`off`, `optional` or `required`, plus `locked` (the plugin will not let the
field be switched off) and `custom` (operator-defined). PUT accepts `modes`
(field key to mode) and `custom` (a list of `{key,label,type,mode}`); unknown
keys, locked fields and invalid modes are dropped rather than rejected, and a
custom key that would collide with a built-in field is namespaced `custom_*`.

### POST /sailings/schedule

Expands a repeating pattern into individual sailings.

```json
{
  "route_id": 12, "vessel_id": 8,
  "date_from": "2026-06-01", "date_to": "2026-09-30",
  "weekdays": [1, 3, 5], "times": ["08:00", "13:00", "18:00"],
  "booking_close_minutes": 30,
  "commit": false
}
```

With `commit: false` it returns every candidate classified as `new`,
`duplicate` or `conflict` (with the id of the sailing it conflicts with), plus a
summary. With `commit: true` it creates only the `new` ones and reports what it
skipped. A pattern that would produce more than 500 sailings is refused.

### GET /availability/{sailing}

Returns capacity, sold, held, used and remaining for each measure —
`passengers`, `vehicles`, `lane_metres` — plus `bookable`, a machine-readable
`reason` when it is not, and `sold_out`. A `capacity` of `-1` means the measure
is not limited on that sailing.

Pass `passengers`, `vehicles` or `lane_metres` to also get `fits` and a
human-readable `message` for that request. Capacity resolves as sailing override
first, then the vessel's own figure.

### POST /quote

```json
{
  "sailing_id": 12, "return_sailing_id": 19,
  "passengers": { "31": 2, "34": 1 },
  "vehicles": { "48": 1 }
}
```

Keys are passenger and vehicle type ids, values are quantities. Returns the
itemised `lines`, the `gross`, `discount`, `subtotal`, `fees`, `tax` and `total`
in minor units, the `usage` the party consumes, and an `availability` block
saying whether each leg can still take it.

The quote is authoritative. A browser may show a running total, but the booking
is written with the figure this endpoint returns.

## Errors

| Code | Meaning |
| --- | --- |
| `fbm_not_authenticated` | 401, no signed-in user |
| `fbm_forbidden` | 403, capability missing |
| `fbm_not_found` | 404 |
| `fbm_validation_failed` | 422, see `data.fields` |
| `fbm_duplicate_value` | 409, an identifier is already in use |
| `fbm_vessel_conflict` | 409, the vessel is already sailing in that window |
| `fbm_port_in_use`, `fbm_vessel_in_use`, `fbm_route_in_use`, `fbm_sailing_has_bookings` | 409, referential integrity |
| `fbm_passenger_type_in_use`, `fbm_vehicle_type_in_use` | 409, a booking still references the type |
| `fbm_sailing_not_bookable` | 409, see `data.reason`: cancelled, not_open_yet, booking_closed, departed |
| `fbm_insufficient_capacity` | 409, see `data.measure`, `data.remaining` |
| `fbm_capacity_taken` | 409, a concurrent booking took the space during checkout |
| `fbm_capacity_busy` | 409, too many simultaneous bookings to decide safely; retry |
| `fbm_hold_expired` | 409, the hold lapsed and the space has gone |
| `fbm_empty_quote`, `fbm_party_too_large` | 400, the party makes no sense |
