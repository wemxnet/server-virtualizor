@if(isset($order) && \Extensions\Servers\Virtualizor\Server::usesVirtualizor($order))
    @livewire('admin_area.default.orders.livewire.virtualizor-server-sidebar', ['order_id' => $order->id])
@endif
