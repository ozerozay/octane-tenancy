# 🚀 Laravel Octane Setup Guide

This guide will help you set up **ozerozay/octane-tenancy** with Laravel Octane for maximum performance.

## 📋 Prerequisites

- PHP 8.4+
- Laravel 12.0+
- Laravel Octane 2.5+
- One of the supported servers: **FrankenPHP**, **Swoole**, or **RoadRunner**

## 🛠️ Installation

### 1. Install the Package

```bash
composer require ozerozay/octane-tenancy
```

### 2. Install Laravel Octane

```bash
composer require laravel/octane

# Choose your preferred server
php artisan octane:install --server=frankenphp  # Recommended
# OR
php artisan octane:install --server=swoole
# OR  
php artisan octane:install --server=roadrunner
```

### 3. Publish Configuration Files

```bash
# Publish tenancy config
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag="config"

# Publish Octane-specific config
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag="octane-config"

# Publish migrations
php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider" --tag="migrations"
```

### 4. Run Migrations

```bash
php artisan migrate
```

## ⚙️ Configuration

### Environment Variables

Add these to your `.env` file:

```bash
# Octane Server
OCTANE_SERVER=frankenphp

# Memory Management
OCTANE_TENANCY_AUTO_CLEANUP=true
OCTANE_TENANCY_FORCE_GC=true
OCTANE_TENANCY_RESET_STATICS=true
OCTANE_TENANCY_FLUSH_SINGLETONS=true

# Performance Optimizations
OCTANE_TENANCY_CACHE_RESOLUTION=true
OCTANE_TENANCY_PRELOAD_BOOTSTRAPPERS=false
OCTANE_TENANCY_OPTIMIZE_EVENTS=true
OCTANE_TENANCY_DB_POOLING=true

# Debugging (Development Only)
OCTANE_TENANCY_LOG_MEMORY=false
OCTANE_TENANCY_LOG_CLEANUP=false
OCTANE_TENANCY_MONITOR_STATICS=false

# Cache Configuration
OCTANE_TENANT_CACHE_DRIVER=redis
OCTANE_CENTRAL_CACHE_DRIVER=redis
OCTANE_CACHE_PREFIX_STRATEGY=tenant_id
OCTANE_CACHE_TAGS_ENABLED=true

# Database Configuration
OCTANE_DB_CONNECTION_POOLING=true
OCTANE_DB_LAZY_CONNECTIONS=true
OCTANE_DB_TENANT_CACHE=true
OCTANE_DB_CENTRAL_PERSISTENT=true
```

### Server-Specific Configuration

#### FrankenPHP (Recommended)
```bash
FRANKENPHP_NUM_THREADS=4
FRANKENPHP_ENABLE_WORKER=true
```

#### Swoole
```bash
SWOOLE_TASK_WORKERS=4
SWOOLE_TENANT_TASK_WORKER=true
SWOOLE_ENABLE_COROUTINE=false  # Disable for stability
```

#### RoadRunner
```bash
RR_NUM_WORKERS=4
RR_MAX_JOBS=1000
RR_TENANT_ISOLATION=true
```

## 🔧 Service Provider Setup

Update your `config/app.php`:

```php
'providers' => [
    // ... other providers
    Stancl\Tenancy\TenancyServiceProvider::class,
],

'aliases' => [
    // ... other aliases
    'Tenancy' => Stancl\Tenancy\Facades\Tenancy::class,
    'GlobalCache' => Stancl\Tenancy\Facades\GlobalCache::class,
],
```

## 🚦 Starting Octane

### Development
```bash
# FrankenPHP (Recommended)
php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000

# Swoole
php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000

# RoadRunner  
php artisan octane:start --server=roadrunner --host=0.0.0.0 --port=8000
```

### Production
```bash
# FrankenPHP with SSL
php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=443 --https

# With custom workers
php artisan octane:start --server=swoole --workers=8 --task-workers=4

# RoadRunner with config
php artisan octane:start --server=roadrunner --rpc-port=6001 --workers=8
```

## 🔄 Hot Reloading (Development)

Enable hot reloading for development:

```bash
# Watch for changes
php artisan octane:start --watch

# Or use specific paths
php artisan octane:start --watch=app,config,database,resources,routes
```

