# Hooks

All hooks are prefixed `mpfbs_`. These are part of the public contract and will
not change without a major version.

## Actions

| Hook | Fired when | Arguments |
| --- | --- | --- |
| `mpfbs_booted` | The container is built and every provider booted | `$container`, `$plugin` |
| `mpfbs_activated` | Activation completes | — |
| `mpfbs_deactivated` | Deactivation completes | — |
| `mpfbs_upgraded` | The stored version differs from the running one | `$from`, `$to` |
| `mpfbs_post_types_registered` | Post types and meta are registered | — |
| `mpfbs_rest_routes_registered` | REST routes are registered | `$container` |
| `mpfbs_daily_maintenance_run` | The daily maintenance cron runs | `$container` |
| `mpfbs_cache_group_flushed` | A cache group is invalidated | `$group`, `$version` |
| `mpfbs_{entity}_saved` | An entity is created or updated | `$entity`, `$created` |
| `mpfbs_{entity}_deleted` | An entity is deleted | `$entity`, `$force` |
| `mpfbs_schedule_generated` | A bulk schedule run creates sailings | `$ids`, `$pattern` |

`{entity}` is `port`, `vessel`, `route`, `sailing` or `booking`.

## Filters

| Hook | Purpose |
| --- | --- |
| `mpfbs_service_providers` | Add or reorder service providers |
| `mpfbs_entities` | Add an entity class |
| `mpfbs_entity_fields` | Add persisted properties to an entity (`$definitions`, `$key`) |
| `mpfbs_validate_entity` | Add cross-field validation rules |
| `mpfbs_serialize_{entity}` | Change an entity's API representation |
| `mpfbs_post_type_args` | Change post type registration arguments |
| `mpfbs_repository_query_args` | Change the `WP_Query` arguments of a listing |
| `mpfbs_capabilities`, `mpfbs_capability_labels` | Add capabilities |
| `mpfbs_role_definitions` | Change the provisioned roles |
| `mpfbs_current_user_can` | Override a capability check |
| `mpfbs_rest_controllers` | Register additional REST controllers |
| `mpfbs_rest_health_payload` | Add data to the health endpoint |
| `mpfbs_admin_config` | Add data to the dashboard runtime configuration |
| `mpfbs_js_translations` | Add strings to the interface dictionary |
| `mpfbs_currency_settings` | Override currency formatting |
| `mpfbs_cache_groups` | Register a cache group |
| `mpfbs_log_level` | Change or disable file logging |

## Extending an entity

```php
add_filter( 'mpfbs_entity_fields', function ( array $fields, string $entity ) {
    if ( 'vessel' !== $entity ) {
        return $fields;
    }

    $fields['imo_number'] = array(
        'type'        => 'string',
        'meta'        => '_mpfbs_imo_number',
        'max'         => 16,
        'searchable'  => true,
        'unique'      => true,
        'description' => __( 'IMO number', 'my-plugin' ),
    );

    return $fields;
}, 10, 2 );
```

The field is now sanitised, validated, persisted, searchable, exposed in the REST
schema and accepted by the write endpoints — with no further code.
