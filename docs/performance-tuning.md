# ⚡ Performance Tuning Guide

This guide covers advanced performance optimization techniques for **ozerozay/octane-tenancy** running on Laravel Octane.

## 🎯 Performance Goals

Target benchmarks for a well-tuned setup:
- **Request Response Time**: < 10ms (simple routes)
- **Tenant Switching**: < 5ms per switch
- **Memory Growth**: < 1MB per 1000 requests
- **Throughput**: > 1000 requests/second

## 📊 Profiling & Monitoring

### 1. Enable Performance Monitoring

```bash
# .env
OCTANE_TENANCY_LOG_MEMORY=true
OCTANE_TENANCY_LOG_CLEANUP=true
OCTANE_TENANCY_TRACK_SINGLETONS=true
```

### 2. Memory Profiling

```php
// Add to your middleware
class MemoryProfilerMiddleware
{
    public function handle($request, Closure $next)
    {
        $startMemory = memory_get_usage(true);
        
        $response = $next($request);
        
        $endMemory = memory_get_usage(true);
        $memoryUsed = $endMemory - $startMemory;
        
        if ($memoryUsed > 1024 * 1024) { // > 1MB
            \Log::warning("High memory usage detected", [
                'route' => $request->route()?->getName(),
                'memory_used' => round($memoryUsed / 1024 / 1024, 2) . 'MB',
                'tenant' => tenant()?->getTenantKey(),
            ]);
        }
        
        return $response;
    }
}
```

### 3. Performance Metrics Collection

```php
// config/tenancy.php
'performance' => [
    'collect_metrics' => env('COLLECT_PERFORMANCE_METRICS', false),
    'metrics_storage' => 'redis', // or 'database'
],
```

## 🚀 Server-Specific Optimizations

### FrankenPHP Tuning

```yaml
# frankenphp.yaml
version: 1
global:
    debug: false
    grace_period: 10s

frankenphp:
    num_threads: 8  # CPU cores * 2
    max_requests_per_worker: 1000
    
octane:
    memory_limit: 256M
    max_execution_time: 30
```

#### Environment Variables
```bash
# .env
FRANKENPHP_NUM_THREADS=8
FRANKENPHP_ENABLE_WORKER=true
FRANKENPHP_MAX_REQUESTS=1000

# PHP Settings
PHP_MEMORY_LIMIT=256M
PHP_MAX_EXECUTION_TIME=30
```

### Swoole Optimizations

```php
// config/octane.php
'swoole' => [
    'options' => [
        'log_level' => SWOOLE_LOG_WARNING,
        'trace_flags' => 0,
        'log_file' => storage_path('logs/swoole.log'),
        
        // Worker settings
        'worker_num' => 8,
        'task_worker_num' => 4,
        'max_request' => 1000,
        'max_wait_time' => 60,
        
        // Memory optimizations
        'memory_pool_size' => 128 * 1024 * 1024, // 128MB
        'buffer_output_size' => 32 * 1024 * 1024, // 32MB
        
        // Connection optimizations
        'max_conn' => 1000,
        'heartbeat_check_interval' => 60,
        'heartbeat_idle_time' => 600,
        
        // Performance settings
        'enable_coroutine' => false, // Disable for stability
        'enable_preemptive_scheduler' => false,
        'hook_flags' => SWOOLE_HOOK_ALL,
    ],
],
```

### RoadRunner Configuration

```yaml
# .rr.yaml
version: 2.7

rpc:
  listen: tcp://127.0.0.1:6001

server:
  command: "php worker.php"
  
http:
  address: "0.0.0.0:8000"
  middleware: ["gzip", "static"]
  pool:
    num_workers: 8
    max_jobs: 1000
    allocate_timeout: 60s
    destroy_timeout: 60s

logs:
  level: warn
  output: stderr

metrics:
  address: "127.0.0.1:2112"
```

## 💾 Memory Optimization

### 1. Static Property Management

