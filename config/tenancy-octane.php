<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 🚀 Octane Tenancy Configuration
|--------------------------------------------------------------------------
|
| Bu config dosyası ozerozay/octane-tenancy için optimize edilmiş
| default ayarlardır. Çoğu durumda hiçbir şeyi değiştirmenize gerek yok!
|
| 🎯 Optimize edilmiş production-ready ayarlar
| ⚡ Maximum performans için hazır
| 🛡️ Memory leak'ler otomatik olarak önleniyor
| 🔒 419 CSRF hataları çözülmüş
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | 🚀 TEMEL AYARLAR (Dokunmanıza Gerek Yok!)
    |--------------------------------------------------------------------------
    */
    
    'enabled' => env('OCTANE_TENANCY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | 🧠 MEMORY MANAGEMENT (Otomatik - En Önemli Özellik)
    |--------------------------------------------------------------------------
    |
    | Memory leak'leri önlemek için gerekli. Bu ayarları değiştirmeyin!
    |
    */

    'memory_management' => [
        'auto_cleanup' => true,                    // ✅ Otomatik bellek temizliği
        'reset_static_properties' => true,        // ✅ Static property temizleme
        'flush_singletons' => true,               // ✅ Singleton temizleme
        'force_gc' => true,                       // ✅ Garbage collection
        'gc_frequency' => 0,                      // ✅ Her istekte GC çalıştır
        'memory_threshold_mb' => 128,             // ⚙️ 128MB üzerinde GC zorla
        
        'opcache' => [
            'reset' => false,                     // ✅ Production'da false
            'invalidate_tenant_files' => true,   // ✅ Tenant dosyalarını invalidate et
            'monitor' => false,                   // ✅ Production'da false
            'hit_rate_threshold' => 95.0,         // ⚙️ %95 hit rate hedefi
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ⚡ PERFORMANCE (Maksimum Hız İçin Optimize)
    |--------------------------------------------------------------------------
    */

    'performance' => [
        'cache_tenant_resolution' => true,        // ✅ Tenant çözümlemesi cache'le (5x hız)
        'tenant_cache_ttl' => 300,                // ⚙️ 5 dakika cache
        'optimize_event_listeners' => true,      // ✅ Event optimization
        'database_connection_pooling' => true,   // ✅ DB connection pool
        'lazy_connections' => true,              // ✅ Lazy loading
        'cache_routes' => true,                  // ✅ Route cache
    ],

    /*
    |--------------------------------------------------------------------------
    | 🛡️ SESSION & CSRF (419 Hata Önleme)
    |--------------------------------------------------------------------------
    |
    | 419 "Page Expired" hatalarını önlemek için optimize edilmiş ayarlar
    |
    */

    'session' => [
        'isolation_strategy' => 'tenant_prefix',  // ✅ Tenant bazlı session
        'strict_scoping' => true,                // ✅ Katı session kontrolü
        'csrf_per_tenant' => true,               // ✅ Her tenant için ayrı CSRF
        'cleanup_on_switch' => true,             // ✅ Tenant değişiminde temizle
    ],

    /*
    |--------------------------------------------------------------------------
    | 🔧 SERVER AYARLARI (Otomatik Algılanır)
    |--------------------------------------------------------------------------
    */

    'servers' => [
        'frankenphp' => [
            'num_threads' => env('FRANKENPHP_NUM_THREADS', 8),
            'enable_worker' => true,
            'memory_limit' => '256M',
            'max_requests' => 1000,
        ],
        
        'swoole' => [
            'worker_num' => env('SWOOLE_WORKER_NUM', 8),
            'task_workers' => env('SWOOLE_TASK_WORKERS', 4),
            'enable_coroutine' => false,          // ✅ Tenancy için kapalı
            'max_requests' => 1000,
        ],
        
        'roadrunner' => [
            'num_workers' => env('RR_NUM_WORKERS', 8),
            'max_jobs' => 1000,
            'memory_limit' => '256M',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 💾 CACHE (Redis Öneriliyor)
    |--------------------------------------------------------------------------
    */

    'caching' => [
        'tenant_cache_driver' => env('CACHE_DRIVER', 'redis'),
        'central_cache_driver' => env('CACHE_DRIVER', 'redis'),
        'cache_prefix_strategy' => 'tenant_id',
        'cache_tags_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | 🗄️ DATABASE OPTIMIZATION
    |--------------------------------------------------------------------------
    */

    'database' => [
        'connection_pooling' => true,             // ✅ Connection pool
        'lazy_connections' => true,              // ✅ Lazy loading  
        'tenant_connection_cache' => true,       // ✅ Config cache
        'max_connections_per_tenant' => 5,       // ⚙️ Max 5 connection per tenant
    ],

    /*
    |--------------------------------------------------------------------------
    | 🛡️ SECURITY & ISOLATION
    |--------------------------------------------------------------------------
    */

    'security' => [
        'strict_tenant_isolation' => true,       // ✅ Katı tenant izolasyonu
        'prevent_cross_access' => true,          // ✅ Cross-tenant erişim engelle
        'state_protection' => true,              // ✅ State bleeding koruması
        'validate_context' => true,              // ✅ Context doğrulama
    ],

    /*
    |--------------------------------------------------------------------------
    | 🚨 ERROR HANDLING
    |--------------------------------------------------------------------------
    */

    'error_handling' => [
        'fallback_to_central_on_error' => true,  // ✅ Hata durumunda central'a git
        'retry_tenant_resolution' => 3,          // ⚙️ 3 defa dene
        'auto_recover' => true,                   // ✅ Otomatik recovery
        'log_tenant_errors' => true,             // ✅ Hataları logla
    ],

    /*
    |--------------------------------------------------------------------------
    | 👨‍💻 DEBUG (Sadece Development!)
    |--------------------------------------------------------------------------
    |
    | ⚠️ Production'da debug ayarlarını FALSE yapın!
    |
    */

    'debug' => [
        'enabled' => env('APP_DEBUG', false),
        'log_memory_usage' => env('APP_DEBUG', false),
        'log_cleanup_operations' => env('APP_DEBUG', false),
        'profile_switches' => env('APP_DEBUG', false),
        'track_lifecycle' => env('APP_DEBUG', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | ⚙️ WORKER MANAGEMENT
    |--------------------------------------------------------------------------
    */

    'workers' => [
        'max_requests_per_worker' => 1000,       // ⚙️ Worker restart limiti
        'memory_threshold_mb' => 300,            // ⚙️ Memory limiti (300MB)
        'health_check' => true,                  // ✅ Health monitoring
        'balancing' => true,                     // ✅ Load balancing
    ],

];

/*
|--------------------------------------------------------------------------
| 📚 ENVIRONMENT VARIABLES (.env dosyanıza ekleyin)
|--------------------------------------------------------------------------
|
| # Octane Tenancy
| OCTANE_TENANCY_ENABLED=true
| 
| # Server (frankenphp, swoole, roadrunner)
| OCTANE_SERVER=frankenphp
| FRANKENPHP_NUM_THREADS=8
| 
| # Cache (Redis öneriliyor)
| CACHE_DRIVER=redis
| 
| # Debug (Production'da false!)
| APP_DEBUG=false
|
|--------------------------------------------------------------------------
| 🚀 HIZLI BAŞLANGIČ
|--------------------------------------------------------------------------
|
| 1. Hiçbir ayarı değiştirmeden başlayın
| 2. Sadece .env'ye yukarıdaki değerleri ekleyin  
| 3. php artisan octane:start --server=frankenphp
| 4. Sistem otomatik optimize çalışacak!
|
|--------------------------------------------------------------------------
| 📞 SORUN ÇÖZME
|--------------------------------------------------------------------------
|
| 419 Hataları: Session ayarları otomatik optimize
| Memory Leaks: Otomatik cleanup açık
| Performance: Cache ve pooling açık
| 
| Tüm optimize ayarlar default olarak hazır! 🎉
|
*/