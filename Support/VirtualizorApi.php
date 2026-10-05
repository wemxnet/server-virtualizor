<?php

namespace Extensions\Servers\Virtualizor\Support;

use App\Models\ServerConnection;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class VirtualizorApi
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        protected array $credentials,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function make(array $credentials): self
    {
        return new self($credentials);
    }

    public static function fromConnection(ServerConnection $connection): self
    {
        return new self($connection->config ?? []);
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function plans(): array
    {
        return (array) ($this->get('plans')['plans'] ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function plan(int|string $planId): ?array
    {
        foreach ($this->plans() as $key => $plan) {
            if ((string) ($plan['plid'] ?? $key) === (string) $planId) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Operating system templates as [osid => name], optionally limited to one virtualization type.
     *
     * @return array<string, string>
     */
    public function operatingSystems(?string $virt = null): array
    {
        $list = (array) ($this->get('os')['oslist'] ?? []);
        $templates = [];

        foreach ($list as $type => $distros) {
            if ($virt && (string) $type !== $virt) {
                continue;
            }

            foreach ((array) $distros as $distro) {
                foreach ((array) $distro as $osId => $os) {
                    $templates[(string) $osId] = is_array($os) ? (string) ($os['name'] ?? $osId) : (string) $os;
                }
            }
        }

        return $templates;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByEmail(string $email): ?array
    {
        $users = (array) ($this->get('users', ['email' => $email])['users'] ?? []);

        foreach ($users as $user) {
            if (strcasecmp((string) ($user['email'] ?? ''), $email) === 0) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function addUser(array $data): array
    {
        return $this->post('adduser', array_merge(['adduser' => 1], $data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createServer(array $data): array
    {
        return $this->post('addvs', array_merge(['addvps' => 1], $data));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function server(int|string $vpsId): ?array
    {
        $servers = (array) ($this->get('vs', ['vpsid' => $vpsId])['vs'] ?? []);

        return $servers[(string) $vpsId] ?? (array_values($servers)[0] ?? null);
    }

    public function suspend(int|string $vpsId): void
    {
        $this->get('vs', ['suspend' => $vpsId]);
    }

    public function unsuspend(int|string $vpsId): void
    {
        $this->get('vs', ['unsuspend' => $vpsId]);
    }

    public function delete(int|string $vpsId): void
    {
        $this->post('vs', ['delete' => $vpsId]);
    }

    public function power(int|string $vpsId, string $action): void
    {
        if (! in_array($action, ['start', 'stop', 'restart', 'poweroff'], true)) {
            throw new Exception('Unsupported power action.');
        }

        $this->get('vs', ['action' => $action, 'vpsid' => $vpsId]);
    }

    public function changePlan(int|string $vpsId, int|string $planId): void
    {
        $this->post('managevps', [
            'vpsid' => $vpsId,
            'plid' => $planId,
            'editvps' => 1,
        ]);
    }

    public function changeRootPassword(int|string $vpsId, string $password): void
    {
        $this->post('managevps', [
            'vpsid' => $vpsId,
            'rootpass' => $password,
            'editvps' => 1,
        ]);
    }

    /**
     * One-time login URL for the end-user panel of a server.
     */
    public function loginUrl(int|string $vpsId): string
    {
        $response = $this->get('sso', ['svs' => $vpsId], clientPanel: true);

        if (empty($response['sid']) || empty($response['token_key'])) {
            throw new Exception('Virtualizor did not return a login session.');
        }

        return $this->baseUrl(clientPanel: true).'/'.$response['token_key'].'/?as='.$response['sid'].'&svs='.$vpsId;
    }

    public function baseUrl(bool $clientPanel = false): string
    {
        $hostname = rtrim((string) ($this->credentials['hostname'] ?? ''), '/');

        if ($hostname === '') {
            throw new Exception('Virtualizor hostname is not configured.');
        }

        if (! preg_match('#^https?://#', $hostname)) {
            $hostname = 'https://'.$hostname;
        }

        $port = $clientPanel
            ? ($this->credentials['client_port'] ?? 4083)
            : ($this->credentials['port'] ?? 4085);

        return $hostname.':'.$port;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function get(string $act, array $query = [], bool $clientPanel = false): array
    {
        return $this->send('get', $act, $query, $clientPanel);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function post(string $act, array $data = []): array
    {
        return $this->send('post', $act, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function send(string $method, string $act, array $data = [], bool $clientPanel = false): array
    {
        $query = [
            'act' => $act,
            'api' => 'json',
            'adminapikey' => (string) ($this->credentials['api_key'] ?? ''),
            'adminapipass' => (string) ($this->credentials['api_password'] ?? ''),
        ];

        if ($method === 'get') {
            $query = array_merge($query, $data);
        }

        $url = $this->baseUrl($clientPanel).'/index.php?'.http_build_query($query);

        try {
            $response = $method === 'get'
                ? $this->client()->get($url)
                : $this->client()->asForm()->post($url, $data);
        } catch (ConnectionException $exception) {
            throw new Exception($this->friendlyError($act, 'Could not connect to Virtualizor: '.$exception->getMessage()));
        }

        if ($response->failed()) {
            throw new Exception($this->friendlyError($act, "Virtualizor returned HTTP {$response->status()}."));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new Exception($this->friendlyError($act, 'Virtualizor returned an invalid response. Check the hostname, ports, and API credentials.'));
        }

        if (! empty($body['error'])) {
            $errors = is_array($body['error']) ? implode(', ', array_map('strval', $body['error'])) : (string) $body['error'];

            throw new Exception($this->friendlyError($act, $errors));
        }

        return $body;
    }

    protected function client(): PendingRequest
    {
        $client = Http::acceptJson()->timeout(60);

        if ((string) ($this->credentials['verify_ssl'] ?? '0') !== '1') {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    protected function friendlyError(string $act, string $message): string
    {
        if ((string) ($this->credentials['debug_mode'] ?? '0') === '1') {
            return "[{$act}] {$message}";
        }

        return $message;
    }
}
