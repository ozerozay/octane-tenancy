<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Octane;

use Closure;
use Illuminate\Foundation\Application;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickTerminated;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Octane compatibility manager for memory leak prevention and proper cleanup
 */
class OctaneCompatibilityManager implements OperationTerminated
{
    /**
     * Static properties that need cleanup between requests
     */
    protected static array $staticPropertiesToReset = [
        'Stancl\Tenancy\TenancyServiceProvider::$configure' => null,
        'Stancl\Tenancy\TenancyServiceProvider::$adjustCacheManagerUsing' => null,
        'Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain::$subdomainIndex' => 0,
        'Stancl\Tenancy\Facades\GlobalCache::$cached' => false,
        'Stancl\Tenancy\Tenancy::$findWith' => [],
    ];

    /**
     * Singletons that need to be flushed between requests to prevent memory leaks
     */
    protected static array $singletonsToFlush = [
        'Stancl\Tenancy\Tenancy',
        'Stancl\Tenancy\Database\DatabaseManager',
        'globalCache',
        'globalUrl',
    ];

    /**
     * Event listeners that accumulate memory
     */
    protected static array $eventListenersToClean = [
        'Stancl\Tenancy\Events\*',
    ];

    public function __construct(
        protected ?RequestContext $context = null
    ) {}

    /**
     * Required by OperationTerminated interface - Laravel application instance
     */
    public function app(): Application
    {
        return \app();
    }

    /**
     * Required by OperationTerminated interface - Sandbox instance (if applicable)
     */
    public function sandbox(): Application
    {
        return \app(); // Return the application instance since we don't use sandbox
    }

    /**
     * Handle the request terminated event
     */
    public function handle(RequestTerminated|TaskTerminated|TickTerminated $event): void
    {
        $this->resetStaticProperties();
        $this->flushSingletons();
        $this->cleanEventListeners();
        $this->forceTenancyEnd();
        $this->cleanGlobalState();
        $this->monitorOpcachePerformance();
    }

    /**
     * Reset all static properties to prevent memory leaks
     */
    protected function resetStaticProperties(): void
    {
        // Reset regular properties
        foreach (static::$staticPropertiesToReset as $property => $defaultValue) {
            [$class, $propertyName] = explode('::', $property);
            
            if (class_exists($class) && property_exists($class, ltrim($propertyName, '$'))) {
                $reflection = new \ReflectionClass($class);
                $reflectionProperty = $reflection->getProperty(ltrim($propertyName, '$'));
                $reflectionProperty->setAccessible(true);
                $reflectionProperty->setValue(null, $defaultValue);
            }
        }

        // Reset DatabaseConfig Closures safely
        $this->resetDatabaseConfigClosures();
    }

    /**
     * Reset DatabaseConfig static Closures to their default values
     */
    protected function resetDatabaseConfigClosures(): void
    {
        if (!class_exists('Stancl\Tenancy\Database\DatabaseConfig')) {
            return;
        }

        try {
            $reflection = new \ReflectionClass('Stancl\Tenancy\Database\DatabaseConfig');

            // Reset username generator
            if ($reflection->hasProperty('usernameGenerator')) {
                $property = $reflection->getProperty('usernameGenerator');
                $property->setAccessible(true);
                $property->setValue(null, function () {
                    return \Illuminate\Support\Str::random(16);
                });
            }

            // Reset password generator
            if ($reflection->hasProperty('passwordGenerator')) {
                $property = $reflection->getProperty('passwordGenerator');
                $property->setAccessible(true);
                $property->setValue(null, function () {
                    return \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32));
                });
            }