```php
// Create custom static manager
class StaticPropertyManager
{
    private static array $cleanupCallbacks = [];
    
    public static function register(string $class, Closure $cleanup): void
    {
        static::$cleanupCallbacks[$class] = $cleanup;
    }
    
    public static function cleanup(): void
    {
        foreach (static::$cleanupCallbacks as $cleanup) {
            $cleanup();
        }
    }
}

// Register in your service provider
StaticPropertyManager::register(YourClass::class, function() {
    YourClass::resetStatic();
});
```

### 2. Singleton Lifecycle Management

```php
// config/tenancy.php
'octane' => [
    'singleton_lifecycle' => [
        'request_scoped' => [
            'OzerOzay\OctaneTenancy\Tenancy',
            'cache',
            'session',
        ],
        'persistent' => [
            'OzerOzay\OctaneTenancy\Database\DatabaseManager',
            'events',
            'router',
        ],
    ],
],
```

### 3. Garbage Collection Optimization

```php
// In your OctaneCompatibilityManager
protected function optimizedGarbageCollection(): void
{
    // Force immediate collection
    $collected = gc_collect_cycles();
    
    if ($collected > 100) {
        \Log::debug("High garbage collection", [
            'cycles_collected' => $collected,
            'memory_before' => memory_get_usage(true),
            'memory_after' => memory_get_usage(true),
        ]);
    }
    
    // Disable GC during processing, enable after
    gc_disable();
    // ... process request ...
    gc_enable();
}
```

## 🗄️ Database Optimization

### 1. Connection Pooling

```php
// config/database.php
'connections' => [
    'mysql' => [
        // ... other settings
        
        'pool' => [
            'enable' => true,
            'min_connections' => 5,
            'max_connections' => 20,
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => 60.0,
        ],
        
        // Optimize MySQL settings
        'options' => [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ],
    ],
],
```

### 2. Tenant Database Caching

```php
class OptimizedTenantDatabaseManager
{
    private array $connectionCache = [];
    private int $maxCacheSize = 100;
    
    public function connection(Tenant $tenant): Connection
    {
        $key = $tenant->getTenantKey();
        
        if (!isset($this->connectionCache[$key])) {
            if (count($this->connectionCache) >= $this->maxCacheSize) {
                $this->evictOldestConnection();
            }
            
            $this->connectionCache[$key] = $this->createConnection($tenant);
        }
        
        return $this->connectionCache[$key];
    }
    
    private function evictOldestConnection(): void
    {
        $oldest = array_key_first($this->connectionCache);
        unset($this->connectionCache[$oldest]);
    }
}
```

### 3. Query Optimization

```php
// Use indexes effectively
Schema::table('tenants', function (Blueprint $table) {
    $table->index(['domain', 'is_active']); // Compound index
    $table->index('created_at');
    $table->index(['tenant_type', 'status']);
});

// Optimize tenant resolution queries
class OptimizedTenantResolver
{
    public function resolve(string $domain): ?Tenant
    {
        return Cache::remember(
            "tenant_resolution:{$domain}",
            300, // 5 minutes
            fn() => Tenant::with(['domains', 'settings'])
                ->whereHas('domains', fn($q) => $q->where('domain', $domain))
                ->where('is_active', true)
                ->first()
        );
    }
}
```

## 🚄 Cache Optimization

### 1. Multi-Level Caching

```php
class MultiLevelCache
{
    public function get(string $key): mixed
    {
        // Level 1: In-memory cache (fastest)
        if ($value = apcu_fetch("l1:{$key}")) {
            return unserialize($value);
        }
        
        // Level 2: Redis cache
        if ($value = Redis::get("l2:{$key}")) {
            apcu_store("l1:{$key}", $value, 60);
            return unserialize($value);
        }
        
        return null;
    }
    
    public function put(string $key, mixed $value, int $ttl = 300): void
    {
        $serialized = serialize($value);
        
        // Store in both levels
        apcu_store("l1:{$key}", $serialized, min($ttl, 60));
        Redis::setex("l2:{$key}", $ttl, $serialized);
    }
}
```

