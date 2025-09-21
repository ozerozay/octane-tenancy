<?php

declare(strict_types=1);

namespace OzerOzay\OctaneTenancy\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Octane\Events\RequestTerminated;
use OzerOzay\OctaneTenancy\Octane\OctaneCompatibilityManager;
use OzerOzay\OctaneTenancy\Tests\Etc\Tenant;
use OzerOzay\OctaneTenancy\Tenancy;
use OzerOzay\OctaneTenancy\TenancyServiceProvider;

class OctaneCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock Octane environment
        $_SERVER['LARAVEL_OCTANE'] = '1';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['LARAVEL_OCTANE']);
        parent::tearDown();
    }

    /** @test */
    public function it_resets_static_properties_between_requests(): void
    {
        // Set some static properties
        Tenancy::setFindWith(['domains', 'data']);
        TenancyServiceProvider::$registerForgetTenantParameterListener = false;
        
        expect(Tenancy::getFindWith())->toBe(['domains', 'data']);
        expect(TenancyServiceProvider::$registerForgetTenantParameterListener)->toBeFalse();
        
        // Simulate request termination
        $manager = new OctaneCompatibilityManager(app('Laravel\Octane\RequestContext'));
        $manager->handle(new RequestTerminated(app(), request(), response()));
        
        // Check if static properties are reset
        expect(Tenancy::getFindWith())->toBe([]);
        expect(TenancyServiceProvider::$registerForgetTenantParameterListener)->toBeTrue();
    }

    /** @test */
    public function it_flushes_singletons_between_requests(): void
    {
        // Initialize tenancy
        $tenant = Tenant::create();
        tenancy()->initialize($tenant);
        
        expect(tenancy()->initialized)->toBeTrue();
        expect(app()->bound(Tenancy::class))->toBeTrue();
        
        // Simulate request termination
        $manager = new OctaneCompatibilityManager(app('Laravel\Octane\RequestContext'));
        $manager->handle(new RequestTerminated(app(), request(), response()));
        
        // Check if singleton is flushed and tenancy ended
        expect(tenancy()->initialized)->toBeFalse();
    }

    /** @test */
    public function it_cleans_memory_leaks_between_requests(): void
    {
        $initialMemory = memory_get_usage();
        
        // Create multiple tenants to simulate memory usage
        for ($i = 0; $i < 10; $i++) {
            $tenant = Tenant::create(['id' => "test_tenant_{$i}"]);
            tenancy()->initialize($tenant);
            tenancy()->end();
        }
        
        $beforeCleanupMemory = memory_get_usage();
        expect($beforeCleanupMemory)->toBeGreaterThan($initialMemory);
        
        // Simulate request termination and cleanup
        $manager = new OctaneCompatibilityManager(app('Laravel\Octane\RequestContext'));
        $manager->handle(new RequestTerminated(app(), request(), response()));
        
        // Memory should be cleaned up
        $afterCleanupMemory = memory_get_usage();
        expect($afterCleanupMemory)->toBeLessThan($beforeCleanupMemory);
    }

    /** @test */
    public function it_prevents_tenant_context_bleeding(): void
    {
        $tenant1 = Tenant::create(['id' => 'tenant1']);
        $tenant2 = Tenant::create(['id' => 'tenant2']);
        
        // Initialize first tenant
        tenancy()->initialize($tenant1);
        expect(tenancy()->tenant->getTenantKey())->toBe('tenant1');
        
        // Simulate request end without proper cleanup
        // Then start new request with different tenant
        $manager = new OctaneCompatibilityManager(app('Laravel\Octane\RequestContext'));
        $manager->handle(new RequestTerminated(app(), request(), response()));
        
        // Initialize second tenant - should not have context from first
        tenancy()->initialize($tenant2);
        expect(tenancy()->tenant->getTenantKey())->toBe('tenant2');
        
        // Verify no bleeding occurred
        expect(tenancy()->tenant->getTenantKey())->not->toBe('tenant1');
    }

    /** @test */
    public function it_handles_exceptions_gracefully_with_cleanup(): void
    {
        $tenant = Tenant::create();
        tenancy()->initialize($tenant);
        
        expect(tenancy()->initialized)->toBeTrue();
        
        // Simulate exception during processing
        try {
            $manager = new OctaneCompatibilityManager(app('Laravel\Octane\RequestContext'));
            
            // Force an exception during cleanup
            throw new \Exception('Test exception');
        } catch (\Exception $e) {
            // Cleanup should still happen
            $manager->handle(new RequestTerminated(app(), request(), response()));
        }
        
        // Verify cleanup occurred despite exception
        expect(tenancy()->initialized)->toBeFalse();
    }

    /** @test */
    public function it_optimizes_performance_with_caching(): void
    {
        config(['octane.performance.cache_tenant_resolution' => true]);
        
        $tenant = Tenant::create();
        
        // First resolution should query database
        $start = microtime(true);
        $resolved1 = tenancy()->find($tenant->getTenantKey());
        $time1 = microtime(true) - $start;
        
        // Second resolution should use cache
        $start = microtime(true);
        $resolved2 = tenancy()->find($tenant->getTenantKey());
        $time2 = microtime(true) - $start;
        
        expect($resolved1->getTenantKey())->toBe($resolved2->getTenantKey());
        expect($time2)->toBeLessThan($time1); // Cached should be faster
    }

    /** @test */
    public function it_registers_octane_hooks_correctly(): void
    {
        // Mock Octane service provider existence
        if (!class_exists('Laravel\Octane\OctaneServiceProvider')) {
            $this->markTestSkipped('Laravel Octane not installed');
        }
        
        OctaneCompatibilityManager::registerOctaneHooks();
        
        $events = app('events');
        $listeners = $events->getListeners();
        
        // Check that request termination listeners are registered
        expect($listeners)->toHaveKey('Laravel\Octane\Events\RequestTerminated');
    }

    /** @test */
    public function it_monitors_memory_usage_in_debug_mode(): void
    {
        config(['octane.debug.log_memory_usage' => true]);
        
        // Capture log output
        \Log::shouldReceive('debug')
            ->once()
            ->with('Tenancy initialized', \Mockery::type('array'));
        
        $tenant = Tenant::create();
        tenancy()->initialize($tenant);
    }
}
