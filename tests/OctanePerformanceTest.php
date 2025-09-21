<?php

declare(strict_types=1);

namespace OzerOzay\OctaneTenancy\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use OzerOzay\OctaneTenancy\Tests\Etc\Tenant;
use OzerOzay\OctaneTenancy\Octane\OctaneCompatibilityManager;
use Laravel\Octane\Events\RequestTerminated;

class OctanePerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['LARAVEL_OCTANE'] = '1';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['LARAVEL_OCTANE']);
        parent::tearDown();
    }

    /** @test */
    public function it_measures_tenant_initialization_performance(): void
    {
        $tenant = Tenant::create();
        
        $iterations = 100;
        $times = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            
            tenancy()->initialize($tenant);
            tenancy()->end();
            
            $times[] = microtime(true) - $start;
        }
        
        $averageTime = array_sum($times) / count($times);
        $maxTime = max($times);
        
        // Assert performance benchmarks
        expect($averageTime)->toBeLessThan(0.01); // Less than 10ms average
        expect($maxTime)->toBeLessThan(0.05);     // Less than 50ms max
        
        echo "\nTenant Initialization Performance:\n";
        echo "Average: " . round($averageTime * 1000, 2) . "ms\n";
        echo "Max: " . round($maxTime * 1000, 2) . "ms\n";
    }

    /** @test */
    public function it_measures_memory_usage_during_tenant_operations(): void
    {
        $initialMemory = memory_get_usage(true);
        
        // Create and initialize multiple tenants
        $tenants = [];
        for ($i = 0; $i < 50; $i++) {
            $tenants[] = Tenant::create(['id' => "tenant_{$i}"]);
        }
        
        $afterCreationMemory = memory_get_usage(true);
        
        // Initialize and end tenancy for each tenant
        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);
            
            // Simulate some tenant operations
            Cache::put('test_key', 'test_value', 60);
            Cache::get('test_key');
            
            tenancy()->end();
        }
        
        $afterOperationsMemory = memory_get_usage(true);
        
        // Run cleanup
        $manager = new OctaneCompatibilityManager(app('Laravel\Octane\RequestContext'));
        $manager->handle(new RequestTerminated(app(), request(), response()));
        
        $afterCleanupMemory = memory_get_usage(true);
        
        echo "\nMemory Usage Analysis:\n";
        echo "Initial: " . round($initialMemory / 1024 / 1024, 2) . "MB\n";
        echo "After Creation: " . round($afterCreationMemory / 1024 / 1024, 2) . "MB\n";
        echo "After Operations: " . round($afterOperationsMemory / 1024 / 1024, 2) . "MB\n";
        echo "After Cleanup: " . round($afterCleanupMemory / 1024 / 1024, 2) . "MB\n";
        
        // Memory should not grow excessively and should cleanup
        $memoryGrowth = $afterOperationsMemory - $initialMemory;
        $memoryCleanup = $afterOperationsMemory - $afterCleanupMemory;
        
        expect($memoryGrowth)->toBeLessThan(50 * 1024 * 1024); // Less than 50MB growth
        expect($memoryCleanup)->toBeGreaterThan(0); // Some cleanup should occur
    }

    /** @test */
    public function it_benchmarks_concurrent_tenant_operations(): void
    {
        $tenants = [];
        for ($i = 0; $i < 10; $i++) {
            $tenants[] = Tenant::create(['id' => "concurrent_tenant_{$i}"]);
        }
        
        $operations = 1000;
        $start = microtime(true);
        
        for ($i = 0; $i < $operations; $i++) {
            $tenant = $tenants[$i % count($tenants)];
            
            tenancy()->run($tenant, function() {
                // Simulate work
                Cache::put('operation_' . uniqid(), random_int(1, 1000), 1);
                usleep(100); // 0.1ms simulated work
            });
        }
        
        $totalTime = microtime(true) - $start;
        $operationsPerSecond = $operations / $totalTime;
        
        echo "\nConcurrent Operations Performance:\n";
        echo "Operations: {$operations}\n";
        echo "Total Time: " . round($totalTime, 2) . "s\n";
        echo "Operations/Second: " . round($operationsPerSecond, 2) . "\n";
        
        // Should handle at least 500 ops/second
        expect($operationsPerSecond)->toBeGreaterThan(500);
    }

    /** @test */
    public function it_measures_cache_performance_across_tenants(): void
    {
        $tenant1 = Tenant::create(['id' => 'cache_tenant_1']);
        $tenant2 = Tenant::create(['id' => 'cache_tenant_2']);
        
        $cacheOperations = 100;
        
        // Measure cache operations for tenant 1
        $start = microtime(true);
        tenancy()->run($tenant1, function() use ($cacheOperations) {
            for ($i = 0; $i < $cacheOperations; $i++) {
                Cache::put("key_{$i}", "value_{$i}", 60);
                Cache::get("key_{$i}");
            }
        });
        $tenant1Time = microtime(true) - $start;
        
        // Measure cache operations for tenant 2
        $start = microtime(true);
        tenancy()->run($tenant2, function() use ($cacheOperations) {
            for ($i = 0; $i < $cacheOperations; $i++) {
                Cache::put("key_{$i}", "value_{$i}", 60);
                Cache::get("key_{$i}");
            }
        });
        $tenant2Time = microtime(true) - $start;
        
        echo "\nCache Performance per Tenant:\n";
        echo "Tenant 1: " . round($tenant1Time * 1000, 2) . "ms\n";
        echo "Tenant 2: " . round($tenant2Time * 1000, 2) . "ms\n";
        
        // Both should be fast and similar
        expect($tenant1Time)->toBeLessThan(1.0); // Less than 1 second
        expect($tenant2Time)->toBeLessThan(1.0);
        expect(abs($tenant1Time - $tenant2Time))->toBeLessThan(0.5); // Similar performance
    }

    /** @test */
    public function it_stress_tests_tenant_switching(): void
    {
        $tenants = [];
        for ($i = 0; $i < 5; $i++) {
            $tenants[] = Tenant::create(['id' => "stress_tenant_{$i}"]);
        }
        
        $switches = 500;
        $start = microtime(true);
        $memoryStart = memory_get_usage(true);
        
        for ($i = 0; $i < $switches; $i++) {
            $tenant = $tenants[$i % count($tenants)];
            
            tenancy()->initialize($tenant);
            
            // Simulate some work
            Cache::put('stress_key', 'stress_value_' . $i, 1);
            
            tenancy()->end();
            
            // Periodic cleanup to simulate Octane behavior
            if ($i % 100 === 0) {
                $manager = new OctaneCompatibilityManager(app('Laravel\Octane\RequestContext'));
                $manager->handle(new RequestTerminated(app(), request(), response()));
            }
        }
        
        $totalTime = microtime(true) - $start;
        $memoryEnd = memory_get_usage(true);
        $memoryGrowth = $memoryEnd - $memoryStart;
        
        echo "\nStress Test Results:\n";
        echo "Tenant Switches: {$switches}\n";
        echo "Total Time: " . round($totalTime, 2) . "s\n";
        echo "Switches/Second: " . round($switches / $totalTime, 2) . "\n";
        echo "Memory Growth: " . round($memoryGrowth / 1024 / 1024, 2) . "MB\n";
        
        // Performance assertions
        expect($switches / $totalTime)->toBeGreaterThan(100); // At least 100 switches/sec
        expect($memoryGrowth)->toBeLessThan(20 * 1024 * 1024); // Less than 20MB growth
    }

    /** @test */
    public function it_benchmarks_bootstrap_performance(): void
    {
        config([
            'tenancy.bootstrappers' => [
                \OzerOzay\OctaneTenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
                \OzerOzay\OctaneTenancy\Bootstrappers\CacheTenancyBootstrapper::class,
            ],
        ]);
        
        $tenant = Tenant::create();
        $iterations = 50;
        $times = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            
            tenancy()->initialize($tenant);
            tenancy()->end();
            
            $times[] = microtime(true) - $start;
        }
        
        $averageTime = array_sum($times) / count($times);
        $maxTime = max($times);
        $minTime = min($times);
        
        echo "\nBootstrapper Performance:\n";
        echo "Average: " . round($averageTime * 1000, 2) . "ms\n";
        echo "Min: " . round($minTime * 1000, 2) . "ms\n";
        echo "Max: " . round($maxTime * 1000, 2) . "ms\n";
        
        // Bootstrapping should be fast
        expect($averageTime)->toBeLessThan(0.02); // Less than 20ms average
        expect($maxTime)->toBeLessThan(0.1);      // Less than 100ms max
    }
}