### 2. Intelligent Cache Warming

```php
class CacheWarmer
{
    public function warmTenantCache(Tenant $tenant): void
    {
        $tenant->run(function() use ($tenant) {
            // Pre-cache common queries
            Cache::remember('tenant_settings', 3600, fn() => 
                Setting::all()->pluck('value', 'key')->toArray()
            );
            
            Cache::remember('tenant_users_count', 900, fn() => 
                User::count()
            );
            
            // Pre-cache common routes
            $this->warmRouteCache($tenant);
        });
    }
    
    private function warmRouteCache(Tenant $tenant): void
    {
        $commonRoutes = ['/dashboard', '/profile', '/settings'];
        
        foreach ($commonRoutes as $route) {
            Cache::remember(
                "route_data:{$route}",
                600,
                fn() => $this->generateRouteData($route)
            );
        }
    }
}
```

### 3. Cache Invalidation Strategy

```php
class IntelligentCacheInvalidator
{
    public function invalidateTenantCache(Tenant $tenant, array $tags = []): void
    {
        $tenant->run(function() use ($tags) {
            if (empty($tags)) {
                // Clear all tenant cache
                Cache::flush();
            } else {
                // Clear specific tags
                Cache::tags($tags)->flush();
            }
        });
        
        // Clear tenant resolution cache
        Cache::forget("tenant_resolution:{$tenant->domain}");
        
        // Clear APCu cache if available
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }
}
```

## 🔄 Request Lifecycle Optimization

### 1. Optimized Middleware Stack

```php
// Optimize middleware order for performance
protected $middleware = [
    \Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode::class,
    \OzerOzay\OctaneTenancy\Middleware\EarlyTenantResolution::class, // Early resolution
    \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
    \OzerOzay\OctaneTenancy\Middleware\TenantCacheMiddleware::class,  // Cache after resolution
    \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    // ... other middleware
];
```

### 2. Request Batching

```php
class RequestBatcher
{
    private array $batch = [];
    private int $batchSize = 10;
    
    public function add(Tenant $tenant, Closure $operation): void
    {
        $this->batch[] = [$tenant, $operation];
        
        if (count($this->batch) >= $this->batchSize) {
            $this->processBatch();
        }
    }
    
    private function processBatch(): void
    {
        $groupedByTenant = collect($this->batch)
            ->groupBy(fn($item) => $item[0]->getTenantKey());
        
        foreach ($groupedByTenant as $tenantKey => $operations) {
            $tenant = $operations->first()[0];
            
            $tenant->run(function() use ($operations) {
                foreach ($operations as [$tenant, $operation]) {
                    $operation();
                }
            });
        }
        
        $this->batch = [];
    }
}
```

## 📈 Performance Monitoring

### 1. Real-time Metrics

```php
class PerformanceMonitor
{
    public function recordMetric(string $name, float $value, array $tags = []): void
    {
        $timestamp = microtime(true);
        
        Redis::zadd('metrics:' . $name, $timestamp, json_encode([
            'value' => $value,
            'tags' => $tags,
            'timestamp' => $timestamp,
        ]));
        
        // Keep only last hour of metrics
        Redis::zremrangebyscore('metrics:' . $name, 0, $timestamp - 3600);
    }
    
    public function getAverageResponseTime(int $seconds = 300): float
    {
        $since = microtime(true) - $seconds;
        
        $metrics = Redis::zrangebyscore('metrics:response_time', $since, '+inf');
        
        if (empty($metrics)) {
            return 0.0;
        }
        
        $values = array_map(fn($m) => json_decode($m, true)['value'], $metrics);
        
        return array_sum($values) / count($values);
    }
}
```

### 2. Alert System

