# Hooks

All hooks are prefixed `fbm_`. These are part of the public contract and will
not change without a major version.

## Actions

| Hook | Fired when | Arguments |
| --- | --- | --- |
| `fbm_booted` | The container is built and every provider booted | `$container`, `$plugin` |
| `fbm_activated` | Activation completes | — |
| `fbm_deactivated` | Deactivation completes | — |
| `fbm_upgraded` | The stored version differs from the running one | `$from`, `$to` |
| `fbm_post_types_registered` | Post types and meta are registered | — |
| `fbm_rest_routes_registered` | REST routes are registered | `$container` |
| `fbm_daily_maintenance_run` | The daily maintenance cron runs | `$container` |
| `fbm_cache_group_flushed` | A cache group is invalidated | `$group`, `$version` |
| `fbm_{entity}_saved` | An entity is created or updated | `$entity`, `$created` |
| `fbm_{entity}_deleted` | An entity is deleted | `$entity`, `$force` |
| `fbm_schedule_generated` | A bulk schedule run creates sailings | `$ids`, `$pattern` |

`{entity}` is `port`, `vessel`, `route`, `sailing` or `booking`.

## Filters

| Hook | Purpose |
| --- | --- |
| `fbm_service_providers` | Add or reorder service providers |
| `fbm_entities` | Add an entity class |
| `fbm_entity_fields` | Add persisted properties to an entity (`$definitions`, `$key`) |
| `fbm_validate_entity` | Add cross-field validation rules |
| `fbm_serialize_{entity}` | Change an entity's API representation |
| `fbm_post_type_args` | Change post type registration arguments |
| `fbm_repository_query_args` | Change the `WP_Query` arguments of a listing |
| `fbm_capabilities`, `fbm_capability_labels` | Add capabilities |
| `fbm_role_definitions` | Change the provisioned roles |
| `fbm_current_user_can` | Override a capability check |
| `fbm_rest_controllers` | Register additional REST controllers |
| `fbm_rest_health_payload` | Add data to the health endpoint |
| `fbm_admin_config` | Add data to the dashboard runtime configuration |
| `fbm_js_translations` | Add strings to the interface dictionary |
| `fbm_currency_settings` | Override currency formatting |
| `fbm_cache_groups` | Register a cache group |
| `fbm_log_level` | Change or disable file logging |
| `fbm_pro_active` | Reports whether Pro is running |

## Extending an entity

```php
add_filter( 'fbm_entity_fields', function ( array $fields, string $entity ) {
    if ( 'vessel' !== $entity ) {
        return $fields;
    }

    $fields['imo_number'] = array(
        'type'        => 'string',
        'meta'        => '_fbm_imo_number',
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
