<?php

namespace WrkLst\Pulse\RemoteServer\Recorders;

use Illuminate\Config\Repository;
use Illuminate\Support\Str;
use Laravel\Pulse\Events\SharedBeat;
use Laravel\Pulse\Pulse;
use RuntimeException;

/**
 * @internal
 */
class RemoteServers
{
    /**
     * The events to listen for.
     *
     * @var class-string
     */
    public string $listen = SharedBeat::class;

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected Pulse $pulse,
        protected Repository $config
    ) {
        //
    }

    /**
     * Record the system stats.
     */
    public function record(SharedBeat $event): void
    {
        $servers = $this->config->get('pulse.recorders.' . self::class);
        
        if(isset($servers['server_name'])) {
            $this->recordServer($event, $servers);
        } else {
            foreach ($servers as $serverConfig) {
                $this->recordServer($event, $serverConfig);
            }
        }
    }

    public function recordServer(SharedBeat $event, array $serverConfig)
    {
        $query_interval = (int)($serverConfig['query_interval'] ?? 15);

        if ($event->time->second % $query_interval !== 0) {
            return;
        }

        $remote_ssh = $serverConfig['server_ssh'];
        $server = $serverConfig['server_name'];

        $slug = Str::slug($server);

        $dir = dirname(__FILE__);

        $remoteServerStats = match (PHP_OS_FAMILY) {
            'Darwin' => (`$remote_ssh 'bash -s' < $dir/server-stats-linux.sh`),
            'Linux' => (`$remote_ssh 'bash -s' < $dir/server-stats-linux.sh`),
            default => throw new RuntimeException('The pulse:check command does not currently support ' . PHP_OS_FAMILY),
        };

        $remoteServerStats = explode("\n", $remoteServerStats);

        /*
         cat /proc/meminfo | grep MemTotal | grep -E -o '[0-9]+'
        [0] 19952552
         cat /proc/meminfo | grep MemAvailable | grep -E -o '[0-9]+'
        [1] 5534304
         top -bn1 | grep -E '^(%Cpu|CPU)' | awk '{ print $2 + $4 }'
        [2] 18
        df / | awk 'NR==2 {print $3 "\n" $4 }'
        [3] 34218600
        [4] 473695292
        */

        $memoryTotal = (int)($remoteServerStats[0] / 1024);
        $memoryUsed = $memoryTotal - (int)($remoteServerStats[1] / 1024);
        $cpu = (int) $remoteServerStats[2];

        $storageDirectories = $serverConfig['directories'];

        if (count($storageDirectories) == 1 && $storageDirectories[0] == "/") {
            $storage = [collect([
                'directory' => "/",
                'total' => (round(((int)($remoteServerStats[3]) +  (int)($remoteServerStats[4])) / 1024)), // MB
                'used' => (round((int)($remoteServerStats[3]) / 1024)), // MB
            ])];
        } else {
            $storage = collect($storageDirectories)
                ->map(function (string $directory) use ($remote_ssh, $remoteServerStats) {
                    if ($directory == "/") {
                        $storageTotal = (int)($remoteServerStats[3]) +  (int)($remoteServerStats[4]); // used and availble
                        $storageUsed = (int)($remoteServerStats[3]); // used
                    } else {
                        $storage = match (PHP_OS_FAMILY) {
                            'Darwin' => (`$remote_ssh 'df $directory' | awk 'NR==2 {print $3 "\n" $4 }'`),
                            'Linux' => (`$remote_ssh 'df $directory' | awk 'NR==2 {print $3 "\n" $4 }'`),
                            default => throw new RuntimeException('The pulse:check command does not currently support ' . PHP_OS_FAMILY),
                        };
                        $storage = explode("\n", $storage); // break in lines                    
                        $storageTotal = (int)($storage[0]) + (int)($storage[1]); // used and availble
                        $storageUsed = (int)($storage[0]); // used
                    }

                    return [
                        'directory' => $directory,
                        'total' => (round($storageTotal / 1024)), // MB
                        'used' => (round($storageUsed / 1024)), // MB
                    ];
                })
                ->all();
        }

        $this->pulse->record('cpu', $slug, $cpu, $event->time)->avg()->onlyBuckets();
        $this->pulse->record('memory', $slug, $memoryUsed, $event->time)->avg()->onlyBuckets();
        $this->pulse->set('system', $slug, json_encode([
            'name' => $server,
            'cpu' => $cpu,
            'memory_used' => $memoryUsed,
            'memory_total' => $memoryTotal,
            'storage' => $storage,
        ], flags: JSON_THROW_ON_ERROR), $event->time);
    }
}
