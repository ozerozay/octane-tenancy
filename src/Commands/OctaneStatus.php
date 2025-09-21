<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Octane\OctaneCompatibilityManager;

class OctaneStatus extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'tenancy:octane-status 
                            {--detailed : Show detailed server information}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     */
    protected $description = 'Check if Laravel Octane is active and show server information';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $status = $this->getOctaneStatus();

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->displayStatus($status);

        if ($this->option('detailed')) {
            $this->displayDetailedInfo($status);
        }

        return self::SUCCESS;
    }

    /**
     * Get comprehensive Octane status
     */
    protected function getOctaneStatus(): array
    {
        return [
            'octane_active' => $this->isOctaneActive(),
            'octane_server' => $this->getOctaneServer(),
            'environment_variables' => $this->getOctaneEnvironmentVars(),
            'request_method' => $this->getRequestMethod(),
            'process_info' => $this->getProcessInfo(),
            'memory_persistence' => $this->checkMemoryPersistence(),
            'compatibility_manager' => $this->checkCompatibilityManager(),
        ];
    }

    /**
     * Display formatted status
     */
    protected function displayStatus(array $status): void
    {
        if ($status['octane_active']) {
            $server = $status['octane_server'] ?? 'unknown';
            $this->info("✅ Laravel Octane is ACTIVE");
            $this->line("   Server: <comment>{$server}</comment>");
        } else {
            $this->error("❌ Laravel Octane is NOT ACTIVE");
            $this->line("   Running with: <comment>{$status['request_method']}</comment>");
        }

        $this->line('');

        // Show tenancy compatibility status
        if ($status['compatibility_manager']) {
            $this->info("✅ Octane Tenancy Compatibility Manager: LOADED");
        } else {
            $this->warn("⚠️  Octane Tenancy Compatibility Manager: NOT LOADED");
        }

        // Memory persistence check
        if ($status['memory_persistence']['active']) {
            $this->info("✅ Memory Persistence: ACTIVE");
            $this->line("   Uptime: <comment>{$status['memory_persistence']['uptime']}</comment>");
        } else {
            $this->comment("ℹ️  Memory Persistence: NOT DETECTED (normal for PHP-FPM)");
        }
    }

    /**
     * Display detailed information
     */
    protected function displayDetailedInfo(array $status): void
    {
        $this->line('');
        $this->info('📋 Detailed Information:');

        // Environment variables
        $this->line('');
        $this->comment('Environment Variables:');
        foreach ($status['environment_variables'] as $key => $value) {
            $displayValue = $value === null ? '<fg=red>not set</>' : "<info>{$value}</info>";
            $this->line("   {$key}: {$displayValue}");
        }

        // Process information
        if (!empty($status['process_info'])) {
            $this->line('');
            $this->comment('Process Information:');
            foreach ($status['process_info'] as $key => $value) {
                $this->line("   {$key}: <info>{$value}</info>");
            }
        }

        // Memory persistence details
        if ($status['memory_persistence']['active']) {
            $this->line('');
            $this->comment('Memory Persistence Details:');
            foreach ($status['memory_persistence'] as $key => $value) {
                if ($key !== 'active') {
                    $this->line("   {$key}: <info>{$value}</info>");
                }
            }
        }
    }

    /**
     * Check if Octane is currently active
     */
    protected function isOctaneActive(): bool
    {
        return isset($_SERVER['LARAVEL_OCTANE']) && $_SERVER['LARAVEL_OCTANE'] === '1';
    }

    /**
     * Get the active Octane server type
     */
    protected function getOctaneServer(): ?string
    {
        return $_SERVER['OCTANE_SERVER'] ?? 
               $_SERVER['LARAVEL_OCTANE_SERVER'] ?? 
               config('octane.server') ?? 
               null;
    }

    /**
     * Get Octane-related environment variables
     */
    protected function getOctaneEnvironmentVars(): array
    {
        return [
            'LARAVEL_OCTANE' => $_SERVER['LARAVEL_OCTANE'] ?? null,
            'OCTANE_SERVER' => $_SERVER['OCTANE_SERVER'] ?? null,
            'LARAVEL_OCTANE_SERVER' => $_SERVER['LARAVEL_OCTANE_SERVER'] ?? null,
            'OCTANE_WORKERS' => $_SERVER['OCTANE_WORKERS'] ?? null,
            'OCTANE_MAX_REQUESTS' => $_SERVER['OCTANE_MAX_REQUESTS'] ?? null,
            'SERVER_SOFTWARE' => $_SERVER['SERVER_SOFTWARE'] ?? null,
        ];
    }

    /**
     * Determine request method (CLI, FPM, Octane)
     */
    protected function getRequestMethod(): string
    {
        if (php_sapi_name() === 'cli') {
            return 'CLI';
        }

        if ($this->isOctaneActive()) {
            return 'Laravel Octane';
        }

        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            return $_SERVER['SERVER_SOFTWARE'];
        }

        return 'Unknown (probably PHP-FPM)';
    }

    /**
     * Get process information
     */
    protected function getProcessInfo(): array
    {
        $info = [];

        if (function_exists('getmypid')) {
            $info['Process ID'] = getmypid();
        }

        if (function_exists('memory_get_usage')) {
            $info['Memory Usage'] = $this->formatBytes(memory_get_usage(true));
            $info['Peak Memory'] = $this->formatBytes(memory_get_peak_usage(true));
        }

        $info['PHP SAPI'] = php_sapi_name();

        return $info;
    }

    /**
     * Check memory persistence (indicates Octane is working)
     */
    protected function checkMemoryPersistence(): array
    {
        static $startTime;
        static $requestCount = 0;

        if ($startTime === null) {
            $startTime = time();
        }

        $requestCount++;

        $uptime = time() - $startTime;
        
        return [
            'active' => $uptime > 0 && $requestCount > 1,
            'uptime' => $this->formatDuration($uptime),
            'request_count' => $requestCount,
            'start_time' => date('Y-m-d H:i:s', $startTime),
        ];
    }

    /**
     * Check if Octane compatibility manager is loaded
     */
    protected function checkCompatibilityManager(): bool
    {
        return app()->bound(OctaneCompatibilityManager::class) && 
               class_exists('\Laravel\Octane\OctaneServiceProvider');
    }

    /**
     * Format bytes to human readable format
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Format duration to human readable format
     */
    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} seconds";
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return "{$minutes}m {$remainingSeconds}s";
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        return "{$hours}h {$remainingMinutes}m {$remainingSeconds}s";
    }
}
