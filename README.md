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
            'directories' => explode(':', env('PULSE_SERVER_DIRECTORIES', '/')),
        ],
    ]
]
```

Ensure you're running [the `pulse:check` command](https://laravel.com/docs/10.x/pulse#capturing-entries).


And that's it! 
