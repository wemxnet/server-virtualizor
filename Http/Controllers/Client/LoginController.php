<?php

namespace Extensions\Servers\Virtualizor\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Extensions\Servers\Virtualizor\Server;
use Illuminate\Http\RedirectResponse;

class LoginController extends Controller
{
    public function __invoke(Order $order): RedirectResponse
    {
        $url = Server::actions()->loginAsClient([
            'order_id' => $order->id,
            'user_id' => auth()->id(),
        ]);

        return redirect()->away($url);
    }
}
