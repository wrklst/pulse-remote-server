# Remote Server for Laravel Pulse

Add remote linux server to your server stats. This is meant for servers that do not run PHP, e.g. database or cache servers. Servers that run PHP should install their own instance of [Laravel Pulse](https://pulse.laravel.com) instead.

## Installation

Install the package using Composer:

```shell
composer require wrklst/pulse-remote-server
```

## Authentication

Requires SSH key authentication in place for authentication to remote server.
Remote Server is assumed to be running Linux. Local server supports Mac and Linux servers.

## Register the recorder

In your `pulse.php` configuration file, register the \WrkLst\Pulse\RemoteServer\Recorders\RemoteServers with the desired settings:

```php
return [
    // ...
    
    'recorders' => [
        \WrkLst\Pulse\RemoteServer\Recorders\RemoteServers::class => [
            'server_name' => "database-server-1",
            'server_ssh' => "ssh forge@1.2.3.4",
            'query_interval' => 15,
            'directories' => explode(':', env('PULSE_SERVER_DIRECTORIES', '/')),
        ],
    ]
]
```

Ensure you're running [the `pulse:check` command](https://laravel.com/docs/10.x/pulse#capturing-entries).


And that's it! 

## Config Notes

`server_name`: name of server how it should be shown in the server stats

`server_ssh`: ssh command to connect to server (ssh user@ipaddress, can also inlcude option -p 2222 for the port if you are not using standard ports etc).

`query_interval`: define the interval of how often the stats should be queries in seconds

`directories`: define the directories checked for disk capacity used and available. We recommend keeping this at "/". adding multiple directories or changing the directory will slow down the query. If you have a special setup, considder forking the repository and adjusting the shell script accordginly.