<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Octane Tenancy Configuration
    |--------------------------------------------------------------------------
    |
    | This file is where you may configure your Octane-specific tenancy
    | settings. Each server (Swoole, FrankenPHP, RoadRunner) may have
    | different optimization strategies.
    |
    */

    'server' => env('OCTANE_SERVER', 'frankenphp'),

    /*
    |--------------------------------------------------------------------------
    | Memory Management
    |--------------------------------------------------------------------------
    |
    | These options control how tenancy handles memory management in
    | Octane environments to prevent memory leaks and ensure optimal
    | performance across requests.
    |
    */

    'memory_management' => [
        'auto_cleanup' => env('OCTANE_TENANCY_AUTO_CLEANUP', true),
        'force_gc' => env('OCTANE_TENANCY_FORCE_GC', true),
        'reset_static_properties' => env('OCTANE_TENANCY_RESET_STATICS', true),
        'flush_singletons' => env('OCTANE_TENANCY_FLUSH_SINGLETONS', true),
        'opcache_reset' => env('OCTANE_TENANCY_OPCACHE_RESET', false),
        'opcache_invalidate_tenant_files' => env('OCTANE_TENANCY_OPCACHE_INVALIDATE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Optimizations
    |--------------------------------------------------------------------------
    |
    | These settings control various performance optimizations specific
    | to tenancy in Octane environments.
    |
    */

    'performance' => [
        'cache_tenant_resolution' => env('OCTANE_TENANCY_CACHE_RESOLUTION', true),
        'preload_bootstrappers' => env('OCTANE_TENANCY_PRELOAD_BOOTSTRAPPERS', false),
        'optimize_event_listeners' => env('OCTANE_TENANCY_OPTIMIZE_EVENTS', true),
        'database_connection_pooling' => env('OCTANE_TENANCY_DB_POOLING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Debugging & Monitoring
    |--------------------------------------------------------------------------
    |
    | Enable these options for development and debugging purposes.
    | Disable in production for maximum performance.
    |
    */

    'debug' => [
        'log_memory_usage' => env('OCTANE_TENANCY_LOG_MEMORY', false),
        'log_cleanup_operations' => env('OCTANE_TENANCY_LOG_CLEANUP', false),
        'monitor_static_properties' => env('OCTANE_TENANCY_MONITOR_STATICS', false),
        'track_singleton_instances' => env('OCTANE_TENANCY_TRACK_SINGLETONS', false),
        'monitor_opcache' => env('OCTANE_TENANCY_MONITOR_OPCACHE', false),
        'opcache_hit_rate_threshold' => env('OCTANE_TENANCY_OPCACHE_HIT_RATE', 95.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Server-Specific Configurations
    |--------------------------------------------------------------------------
    |
    | Each Octane server may require different configurations for optimal
    | tenancy performance. These settings are applied based on the active server.
    |
    */

    'servers' => [
        'swoole' => [
            'task_workers' => env('SWOOLE_TASK_WORKERS', 4),
            'tenant_task_worker' => env('SWOOLE_TENANT_TASK_WORKER', true),
            'enable_coroutine' => env('SWOOLE_ENABLE_COROUTINE', false),
        ],

        'frankenphp' => [
            'num_threads' => env('FRANKENPHP_NUM_THREADS', 4),
            'enable_worker' => env('FRANKENPHP_ENABLE_WORKER', true),
        ],

        'roadrunner' => [
            'num_workers' => env('RR_NUM_WORKERS', 4),
            'max_jobs' => env('RR_MAX_JOBS', 1000),
            'tenant_isolation' => env('RR_TENANT_ISOLATION', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant-Specific Caching
    |--------------------------------------------------------------------------
    |
    | Configure how tenancy handles caching in Octane environments.
    | Different strategies may be optimal for different applications.
    |
    */

    'caching' => [
        'tenant_cache_driver' => env('OCTANE_TENANT_CACHE_DRIVER', 'redis'),
        'central_cache_driver' => env('OCTANE_CENTRAL_CACHE_DRIVER', 'redis'),
        'cache_prefix_strategy' => env('OCTANE_CACHE_PREFIX_STRATEGY', 'tenant_id'),
        'cache_tags_enabled' => env('OCTANE_CACHE_TAGS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Optimization
    |--------------------------------------------------------------------------
    |
    | Database-specific optimizations for multi-tenant applications
    | running under Octane.
    |
    */

    'database' => [
        'connection_pooling' => env('OCTANE_DB_CONNECTION_POOLING', true),
        'lazy_connections' => env('OCTANE_DB_LAZY_CONNECTIONS', true),
        'tenant_connection_cache' => env('OCTANE_DB_TENANT_CACHE', true),
        'central_connection_persistent' => env('OCTANE_DB_CENTRAL_PERSISTENT', true),
    ],

];
