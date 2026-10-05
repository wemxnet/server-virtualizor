<?php

use App\Models\Order;
use Extensions\Servers\Virtualizor\Server;
use Extensions\Servers\Virtualizor\Support\VirtualizorManager;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public int $order_id;

    public string $password = '';

    #[Computed]
    public function order(): ?Order
    {
        return Order::query()->with(['package.serverConnection', 'user'])->find($this->order_id);
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function summary(): ?array
    {
        $order = $this->order;

        if (! $order || ! Server::usesVirtualizor($order) || (! $order->external_id && empty($order->data['vpsid']))) {
            return null;
        }

        try {
            return VirtualizorManager::for($order->package->serverConnection)->summary($order);
        } catch (Throwable) {
            return ['error' => true];
        }
    }

    public function refreshPanel(): void
    {
        unset($this->order, $this->summary);
    }

    public function power(string $action): void
    {
        Server::actions()->powerAsClient([
            'order_id' => $this->order_id,
            'user_id' => auth()->id(),
            'action' => $action,
        ]);

        unset($this->summary);
        $this->dispatch('toast', type: 'success', message: __('server-virtualizor::messages.power_sent'), title: 'Success');
    }

    public function changeRootPassword(): void
    {
        Server::actions()->changeRootPasswordAsClient([
            'order_id' => $this->order_id,
            'user_id' => auth()->id(),
            'password' => $this->password,
        ]);

        $this->reset('password');
        $this->dispatch('toast', type: 'success', message: __('server-virtualizor::messages.password_updated'), title: 'Success');
    }
}

?>

<div wire:poll.60s="refreshPanel">
    @php
        $order = $this->order;
        $summary = $this->summary;
        $provisioned = $order && ($order->external_id || !empty($order->data['vpsid']));
        $canManage = $provisioned && $order->status === 'active';
        $account = $order?->getExternalUser();
        $enabled = fn (string $flag) => (string) $order?->option($flag, '1') === '1';
    @endphp

    @if($order)
        <x-theme::card class="mb-4">
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">{{ __('server-virtualizor::messages.server') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $order->data['hostname'] ?? $order->package->name }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if($order->status === 'suspended' || ($summary['suspended'] ?? false))
                        <x-theme::badge.warning :text="__('server-virtualizor::messages.suspended_badge')" />
                    @elseif($summary && empty($summary['error']))
                        <x-theme::badge.success :text="__('server-virtualizor::messages.active')" />
                    @else
                        <x-theme::badge.primary :text="__('server-virtualizor::messages.unknown')" />
                    @endif

                    @if($canManage && $enabled('allow_login'))
                        <x-theme::button.primary :href="route('virtualizor.login', $order)" target="_blank" :text="__('server-virtualizor::messages.login')" />
                    @endif
                </div>
            </div>

            @if($order->status === 'suspended')
                <x-theme::alert.warning class="mb-4" :text="__('server-virtualizor::messages.suspended')" />
            @endif

            @if(! $provisioned)
                <x-theme::alert.primary :text="__('server-virtualizor::messages.not_provisioned')" />
            @elseif(($summary['error'] ?? false) === true)
                <x-theme::alert.warning :text="__('server-virtualizor::messages.unavailable')" />
            @else
                <x-theme::datagrid.grid :cols="3" :gap="4">
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-virtualizor::messages.hostname') }}</x-slot:label>
                        {{ $summary['hostname'] ?? '—' }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-virtualizor::messages.ip') }}</x-slot:label>
                        {{ $summary['ip'] ?? '—' }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-virtualizor::messages.os') }}</x-slot:label>
                        {{ $summary['os'] ?? '—' }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-virtualizor::messages.plan') }}</x-slot:label>
                        {{ $order->data['plan'] ?? '—' }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-virtualizor::messages.ram') }}</x-slot:label>
                        {{ $summary['ram'] ?? '—' }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-virtualizor::messages.disk') }}</x-slot:label>
                        {{ $summary['disk'] ?? '—' }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-virtualizor::messages.cores') }}</x-slot:label>
                        {{ $summary['cores'] ?? '—' }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-virtualizor::messages.bandwidth') }}</x-slot:label>
                        {{ $summary['bandwidth'] ?? '—' }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>{{ __('server-virtualizor::messages.panel_user') }}</x-slot:label>
                        {{ $account->username ?? '—' }}
                    </x-theme::datagrid.item>
                </x-theme::datagrid.grid>

                @if($canManage && $enabled('allow_power'))
                    <div class="mt-5 flex flex-wrap gap-2">
                        <x-theme::button.primary type="button" wire:click="power('start')" :text="__('server-virtualizor::messages.start')" />
                        <x-theme::button.primary type="button" wire:click="power('restart')" :text="__('server-virtualizor::messages.restart')" />
                        <x-theme::button.primary type="button" wire:click="power('stop')" wire:confirm="Stop this server?" :text="__('server-virtualizor::messages.stop')" />
                    </div>
                @endif
            @endif
        </x-theme::card>

        @if($canManage && $enabled('allow_password_change'))
            <x-theme::card class="mb-4">
                <h4 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">{{ __('server-virtualizor::messages.change_root_password') }}</h4>
                <div class="mb-3 max-w-md">
                    <x-theme::form.label for="virtualizor-password" :text="__('server-virtualizor::messages.new_password')" />
                    <x-theme::form.input id="virtualizor-password" type="password" wire:model="password" />
                    @error('password')
                        <x-theme::form.error :text="$message" />
                    @enderror
                </div>
                <x-theme::button.primary type="button" wire:click="changeRootPassword" :text="__('server-virtualizor::messages.save_password')" />
            </x-theme::card>
        @endif
    @endif
</div>
