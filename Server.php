<?php

namespace Extensions\Servers\Virtualizor;

use App\Extensions\Foundation\ServerExtension;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackagePrice;
use App\Models\ServerConnection;
use Exception;
use Extensions\Servers\Virtualizor\Actions\VirtualizorActions;
use Extensions\Servers\Virtualizor\Providers\VirtualizorServiceProvider;
use Extensions\Servers\Virtualizor\Support\VirtualizorApi;
use Extensions\Servers\Virtualizor\Support\VirtualizorManager;
use Illuminate\Support\Facades\Cache;

class Server extends ServerExtension
{
    protected string $id = 'server-virtualizor';

    protected string $name = 'Virtualizor';

    protected string $description = 'Sell KVM, OpenVZ, Xen and LXC virtual servers from a Virtualizor master, with one-click panel login and power controls.';

    protected string $type = 'Server';

    protected string $icon = 'server';

    protected string $version = '1.0.0';

    protected array $wemxVersions = ['*'];

    protected array $authors = [
        [
            'name' => 'WemX',
            'email' => 'mubeen@wemx.net',
        ],
    ];

    public function providers(): array
    {
        return [
            VirtualizorServiceProvider::class,
        ];
    }

    public function elements(): array
    {
        return [
            [
                'element' => 'client-order-top-view',
                'view' => 'server-virtualizor::client_area.default.orders.widgets.server-panel',
            ],
            [
                'element' => 'admin-order-sidebar-view',
                'view' => 'server-virtualizor::admin_area.default.orders.widgets.server-sidebar',
            ],
        ];
    }

    public function setConfig(): array
    {
        return [
            [
                'key' => 'hostname',
                'name' => 'Hostname',
                'description' => 'Virtualizor master hostname or IP without a port, for example https://virt.example.com',
                'type' => 'text',
                'default_value' => 'https://virt.example.com',
                'rules' => ['required', 'string', 'not_regex:/\/$/'],
            ],
            [
                'key' => 'port',
                'name' => 'Admin port',
                'description' => 'Admin panel port. Default is 4085.',
                'type' => 'number',
                'default_value' => 4085,
                'rules' => ['required', 'numeric', 'min:1', 'max:65535'],
            ],
            [
                'key' => 'client_port',
                'name' => 'End-user port',
                'description' => 'End-user panel port used for one-click login. Default is 4083.',
                'type' => 'number',
                'default_value' => 4083,
                'rules' => ['required', 'numeric', 'min:1', 'max:65535'],
            ],
            [
                'key' => 'api_key',
                'name' => 'API key',
                'description' => 'Admin API key from Configuration → API Credentials.',
                'type' => 'text',
                'rules' => ['required', 'string'],
            ],
            [
                'key' => 'api_password',
                'name' => 'API password',
                'description' => 'Admin API password from Configuration → API Credentials.',
                'type' => 'password',
                'rules' => ['required', 'string'],
            ],
            [
                'key' => 'verify_ssl',
                'name' => 'Verify SSL',
                'description' => 'Virtualizor ships with a self-signed certificate. Enable only if you installed a trusted one.',
                'type' => 'select',
                'options' => [
                    '0' => 'Disabled',
                    '1' => 'Enabled',
                ],
                'default_value' => '0',
                'rules' => ['required', 'in:0,1'],
            ],
            [
                'key' => 'debug_mode',
                'name' => 'Debug mode',
                'description' => 'Include API action names in error messages. Keep disabled in production.',
                'type' => 'select',
                'options' => [
                    '0' => 'Disabled',
                    '1' => 'Enabled',
                ],
                'default_value' => '0',
                'rules' => ['required', 'in:0,1'],
            ],
        ];
    }

    public function setPackageConfig(Package $package, ServerConnection $connection): array
    {
        $planOptions = $this->cachedOptions($connection, 'plans', function (VirtualizorApi $api) {
            return collect($api->plans())
                ->mapWithKeys(fn ($plan, $key) => [(string) ($plan['plid'] ?? $key) => trim(($plan['plan_name'] ?? $key).' ('.strtoupper((string) ($plan['virt'] ?? '')).')')])
                ->all();
        });

        $osOptions = $this->cachedOptions($connection, 'os', fn (VirtualizorApi $api) => $api->operatingSystems());

        return [
            $planOptions === []
                ? $this->field('plan_id', 'Virtualizor plan ID', 'Plan ID from Virtualizor → Plans. The connection is offline, so enter the ID manually.', 'text', ['required', 'string'])
                : $this->field('plan_id', 'Virtualizor plan', 'Plan that sets RAM, disk, CPU, bandwidth and IPs.', 'select', ['required', 'string'], $planOptions, array_key_first($planOptions)),
            $osOptions === []
                ? $this->field('os', 'Default OS template ID', 'Used when the customer does not pick one at checkout.', 'text', ['nullable', 'string'])
                : $this->field('os', 'Default OS template', 'Used when the customer does not pick one at checkout.', 'select', ['nullable', 'string'], $osOptions, array_key_first($osOptions)),
            $this->toggle('allow_login', 'Allow panel login', 'Let customers open the Virtualizor end-user panel with one click.'),
            $this->toggle('allow_power', 'Allow power controls', 'Let customers start, stop and restart the server.'),
            $this->toggle('allow_password_change', 'Allow root password change', 'Let customers set a new root password.'),
        ];
    }

