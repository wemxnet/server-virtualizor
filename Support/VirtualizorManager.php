<?php

namespace Extensions\Servers\Virtualizor\Support;

use App\Models\Order;
use App\Models\PackagePrice;
use App\Models\ServerConnection;
use Exception;
use Illuminate\Support\Str;

class VirtualizorManager
{
    /**
     * Plan fields copied into the "addvs" request, keyed by Virtualizor's request field.
     *
     * @var array<string, string>
     */
    protected const PLAN_FIELDS = [
        'num_ips' => 'ips',
        'num_ips6' => 'ips6',
        'num_ips6_subnet' => 'ips6_subnet',
        'ram' => 'ram',
        'swapram' => 'swap',
        'space' => 'space',
        'bandwidth' => 'bandwidth',
        'network_speed' => 'network_speed',
        'cpu' => 'cpu',
        'cores' => 'cores',
        'cpu_percent' => 'cpu_percent',
        'vnc' => 'vnc',
        'kvm_cache' => 'kvm_cache',
        'io_mode' => 'io_mode',
        'vnc_keymap' => 'vnc_keymap',
        'nic_type' => 'nic_type',
        'osreinstall_limit' => 'osreinstall_limit',
    ];

    public function __construct(
        protected VirtualizorApi $api,
        protected ServerConnection $connection,
    ) {}

    public static function for(ServerConnection $connection): self
    {
        return new self(VirtualizorApi::fromConnection($connection), $connection);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(Order $order): array
    {
        if ($order->external_id) {
            $data = $order->data ?? [];
            $data['last_error'] = null;

            return $data;
        }

        $planId = (string) $order->package->data('plan_id', '');
        $plan = $planId !== '' ? $this->api->plan($planId) : null;

        if (! $plan) {
            throw new Exception('The Virtualizor plan set on this package was not found.');
        }

        $hostname = strtolower(trim((string) $order->option('hostname', '')));
        $osId = (string) ($order->option('os') ?: $order->package->data('os', ''));

        if ($hostname === '') {
            $hostname = 'vps'.$order->id.'.'.Str::lower(Str::slug(settings('app_name', 'wemx'))).'.local';
        }

        if ($osId === '') {
            throw new Exception('No operating system was chosen for this server.');
        }

        $panelUser = $this->panelUser($order);
        $rootPassword = Str::password(16, symbols: false);

        $payload = [
            'virt' => $plan['virt'] ?? $order->package->data('virt'),
            'node_select' => 1,
            'uid' => $panelUser['uid'],
            'plid' => $plan['plid'] ?? $planId,
            'osid' => $osId,
            'hostname' => $hostname,
            'rootpass' => $rootPassword,
        ];

        foreach (self::PLAN_FIELDS as $requestField => $planField) {
            if (array_key_exists($planField, $plan)) {
                $payload[$requestField] = $plan[$planField];
            }
        }

        $response = $this->api->createServer($payload);
        $server = (array) ($response['newvs'] ?? []);
        $vpsId = (string) ($server['vpsid'] ?? '');

        if ($vpsId === '') {
            throw new Exception('Virtualizor did not return the new server ID.');
        }

        return [
            'vpsid' => $vpsId,
            'hostname' => $hostname,
            'ip' => $this->primaryIp($server),
            'os' => $osId,
            'plan' => $plan['plan_name'] ?? $planId,
            'plan_id' => (string) ($plan['plid'] ?? $planId),
            'virt' => $payload['virt'],
            'root_password' => $rootPassword,
            'panel_uid' => (string) $panelUser['uid'],
            'panel_email' => $panelUser['email'],
            'panel_password' => $panelUser['password'] ?? null,
            'last_error' => null,
        ];
    }

    public function suspend(Order $order): void
    {
        $this->api->suspend($this->vpsId($order));
    }

    public function unsuspend(Order $order): void
    {
        $this->api->unsuspend($this->vpsId($order));
    }

    public function terminate(Order $order): void
    {
        $this->api->delete($this->vpsId($order));
    }

    /**
     * @return array<string, mixed>
     */
    public function upgrade(Order $order, PackagePrice $newPackagePrice): array
    {
        $planId = (string) $newPackagePrice->package->data('plan_id', '');

        if ($planId === '') {
            throw new Exception('The new package has no Virtualizor plan.');
        }

        $this->api->changePlan($this->vpsId($order), $planId);

        $plan = $this->api->plan($planId);
        $data = $order->data ?? [];
        $data['plan_id'] = $planId;
        $data['plan'] = $plan['plan_name'] ?? $planId;
        $data['last_error'] = null;

        return $data;
    }

    public function power(Order $order, string $action): void
    {
        $this->api->power($this->vpsId($order), $action);
    }

    public function changeRootPassword(Order $order, string $password): void
    {
        $this->api->changeRootPassword($this->vpsId($order), $password);
    }

    public function loginUrl(Order $order): string
    {
        return $this->api->loginUrl($this->vpsId($order));
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Order $order): array
    {
        $server = $this->api->server($this->vpsId($order)) ?? [];

        return [
            'hostname' => $server['hostname'] ?? ($order->data['hostname'] ?? null),
            'ip' => $this->primaryIp($server) ?? ($order->data['ip'] ?? null),
            'os' => $server['os_name'] ?? null,
            'ram' => $server['ram'] ?? null,
            'disk' => $server['space'] ?? null,
            'cores' => $server['cores'] ?? null,
            'bandwidth' => $server['bandwidth'] ?? null,
            'suspended' => (string) ($server['suspended'] ?? '0') === '1',
        ];
    }

    public function vpsId(Order $order): string
    {
        $vpsId = (string) ($order->external_id ?: ($order->data['vpsid'] ?? ''));

        if ($vpsId === '') {
            throw new Exception('This server has not finished provisioning yet.');
        }

        return $vpsId;
    }

    /**
     * Find or create the Virtualizor user that owns the customer's servers.
     *
     * @return array{uid: int|string, email: string, password?: string}
     */
    protected function panelUser(Order $order): array
    {
        $email = (string) $order->user->email;
        $existing = $this->api->findUserByEmail($email);

        if ($existing) {
            return ['uid' => $existing['uid'], 'email' => $email];
        }

        $password = Str::password(16, symbols: false);

        $this->api->addUser([
            'priority' => 0,
            'newemail' => $email,
            'newpass' => $password,
            'fname' => (string) ($order->user->first_name ?? ''),
            'lname' => (string) ($order->user->last_name ?? ''),
        ]);

        $created = $this->api->findUserByEmail($email);

        if (! $created) {
            throw new Exception('Virtualizor did not create the customer account.');
        }

        return ['uid' => $created['uid'], 'email' => $email, 'password' => $password];
    }

    /**
     * @param  array<string, mixed>  $server
     */
    protected function primaryIp(array $server): ?string
    {
        $ips = $server['ips'] ?? [];

        if (is_array($ips) && $ips !== []) {
            return (string) array_values($ips)[0];
        }

        return isset($server['ip']) ? (string) $server['ip'] : null;
    }
}
