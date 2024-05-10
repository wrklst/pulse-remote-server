# Remote Server for Laravel Pulse

Enhance your server stats by adding remote Linux servers to the mix. This feature is designed to incorporate remote servers to Laravel Pulse that do not execute PHP, such as database or cache servers. Servers running PHP are recommended to install their own instance of [Laravel Pulse](https://pulse.laravel.com) instead.

## Installation

Begin by installing the package via Composer:

```shell
composer require wrklst/pulse-remote-server
```

## Authentication

Ensure SSH key authentication is set up for accessing the remote server. The Remote Server package assumes the remote server is running Linux. It is compatible with both Mac and Linux servers for your local Larvel Pulse installation.

## Register the Recorder

In your `pulse.php` configuration file, incorporate the `\WrkLst\Pulse\RemoteServer\Recorders\RemoteServers` class with the desired settings:

```php
return [
    // Other configurations...

    'recorders' => [
        \WrkLst\Pulse\RemoteServer\Recorders\RemoteServers::class => [
            [
                'server_name' => "database-server-1",
                'server_ssh' => "ssh forge@1.2.3.4",
                'query_interval' => 15,
                'directories' => explode(':', env('PULSE_SERVER_DIRECTORIES', '/')),
            ],
            [
                'server_name' => "cache-server-1",
                'server_ssh' => "ssh forge@1.2.3.5",
                'query_interval' => 15,
                'directories' => explode(':', env('PULSE_SERVER_DIRECTORIES', '/')),
            ]
        ],
    ]
]
```

Don't forget to run [the `pulse:check` command](https://laravel.com/docs/10.x/pulse#capturing-entries) to start recording.

## Configuration Notes

- `server_name`: Specify the name of the server as it should appear in the server stats.
- `server_ssh`: Enter the SSH command to connect to the server (`ssh user@ipaddress`). You can also include options like `-p 2222` for non-standard ports.
- `query_interval`: Define the interval for querying the remote server's stats, in seconds.
- `directories`: Specify the directories to check for used and available disk capacity. By default, this is set to "/", but you can add multiple directories or change the directory. Note that altering this configuration might impact query performance. For specialized setups, consider forking the repository and adjusting the shell script accordingly.

And that's all there is to it!