    public function setCheckoutConfig(Package $package): array
    {
        $osOptions = [];

        if ($package->serverConnection) {
            $osOptions = $this->cachedOptions($package->serverConnection, 'os', fn (VirtualizorApi $api) => $api->operatingSystems());
        }

        $fields = [
            [
                'key' => 'hostname',
                'name' => 'Hostname',
                'description' => 'Server hostname, for example server1.example.com',
                'type' => 'text',
                'rules' => ['required', 'string', 'max:191', 'regex:/^(?=.{1,253}$)(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+$/'],
                'is_configurable' => true,
            ],
        ];

        if ($osOptions !== []) {
            $fields[] = [
                'key' => 'os',
                'name' => 'Operating system',
                'description' => 'Template installed on the server.',
                'type' => 'select',
                'options' => $osOptions,
                'default_value' => $package->data('os') ?: array_key_first($osOptions),
                'rules' => ['required', 'string', 'in:'.implode(',', array_keys($osOptions))],
                'is_configurable' => true,
            ];
        }

        return $fields;
    }

    public static function testConnection(array $credentials): string
    {
        $plans = VirtualizorApi::make($credentials)->plans();

        return 'Connected to Virtualizor. '.count($plans).' plans found.';
    }

    public function create(Order $order, ServerConnection $connection): void
    {
        $data = [];

        $this->withErrorTracking($order, function () use ($order, $connection, &$data) {
            $data = VirtualizorManager::for($connection)->create($order);
            self::actions()->storeProvisionedState($order, $data);
        });

        if (empty($data['root_password'])) {
            return;
        }

        $order->refresh();
        $api = VirtualizorApi::fromConnection($connection);

        $order->user->email([
            'identifier' => 'server.virtualizor.created',
            'mailable_type' => Order::class,
            'mailable_id' => $order->id,
            'variables' => [
                'hostname' => $data['hostname'] ?? '',
                'ip' => $data['ip'] ?? '',
                'root_password' => $data['root_password'],
                'panel_url' => $api->baseUrl(clientPanel: true),
                'panel_email' => $data['panel_email'] ?? '',
                'panel_password' => $data['panel_password'] ?? 'Use your existing Virtualizor password or the one-click login on your order page.',
            ],
            'button' => [
                'url' => route('orders.view', $order->id),
            ],
        ]);
    }

    public function suspend(Order $order, ServerConnection $connection): void
    {
        $this->withErrorTracking($order, fn () => VirtualizorManager::for($connection)->suspend($order));
    }

    public function unsuspend(Order $order, ServerConnection $connection): void
    {
        $this->withErrorTracking($order, fn () => VirtualizorManager::for($connection)->unsuspend($order));
    }

    public function terminate(Order $order, ServerConnection $connection): void
    {
        $this->withErrorTracking($order, fn () => VirtualizorManager::for($connection)->terminate($order));
    }

    public function upgradeOrDowngrade(Order $order, PackagePrice $oldPackagePrice, PackagePrice $newPackagePrice, ServerConnection $connection): void
    {
        $this->withErrorTracking($order, function () use ($order, $newPackagePrice, $connection) {
            $order->update(['data' => VirtualizorManager::for($connection)->upgrade($order, $newPackagePrice)]);
        });
    }

    public static function actions(): VirtualizorActions
    {
        return new VirtualizorActions;
    }

    public static function usesVirtualizor(?Order $order): bool
    {
        return $order?->package?->serverConnection?->extension_identifier === 'server-virtualizor';
    }

    protected function withErrorTracking(Order $order, callable $callback): void
    {
        try {
            $callback();
        } catch (Exception $exception) {
            self::actions()->rememberError($order, $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * @param  array<int, mixed>  $rules
     * @param  array<string, string>  $options
     * @return array<string, mixed>
     */
    protected function field(string $key, string $name, string $description, string $type, array $rules, array $options = [], mixed $default = null): array
    {
        return array_filter([
            'key' => $key,
            'name' => $name,
            'col' => 'col-4',
            'description' => $description,
            'type' => $type,
            'options' => $options ?: null,
            'default_value' => $default,
            'rules' => $rules,
            'is_configurable' => false,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function toggle(string $key, string $name, string $description): array
    {
        return $this->field($key, $name, $description, 'select', ['required', 'in:0,1'], ['1' => 'Enabled', '0' => 'Disabled'], '1');
    }

    /**
     * @return array<string, string>
     */
    protected function cachedOptions(ServerConnection $connection, string $type, callable $callback): array
    {
        $connectionId = $connection->id ?? 'new';

        try {
            return Cache::remember("virtualizor:{$type}:{$connectionId}", now()->addHour(), function () use ($connection, $callback) {
                return collect($callback(VirtualizorApi::fromConnection($connection)))
                    ->mapWithKeys(fn ($label, $value) => [(string) $value => (string) $label])
                    ->all();
            });
        } catch (Exception) {
            return [];
        }
    }
}