## 📊 Performance Testing

Run the included performance tests:

```bash
# Run all tests
composer octane-test

# Specific performance tests
./vendor/bin/pest tests/OctanePerformanceTest.php

# Benchmark your setup
composer octane-benchmark
```

## 🎯 Usage Examples

### Basic Tenant Resolution

```php
use Stancl\Tenancy\Facades\Tenancy;

// In your routes or controllers
Route::middleware(['octane-tenant'])->group(function () {
    Route::get('/dashboard', function () {
        $tenant = Tenancy::tenant();
        return view('dashboard', compact('tenant'));
    });
});
```

### Manual Tenant Switching

```php
use Stancl\Tenancy\Facades\Tenancy;

// Switch tenants safely
Tenancy::run($tenant, function () {
    // Your tenant-specific code
    $users = User::all(); // This will use tenant database
    Cache::put('users_count', $users->count());
});
```

### Global Context Operations

```php
use Stancl\Tenancy\Facades\GlobalCache;

// Use central/global cache
GlobalCache::put('global_setting', 'value');

// Run in central context
Tenancy::central(function () {
    // This runs in central context regardless of current tenant
    $allTenants = \App\Models\Tenant::all();
});
```

## 🛡️ Security Considerations

### 1. Tenant Isolation
```php
// Always verify tenant access
if (!$user->belongsToTenant($currentTenant)) {
    abort(403, 'Unauthorized tenant access');
}
```

### 2. Data Validation
```php
// Validate tenant-specific data
$tenant = Tenancy::resolveFromDomain(request()->getHost());
if (!$tenant || !$tenant->isActive()) {
    abort(404, 'Tenant not found');
}
```

### 3. Cache Isolation
```php
// Use tenant-prefixed cache keys
Cache::put("tenant.{$tenant->id}.user.{$user->id}", $data);
```

## 🚨 Troubleshooting

### Memory Leaks
```bash
# Enable memory monitoring
OCTANE_TENANCY_LOG_MEMORY=true
OCTANE_TENANCY_MONITOR_STATICS=true

# Check logs
tail -f storage/logs/laravel.log
```

### Performance Issues
```bash
# Profile your application
php artisan octane:start --workers=1  # Start with 1 worker
# Gradually increase based on your server capacity
```

### Connection Issues
```bash
# Clear all connections
php artisan octane:reload

# Reset specific services
php artisan cache:clear
php artisan config:clear
```

### Static Property Issues
```bash
# Check static property monitoring
OCTANE_TENANCY_MONITOR_STATICS=true

# Or reset manually in code
\Stancl\Tenancy\Tenancy::resetStaticState();
```

## 📈 Production Optimization

### 1. Caching Strategy
```php
// config/tenancy.php
'cache' => [
    'tenant_resolution' => true,
    'bootstrap_cache' => true,
    'route_cache' => true,
],
```

### 2. Database Optimization
```php
// config/database.php
'connections' => [
    'tenant' => [
        'pool' => [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10,
            'wait_timeout' => 3,
            'heartbeat' => -1,
            'max_idle_time' => 60,
        ],
    ],
],
```

### 3. Process Management
```bash
# Use a process manager like supervisord
[program:octane]
command=php /path/to/artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000
directory=/path/to/your/app
autostart=true
autorestart=true
user=www-data
```

## 🔍 Monitoring & Debugging

### Memory Usage
```php
// Add to your monitoring
\Log::info('Memory usage', [
    'current' => memory_get_usage(true),
    'peak' => memory_get_peak_usage(true),
    'tenant' => Tenancy::tenant()?->getTenantKey(),
]);
```

### Performance Metrics
```php
// Track tenant switching performance
$start = microtime(true);
Tenancy::initialize($tenant);
$duration = microtime(true) - $start;
\Log::debug("Tenant initialization took {$duration}ms");
```

## 🆘 Getting Help

- **Documentation**: [GitHub Wiki](https://github.com/ozerozay/octane-tenancy/wiki)
- **Issues**: [GitHub Issues](https://github.com/ozerozay/octane-tenancy/issues)  
- **Discussions**: [GitHub Discussions](https://github.com/ozerozay/octane-tenancy/discussions)

---

**Next**: [Performance Tuning Guide](./performance-tuning.md)
