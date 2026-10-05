@if(isset($order) && \Extensions\Servers\Virtualizor\Server::usesVirtualizor($order))
    @livewire('client_area.default.orders.livewire.virtualizor-server-panel', ['order_id' => $order->id])
@endif
