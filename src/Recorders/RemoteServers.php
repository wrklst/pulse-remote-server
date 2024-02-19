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
        // run every 30 seconds, comapared to every 15 seconds (what is the default for the local Pulse Servers recorder)
        // this is to reduce the amount of ssh commands (4 per run)
        if ($event->time->second % 30 !== 0) {
            return;
        }

        $remote_ssh = $this->config->get('pulse.recorders.' . self::class . '.server_ssh');
        $server = $this->config->get('pulse.recorders.' . self::class . '.server_name');
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
        df --output=used,avail /
        [3]     Used     Avail
        [4] 34218600 473695292
        */

        $memoryTotal = intval($remoteServerStats[0] / 1024);
        $memoryUsed = $memoryTotal - intval($remoteServerStats[1] / 1024);
        $cpu = (int) $remoteServerStats[2];

        $storageDirectories = $this->config->get('pulse.recorders.' . self::class . '.directories');

        if (count($storageDirectories) == 1 && $storageDirectories[0] == "/") {
            $storage = preg_replace('/\s+/', ' ', $remoteServerStats[4]); // replace multi space with single space
            $storage = explode(" ", $storage); // break into segments based on sigle space

            $storageTotal = $storage[0] + $storage[1]; // used and availble
            $storageUsed = $storage[0]; // used

            $storage = [collect([
                'directory' => "/",
                'total' => intval(round($storageTotal / 1024)), // MB
                'used' => intval(round($storageUsed / 1024)), // MB
            ])];
        } else {
            $storage = collect($storageDirectories)
                ->map(function (string $directory) use ($remote_ssh) {
                    $storage = match (PHP_OS_FAMILY) {
                        'Darwin' => (`$remote_ssh 'df $directory'`),
                        'Linux' => (`$remote_ssh 'df $directory'`),
                        default => throw new RuntimeException('The pulse:check command does not currently support ' . PHP_OS_FAMILY),
                    };

                    $storage = explode("\n", $storage); // break in lines
                    $storage = preg_replace('/\s+/', ' ', $storage[1]); // replace multi space with single space
                    $storage = explode(" ", $storage); // break into segments based on sigle space

                    $storageTotal = $storage[2] + $storage[3]; // used and availble
                    $storageUsed = $storage[2]; // used

                    return [
                        'directory' => $directory,
                        'total' => intval(round($storageTotal / 1024)), // MB
                        'used' => intval(round($storageUsed / 1024)), // MB
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
