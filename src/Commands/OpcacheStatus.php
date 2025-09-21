<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Octane\OctaneCompatibilityManager;

class OpcacheStatus extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'tenancy:opcache-status 
                            {--json : Output as JSON}
                            {--recommendations : Show configuration recommendations}';

    /**
     * The console command description.
     */
    protected $description = 'Check OPcache status and configuration for Octane tenancy';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $manager = app(OctaneCompatibilityManager::class);

        // Check if OPcache is enabled
        if (!$manager->isOpcacheEnabled()) {
            $this->error('❌ OPcache is not enabled!');
            $this->line('');
            $this->warn('To enable OPcache, add these to your php.ini:');
            $this->line('opcache.enable=1');
            $this->line('opcache.enable_cli=1');
            
            return self::FAILURE;
        }

        $this->info('✅ OPcache is enabled');
        $this->line('');

        // Get OPcache statistics
        $stats = $manager->getOpcacheStats();
        
        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        // Display formatted statistics
        $this->displayStats($stats);

        // Show recommendations if requested
        if ($this->option('recommendations')) {
            $this->line('');
            $this->displayRecommendations();
        }

        return self::SUCCESS;
    }

    /**
     * Display formatted OPcache statistics
     */
    protected function displayStats(array $stats): void
    {
        $this->info('📊 OPcache Statistics:');
        
        if (isset($stats['scripts_count'])) {
            $this->line("   Cached Scripts: <comment>{$stats['scripts_count']}</comment>");
        }

        if (isset($stats['hit_rate'])) {
            $color = $stats['hit_rate'] >= 95 ? 'info' : ($stats['hit_rate'] >= 80 ? 'comment' : 'error');
            $this->line("   Hit Rate: <{$color}>{$stats['hit_rate']}%</{$color}>");
        }

        // Memory usage
        if (isset($stats['memory_usage'])) {
            $memory = $stats['memory_usage'];
            $used = $this->formatBytes($memory['used_memory'] ?? 0);
            $free = $this->formatBytes($memory['free_memory'] ?? 0);
            $wasted = $this->formatBytes($memory['wasted_memory'] ?? 0);
            
            $this->line("   Memory Used: <comment>{$used}</comment>");
            $this->line("   Memory Free: <info>{$free}</info>");
            if (($memory['wasted_memory'] ?? 0) > 0) {
                $this->line("   Memory Wasted: <error>{$wasted}</error>");
            }
        }

        // Additional statistics
        if (isset($stats['opcache_statistics'])) {
            $opcacheStats = $stats['opcache_statistics'];
            
            $this->line('');
            $this->info('🔄 Operation Statistics:');
            $this->line("   Hits: <info>" . number_format($opcacheStats['hits'] ?? 0) . "</info>");
            $this->line("   Misses: <comment>" . number_format($opcacheStats['misses'] ?? 0) . "</comment>");
            
            if (isset($opcacheStats['blacklist_misses'])) {
                $this->line("   Blacklist Misses: <comment>" . number_format($opcacheStats['blacklist_misses']) . "</comment>");
            }
        }
    }

    /**
     * Display configuration recommendations
     */
    protected function displayRecommendations(): void
    {
        $recommendations = OctaneCompatibilityManager::validateOpcacheConfiguration();
        
        if (empty($recommendations)) {
            $this->info('✅ OPcache configuration looks good for Octane!');
            return;
        }

        $this->warn('⚠️  Configuration Recommendations:');
        $this->line('');
        
        foreach ($recommendations as $recommendation) {
            $this->line("   • {$recommendation}");
        }

        $this->line('');
        $this->info('💡 Add these settings to your php.ini file');
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
}
