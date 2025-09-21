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
        'Stancl\Tenancy\Database\DatabaseConfig::$usernameGenerator' => null,
        'Stancl\Tenancy\Database\DatabaseConfig::$passwordGenerator' => null,
        'Stancl\Tenancy\Database\DatabaseConfig::$databaseNameGenerator' => null,
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
    public function sandbox(): mixed
    {
        return null; // Not applicable for our use case
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
    }

    /**
     * Reset all static properties to prevent memory leaks
     */
    protected function resetStaticProperties(): void
    {
        foreach (static::$staticPropertiesToReset as $property => $defaultValue) {
            [$class, $propertyName] = explode('::', $property);
            
            if (class_exists($class) && property_exists($class, ltrim($propertyName, '$'))) {
                $reflection = new \ReflectionClass($class);
                $reflectionProperty = $reflection->getProperty(ltrim($propertyName, '$'));
                $reflectionProperty->setAccessible(true);
                $reflectionProperty->setValue(null, $defaultValue);
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
        // Clear any tenant-related cached data
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        // Force PHP garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
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