```php
class PerformanceAlerter
{
    private array $thresholds = [
        'response_time' => 100, // ms
        'memory_usage' => 200 * 1024 * 1024, // 200MB
        'error_rate' => 0.05, // 5%
    ];
    
    public function checkThresholds(): void
    {
        foreach ($this->thresholds as $metric => $threshold) {
            $currentValue = $this->getCurrentValue($metric);
            
            if ($currentValue > $threshold) {
                $this->sendAlert($metric, $currentValue, $threshold);
            }
        }
    }
    
    private function sendAlert(string $metric, $current, $threshold): void
    {
        \Log::emergency("Performance threshold exceeded", [
            'metric' => $metric,
            'current' => $current,
            'threshold' => $threshold,
            'timestamp' => now(),
        ]);
        
        // Send to monitoring service (Sentry, New Relic, etc.)
    }
}
```

## 🧪 Load Testing

### 1. Apache Benchmark (ab)

```bash
# Basic load test
ab -n 10000 -c 100 http://localhost:8000/

# With authentication
ab -n 10000 -c 100 -H "Authorization: Bearer TOKEN" http://localhost:8000/api/users

# POST requests
ab -n 1000 -c 10 -p post.json -T application/json http://localhost:8000/api/data
```

### 2. Wrk Load Testing

```bash
# Install wrk
sudo apt install wrk

# Basic test
wrk -t12 -c400 -d30s http://localhost:8000/

# With Lua script for tenant testing
wrk -t12 -c400 -d30s -s tenant-test.lua http://localhost:8000/
```

```lua
-- tenant-test.lua
local tenants = {"tenant1", "tenant2", "tenant3", "tenant4", "tenant5"}

request = function()
    local tenant = tenants[math.random(#tenants)]
    local headers = {
        ["Host"] = tenant .. ".example.com"
    }
    
    return wrk.format("GET", "/dashboard", headers)
end
```

### 3. Custom Performance Test

```php
// tests/PerformanceTest.php
class LoadTest extends TestCase
{
    /** @test */
    public function it_handles_high_concurrent_load(): void
    {
        $tenants = Tenant::factory(10)->create();
        $requests = 1000;
        $concurrency = 50;
        
        $start = microtime(true);
        
        $responses = collect(range(1, $requests))
            ->chunk($concurrency)
            ->map(function ($chunk) use ($tenants) {
                return $chunk->map(function () use ($tenants) {
                    $tenant = $tenants->random();
                    
                    return $this->withHeader('Host', $tenant->domain)
                        ->get('/api/dashboard');
                });
            })
            ->flatten();
        
        $duration = microtime(true) - $start;
        $rps = $requests / $duration;
        
        expect($rps)->toBeGreaterThan(500); // 500+ RPS
        expect($responses->where('status', 200)->count())->toBe($requests);
        
        echo "\nLoad Test Results:\n";
        echo "Requests: {$requests}\n";
        echo "Duration: " . round($duration, 2) . "s\n";
        echo "RPS: " . round($rps, 2) . "\n";
    }
}
```

## 🎛️ Production Deployment

### 1. Process Manager Configuration

```ini
; /etc/supervisor/conf.d/octane.conf
[program:octane]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000 --workers=8
directory=/var/www
autostart=true
autorestart=true
startretries=3
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/octane.log
```

### 2. Health Checks

```php
// routes/health.php
Route::get('/health', function () {
    $checks = [
        'database' => $this->checkDatabase(),
        'redis' => $this->checkRedis(),
        'memory' => memory_get_usage(true) < 200 * 1024 * 1024,
        'tenancy' => $this->checkTenancy(),
    ];
    
    $healthy = !in_array(false, $checks, true);
    
    return response()->json([
        'status' => $healthy ? 'healthy' : 'unhealthy',
        'checks' => $checks,
        'timestamp' => now(),
    ], $healthy ? 200 : 503);
});
```

### 3. Graceful Shutdown

```php
// In your service provider
$this->app['events']->listen('octane.shutdown', function () {
    // Clean up resources
    DB::disconnect();
    Redis::disconnect();
    
    // Final cleanup
    gc_collect_cycles();
    
    \Log::info('Octane shutdown completed gracefully');
});
```

---

**Next**: [Migration Guide](./migration-guide.md)