            // Reset database name generator
            if ($reflection->hasProperty('databaseNameGenerator')) {
                $property = $reflection->getProperty('databaseNameGenerator');
                $property->setAccessible(true);
                $property->setValue(null, function ($tenant, $self) {
                    $prefix = config('tenancy.database.prefix', '');
                    $suffix = config('tenancy.database.suffix', '');

                    if (!$suffix && method_exists($self, 'getTemplateConnection')) {
                        $templateConnection = $self->getTemplateConnection();
                        if (($templateConnection['driver'] ?? '') === 'sqlite') {
                            $suffix = '.sqlite';
                        }
                    }

                    return $prefix . $tenant->getTenantKey() . $suffix;
                });
            }

        } catch (\Throwable $e) {
            // Log the error but don't break the cleanup process
            if (config('octane.debug.log_cleanup', false)) {
                \Log::warning('Failed to reset DatabaseConfig closures', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    /**
     * Flush memory-heavy singletons
     */
    protected function flushSingletons(): void
    {
        $app = \app();
        
        foreach (static::$singletonsToFlush as $singleton) {
            if ($app->bound($singleton)) {
                $app->forgetInstance($singleton);
            }
        }
    }

    /**
     * Clean up event listeners to prevent memory accumulation
     */
    protected function cleanEventListeners(): void
    {
        // Force garbage collection of event listeners
        $events = \app('events');
        
        // Clear tenancy-specific event listeners
        $listeners = $events->getListeners();
        foreach ($listeners as $eventName => $eventListeners) {
            if (str_contains($eventName, 'Stancl\\Tenancy\\Events')) {
                // Keep essential listeners, remove accumulated ones
                $events->forget($eventName);
            }
        }
    }

    /**
     * Force tenancy to end to prevent context bleeding
     */
    protected function forceTenancyEnd(): void
    {
        if (\app()->bound('Stancl\Tenancy\Tenancy')) {
            $tenancy = \app('Stancl\Tenancy\Tenancy');
            if ($tenancy->initialized) {
                $tenancy->end();
            }
        }
    }

    /**
     * Clean global state and force garbage collection
     */
    protected function cleanGlobalState(): void
    {
        // Handle OPcache cleanup based on environment
        $this->handleOpcacheCleanup();

        // Force PHP garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    /**
     * Handle OPcache cleanup with environment-aware logic
     */
    protected function handleOpcacheCleanup(): void
    {
        if (!$this->isOpcacheEnabled()) {
            return;
        }

        // Only reset OPcache in development or when explicitly enabled
        $shouldReset = config('octane.memory_management.opcache_reset', false) 
            || $this->isDevelopmentEnvironment();

        if ($shouldReset && function_exists('opcache_reset')) {
            opcache_reset();
            
            if (config('octane.debug.log_cleanup', false)) {
                \Log::debug('OPcache reset performed', [
                    'memory_before' => memory_get_usage(true),
                    'opcache_stats' => $this->getOpcacheStats(),
                ]);
            }
        }

        // Always invalidate tenant-specific files if possible
        $this->invalidateTenantSpecificOpcache();
    }

    /**
     * Check if OPcache is enabled
     */
    public function isOpcacheEnabled(): bool
    {
        return function_exists('opcache_get_status') && opcache_get_status() !== false;
    }

    /**
     * Get OPcache statistics for monitoring
     */
    public function getOpcacheStats(): array
    {
        if (!$this->isOpcacheEnabled()) {
            return ['enabled' => false];
        }

        $status = opcache_get_status();
        
        return [
            'enabled' => true,
            'memory_usage' => $status['memory_usage'] ?? [],
            'opcache_statistics' => $status['opcache_statistics'] ?? [],
            'scripts_count' => $status['opcache_statistics']['num_cached_scripts'] ?? 0,
            'hit_rate' => $this->calculateOpcacheHitRate($status),
        ];
    }

    /**
     * Calculate OPcache hit rate
     */
    protected function calculateOpcacheHitRate(array $status): float
    {
        $stats = $status['opcache_statistics'] ?? [];
        $hits = $stats['hits'] ?? 0;
        $misses = $stats['misses'] ?? 0;
        $total = $hits + $misses;
        
        return $total > 0 ? round(($hits / $total) * 100, 2) : 0.0;
    }

    /**
     * Check if we're in development environment
     */
    protected function isDevelopmentEnvironment(): bool
    {
        return \app()->environment(['local', 'development', 'testing']);
    }

    /**
     * Invalidate tenant-specific OPcache entries
     */
    protected function invalidateTenantSpecificOpcache(): void
    {
        if (!function_exists('opcache_invalidate') || !$this->isOpcacheEnabled()) {
            return;
        }

        // Get tenant-specific files that might need invalidation
        $tenantFiles = $this->getTenantSpecificFiles();
        
        foreach ($tenantFiles as $file) {
            if (file_exists($file)) {
                opcache_invalidate($file, true);
            }
        }
    }

    /**
     * Get list of tenant-specific files for OPcache invalidation
     */
    protected function getTenantSpecificFiles(): array
    {
        // Common tenant-specific files that might change
        return [
            base_path('config/tenancy.php'),
            base_path('app/Providers/TenancyServiceProvider.php'),
            // Add more tenant-specific files as needed
        ];
    }

    /**
     * Validate OPcache configuration for Octane
     */
    public static function validateOpcacheConfiguration(): array
    {
        $recommendations = [];
        
        if (!function_exists('opcache_get_configuration')) {
            return ['OPcache not available'];
        }

        $config = opcache_get_configuration();
        $directives = $config['directives'] ?? [];

        // Check critical settings
        if (!($directives['opcache.enable'] ?? false)) {
            $recommendations[] = 'Enable OPcache: opcache.enable=1';
        }

        if (!($directives['opcache.enable_cli'] ?? false)) {
            $recommendations[] = 'Enable OPcache for CLI: opcache.enable_cli=1';
        }

        $memoryConsumption = $directives['opcache.memory_consumption'] ?? 0;
        if ($memoryConsumption < 128) {
            $recommendations[] = 'Increase OPcache memory: opcache.memory_consumption=256';
        }

        $maxFiles = $directives['opcache.max_accelerated_files'] ?? 0;
        if ($maxFiles < 4000) {
            $recommendations[] = 'Increase max files: opcache.max_accelerated_files=10000';
        }

        // Production vs Development recommendations
        $isProduction = !\app()->environment(['local', 'development', 'testing']);
        
        if ($isProduction && ($directives['opcache.validate_timestamps'] ?? true)) {
            $recommendations[] = 'Production: Set opcache.validate_timestamps=0';
        }

        if (!$isProduction && !($directives['opcache.validate_timestamps'] ?? false)) {
            $recommendations[] = 'Development: Set opcache.validate_timestamps=1';
        }

        return $recommendations;
    }

    /**
     * Monitor OPcache performance and log warnings if needed
     */
    protected function monitorOpcachePerformance(): void
    {
        if (!config('octane.debug.monitor_opcache', false) || !$this->isOpcacheEnabled()) {
            return;
        }

        $stats = $this->getOpcacheStats();
        $hitRateThreshold = config('octane.debug.opcache_hit_rate_threshold', 95.0);

        // Check hit rate
        if ($stats['hit_rate'] < $hitRateThreshold) {
            \Log::warning('OPcache hit rate below threshold', [
                'hit_rate' => $stats['hit_rate'],
                'threshold' => $hitRateThreshold,
                'stats' => $stats,
            ]);
        }

        // Check memory usage
        if (isset($stats['memory_usage']['current_wasted_percentage'])) {
            $wastedPercentage = $stats['memory_usage']['current_wasted_percentage'];
            
            if ($wastedPercentage > 10) { // More than 10% wasted
                \Log::warning('OPcache memory waste detected', [
                    'wasted_percentage' => $wastedPercentage,
                    'memory_usage' => $stats['memory_usage'],
                ]);
            }
        }
    }

    /**
     * Register Octane hooks for all supported servers
     */
    public static function registerOctaneHooks(): void
    {
        // Swoole
        if (class_exists('\Laravel\Octane\Swoole\SwooleExtension')) {
            \app('events')->listen([
                \Laravel\Octane\Events\RequestTerminated::class,
            ], static::class);
        }

        // FrankenPHP 
        if (class_exists('\Laravel\Octane\FrankenPHP\FrankenPhpExtension')) {
            \app('events')->listen([
                \Laravel\Octane\Events\RequestTerminated::class,
            ], static::class);
        }

        // RoadRunner
        if (class_exists('\Laravel\Octane\RoadRunner\RoadRunnerExtension')) {
            \app('events')->listen([
                \Laravel\Octane\Events\RequestTerminated::class,
                \Laravel\Octane\Events\TaskTerminated::class,
                \Laravel\Octane\Events\TickTerminated::class,
            ], static::class);
        }
    }
}
