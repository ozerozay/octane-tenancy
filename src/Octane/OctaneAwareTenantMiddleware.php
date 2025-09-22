<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Octane;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

/**
 * Octane-aware tenant initialization middleware
 * Provides memory-safe tenant resolution with proper cleanup
 */
class OctaneAwareTenantMiddleware extends InitializeTenancyByDomain
{
    /**
     * Handle the incoming request with Octane optimizations
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = null;

        try {
            // Resolve tenant with caching if enabled
            $tenant = $this->resolveTenantWithCaching($request);

            if ($tenant) {
                // Initialize tenancy in a memory-safe way
                $this->initializeTenancySafely($tenant);
            }

            $response = $next($request);

        } catch (\Throwable $e) {
            // Ensure cleanup even on exceptions
            $this->cleanupTenancyState($tenant);
            throw $e;
        }

        // Cleanup for next request (if not handled by Octane manager)
        if (!$this->isOctaneCleanupEnabled()) {
            $this->cleanupTenancyState($tenant);
        }

        return $response;
    }

    /**
     * Resolve tenant with optional caching
     */
    protected function resolveTenantWithCaching(Request $request): ?Tenant
    {
        $cacheEnabled = config('tenancy-octane.performance.cache_tenant_resolution', true);
        
        if (!$cacheEnabled) {
            return $this->resolveFromRequest($request);
        }

        $cacheKey = $this->getTenantCacheKey($request);
        
        return cache()->remember($cacheKey, 300, function () use ($request) {
            return $this->resolveFromRequest($request);
        });
    }

    /**
     * Initialize tenancy with memory safety
     */
    protected function initializeTenancySafely(Tenant $tenant): void
    {
        // Ensure any previous tenancy is properly ended
        if (app('tenancy')->initialized) {
            app('tenancy')->end();
        }

        // Initialize new tenancy
        app('tenancy')->initialize($tenant);

        // Log memory usage in debug mode
        if (config('tenancy-octane.debug.enabled', false)) {
            Log::debug('Tenancy initialized', [
                'tenant_id' => $tenant->getTenantKey(),
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
            ]);
        }
    }

    /**
     * Clean up tenancy state to prevent memory leaks
     */
    protected function cleanupTenancyState(?Tenant $tenant): void
    {
        if ($tenant && app('tenancy')->initialized) {
            app('tenancy')->end();
        }

        // Force garbage collection if enabled
        if (config('tenancy-octane.memory_management.force_gc', true)) {
            gc_collect_cycles();
        }
    }

    /**
     * Generate cache key for tenant resolution
     */
    protected function getTenantCacheKey(Request $request): string
    {
        return 'octane_tenant:' . hash('xxh64', $request->getHost() . ':' . $request->getPort());
    }

    /**
     * Check if Octane cleanup is enabled
     */
    protected function isOctaneCleanupEnabled(): bool
    {
        return config('tenancy-octane.memory_management.auto_cleanup', true) 
            && isset($_SERVER['LARAVEL_OCTANE']);
    }

    /**
     * Resolve tenant from request using parent's resolver
     */
    protected function resolveFromRequest(Request $request): ?Tenant
    {
        // Use parent's resolver to get domain and then resolve tenant
        $domain = $this->getDomain($request);
        return $domain ? $this->resolver->resolve($domain) : null;
    }
}
