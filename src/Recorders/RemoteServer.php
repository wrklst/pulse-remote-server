<?php
/*
Requires SSH key authentication in place for authentication to remote server.
Remote Server is assumed to be running Ubuntu Linux.
This is usefull to record server performance for database/cache/queue etc only servers, that do not have pulse installed, which would also require nginx and php etc.
Instead the performance measurement is taken from the app server via ssh remote commands.
*/

namespace WrkLst\Pulse\RemoteServer\Recorders;

use Illuminate\Config\Repository;
use Illuminate\Support\Str;
use Laravel\Pulse\Events\SharedBeat;
use Laravel\Pulse\Pulse;
use RuntimeException;

/**
 * @internal
 */
class RemoteServer
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
        // run every 30 seconds, comapared to than every 15 seconds (what is the default for the Pulse Servers recorder)
        // this is to reduce the amount of ssh commands (4 per run)
        if ($event->time->second % 30 !== 0) {
            return;
        }

        $remote_ssh = $this->config->get('pulse.recorders.' . self::class . '.server_ssh');
        $server = $this->config->get('pulse.recorders.' . self::class . '.server_name');
        $slug = Str::slug($server);
        
        /*
        This needs to be optomized to reduce the amount of ssh commands fired.
        E.g. running all commands with one ssh call with piping a shell script into ssh.
        ´cat server-stats.sh | ssh 1.2.3.4´
        */

        $memoryTotal = match (PHP_OS_FAMILY) {
            'Darwin' => intval(`$remote_ssh 'cat /proc/meminfo' | grep MemTotal | grep -Eo '[0-9]+'` / 1024),
            'Linux' => intval(`$remote_ssh 'cat /proc/meminfo' | grep MemTotal | grep -E -o '[0-9]+'` / 1024),
            default => throw new RuntimeException('The pulse:check command does not currently support ' . PHP_OS_FAMILY),
        };

        $memoryUsed = match (PHP_OS_FAMILY) {
            'Darwin' => $memoryTotal - intval(`$remote_ssh 'cat /proc/meminfo' | grep MemAvailable | grep -Eo '[0-9]+'` / 1024), // MB
            'Linux' => $memoryTotal - intval(`$remote_ssh 'cat /proc/meminfo' | grep MemAvailable | grep -E -o '[0-9]+'` / 1024), // MB
            default => throw new RuntimeException('The pulse:check command does not currently support ' . PHP_OS_FAMILY),
        };

        $cpu = match (PHP_OS_FAMILY) {
            'Darwin' => (int) `$remote_ssh 'top -bn1' | grep -E '^(%Cpu|CPU)' | awk '{ print $2 + $4 }'`,
            'Linux' => (int) `$remote_ssh 'top -bn1' | grep -E '^(%Cpu|CPU)' | awk '{ print $2 + $4 }'`,
            default => throw new RuntimeException('The pulse:check command does not currently support ' . PHP_OS_FAMILY),
        };

        $this->pulse->record('cpu', $slug, $cpu, $event->time)->avg()->onlyBuckets();
        $this->pulse->record('memory', $slug, $memoryUsed, $event->time)->avg()->onlyBuckets();
        $this->pulse->set('system', $slug, json_encode([
            'name' => $server,
            'cpu' => $cpu,
            'memory_used' => $memoryUsed,
            'memory_total' => $memoryTotal,
            'storage' => collect($this->config->get('pulse.recorders.' . self::class . '.directories')) // @phpstan-ignore argument.templateType argument.templateType
                ->map(function (string $directory) use ($remote_ssh) {
                    $storage = match (PHP_OS_FAMILY) {
                        'Darwin' => (`$remote_ssh 'df $directory'`),
                        'Linux' => (`$remote_ssh 'df $directory'`),
                        default => throw new RuntimeException('The pulse:check command does not currently support ' . PHP_OS_FAMILY),
                    };

                    /*
                    Filesystem     1K-blocks     Used Available Use% Mounted on
                    /dev/root      507930276 31400452 476513440   7% /
                    */

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
                ->all(),
        ], flags: JSON_THROW_ON_ERROR), $event->time);
    }
}