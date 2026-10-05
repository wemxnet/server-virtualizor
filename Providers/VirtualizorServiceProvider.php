<?php

namespace Extensions\Servers\Virtualizor\Providers;

use Illuminate\Support\ServiceProvider;

class VirtualizorServiceProvider extends ServiceProvider
{
    /**
     * Register the server-created email so admins can edit it under Email Templates.
     */
    public function register(): void
    {
        $events = config('email_events', []);

        if (array_key_exists('server.virtualizor.created', $events)) {
            return;
        }

        $events['server.virtualizor.created'] = [
            'name' => 'Virtualizor server created',
            'group' => 'Servers',
            'description' => 'Sent when a Virtualizor virtual server is provisioned for an order.',
            'subject' => 'Your server is ready',
            'body' => <<<'BODY'
Your virtual server has been created and is ready to use.
**Server details:**
Hostname: {{hostname}}
IP address: {{ip}}
Root password: {{root_password}}
**Control panel:**
URL: {{panel_url}}
Email: {{panel_email}}
Password: {{panel_password}}
You can also open the control panel with one click from your order page.
BODY,
            'button_text' => 'Manage server',
            'placeholders' => [
                'hostname' => 'Server hostname',
                'ip' => 'Primary IP address',
                'root_password' => 'Root password',
                'panel_url' => 'Virtualizor end-user panel URL',
                'panel_email' => 'Virtualizor login email',
                'panel_password' => 'Virtualizor password (only for new panel users)',
            ],
        ];

        config(['email_events' => $events]);
    }
}
