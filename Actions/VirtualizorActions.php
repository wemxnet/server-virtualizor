<?php

namespace Extensions\Servers\Virtualizor\Actions;

use App\Actions\Action;
use App\Models\Order;
use App\Models\User;
use Extensions\Servers\Virtualizor\Server;
use Extensions\Servers\Virtualizor\Support\VirtualizorManager;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VirtualizorActions extends Action
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function loginAsClient(array $input): string
    {
        $validated = $this->validateOrderInput($input);
        $order = $this->authorizedOrder($validated['order_id'], $validated['user_id'], requireActive: true);

        $this->assertFlag($order, 'allow_login', 'Control panel login is not enabled for this package.');

        return VirtualizorManager::for($order->package->serverConnection)->loginUrl($order);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function loginAsAdmin(array $input): string
    {
        $validated = $this->validateOrderInput($input);
        $order = $this->adminOrder($validated['order_id'], $validated['user_id']);

        return VirtualizorManager::for($order->package->serverConnection)->loginUrl($order);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function powerAsClient(array $input): Order
    {
        $validated = Validator::make($input, [
            'order_id' => ['required', 'exists:orders,id'],
            'user_id' => ['required', 'exists:users,id'],
            'action' => ['required', Rule::in(['start', 'stop', 'restart', 'poweroff'])],
        ])->validate();

        $order = $this->authorizedOrder($validated['order_id'], $validated['user_id'], requireActive: true);

        $this->assertFlag($order, 'allow_power', 'Power controls are not enabled for this package.');

        VirtualizorManager::for($order->package->serverConnection)->power($order, $validated['action']);

        return $order->fresh();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function changeRootPasswordAsClient(array $input): Order
    {
        $validated = Validator::make($input, [
            'order_id' => ['required', 'exists:orders,id'],
            'user_id' => ['required', 'exists:users,id'],
            'password' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9!@#%^*_+=.,-]+$/'],
        ])->validate();

        $order = $this->authorizedOrder($validated['order_id'], $validated['user_id'], requireActive: true);

        $this->assertFlag($order, 'allow_password_change', 'Root password changes are not enabled for this package.');

        VirtualizorManager::for($order->package->serverConnection)->changeRootPassword($order, $validated['password']);

        return $order->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeProvisionedState(Order $order, array $data): void
    {
        $panelPassword = $data['panel_password'] ?? null;
        $orderData = $data;
        unset($orderData['root_password'], $orderData['panel_password']);

        $order->update([
            'external_id' => (string) $data['vpsid'],
            'data' => $orderData,
        ]);

        if (empty($data['panel_uid'])) {
            return;
        }

        $account = $order->getExternalUser();

        if ($account) {
            $account->update([
                'external_id' => (string) $data['panel_uid'],
                'username' => $data['panel_email'],
                'password' => $panelPassword ?? $account->password,
            ]);

            return;
        }

        $order->createExternalUser([
            'external_id' => (string) $data['panel_uid'],
            'username' => $data['panel_email'],
            'password' => $panelPassword ?? 'unknown',
            'data' => ['panel_uid' => $data['panel_uid']],
        ]);
    }

    public function rememberError(Order $order, string $message): void
    {
        $data = $order->data ?? [];
        $data['last_error'] = $message;
        $order->update(['data' => $data]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function validateOrderInput(array $input): array
    {
        return Validator::make($input, [
            'order_id' => ['required', 'exists:orders,id'],
            'user_id' => ['required', 'exists:users,id'],
        ])->validate();
    }

    protected function assertFlag(Order $order, string $flag, string $message): void
    {
        if ((string) $order->option($flag, '1') !== '1') {
            throw ValidationException::withMessages([
                'order_id' => $message,
            ]);
        }
    }

    protected function authorizedOrder(int|string $orderId, int|string $userId, bool $requireActive = false): Order
    {
        $order = Order::query()->with(['package.serverConnection', 'members', 'user'])->find($orderId);
        $user = User::query()->find($userId);

        if (! $order || ! $user || ! Server::usesVirtualizor($order)) {
            throw ValidationException::withMessages([
                'order_id' => 'This order is not provisioned on Virtualizor.',
            ]);
        }

        $isOwner = (int) $order->user_id === (int) $user->id;
        $isMember = $order->members()
            ->where('status', 'active')
            ->where('user_id', $user->id)
            ->exists();

        if (! $isOwner && ! $isMember) {
            throw ValidationException::withMessages([
                'order_id' => 'You do not have access to this order.',
            ]);
        }

        if ($requireActive && $order->status !== 'active') {
            throw ValidationException::withMessages([
                'order_id' => 'This action is only available while the server is active.',
            ]);
        }

        $this->assertProvisioned($order);

        return $order;
    }

    protected function adminOrder(int|string $orderId, int|string $userId): Order
    {
        $order = Order::query()->with(['package.serverConnection', 'user'])->find($orderId);
        $user = User::query()->find($userId);

        if (! $order || ! Server::usesVirtualizor($order)) {
            throw ValidationException::withMessages([
                'order_id' => 'This order is not provisioned on Virtualizor.',
            ]);
        }

        if (! $user || (! $user->isAdmin() && ! $user->hasPermission('admin.orders.view'))) {
            throw ValidationException::withMessages([
                'order_id' => 'You do not have access to this order.',
            ]);
        }

        $this->assertProvisioned($order);

        return $order;
    }

    protected function assertProvisioned(Order $order): void
    {
        if (! $order->external_id && empty($order->data['vpsid'])) {
            throw ValidationException::withMessages([
                'order_id' => 'This server has not finished provisioning yet.',
            ]);
        }
    }
}
