<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payment\PayRequest;
use App\Mail\SendOrderedImages;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Payments\Requests\IDPayRequest;
use App\Services\Payments\Requests\IDPayVerifyRequest;
use App\Utilities\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Config;

class PaymentControllerCopy extends Controller
{
    private $IPGConfig;

    public function __construct()
    {
        $this->IPGConfig = Config::get('IPG');
    }

    public function pay(PayRequest $request)
    {
        try {

            $user = $this->setUser($request);

            $orderItem = json_decode(Cookie::get('basket'), true);

            if (count($orderItem) <= 0) {
                throw new \InvalidArgumentException(__('conditions.basket.empty_basket'));
            }

            $products = Product::findMany(array_keys($orderItem));

            $totalPrice = $products->sum('price');

            $ref_code = Str::random(30);

            $createdOrder = $this->setOrder($totalPrice, $user, $ref_code);

            $this->setOrderItems($products, $createdOrder);

            $this->setPayment($createdOrder, $ref_code);

            $idPayRequest = new IDPayRequest([
                'amount'    => $totalPrice,
                'user'      => $user,
                'order_id'  => $ref_code,
                'apiKey'  => config('services.gateways.id_pay.api_key'),
            ]);

            $paymentService = new PaymentService($this->IPGConfig['gateway'], $idPayRequest);
            return $paymentService->pay();
        } catch (\Exception $e) {
            return back()->with('failed', $e->getMessage());
        }
    }

    private function setUser($request)
    {

        $validData = $request->validated();

        $user = User::firstOrCreate([
            'email'   => $validData['email'],
        ], [
            'name'   => $validData['name'],
            'mobile' => $validData['mobile'],
        ]);

        return $user;
    }

    private function  setOrderItems($products, $createdOrder)
    {

        $orderItemForCreateOrder = $products->map(function ($product) {

            $currentProduct = $product->only('price', 'id');
            $currentProduct['product_id'] = $currentProduct['id'];
            unset($currentProduct['id']);

            return $currentProduct;
        });

        $createdOrder->orderItems()->createMany($orderItemForCreateOrder->toArray());
    }


    private function  setOrder($totalPrice, $user, $ref_code)
    {

        $createdOrder = Order::Create([
            'amount' => $totalPrice,
            'user_id' => $user->id,
            'status' => 'unpaid',
            'ref_code' => $ref_code,
        ]);

        return $createdOrder;
    }

    private function  setPayment($createdOrder, $ref_code)
    {

        Payment::create([
            'gateways' => 'idPay',
            'ref_code'   => $ref_code,
            'order_id' => $createdOrder->id,
            'status'   => 'unpaid',
        ]);
    }


    public function callback(Request $request)
    {
        $request = $this->serializeRequest($request);

        $idPayVerifyRequest = new IDPayVerifyRequest([
            'id' => $request['id'],
            'order_id' => $request['order_id'],
        ]);

        $paymentService = new PaymentService(PaymentService::IDPAY, $idPayVerifyRequest);

        $result = $paymentService->verify();

        if (!$result['status']) {
            return redirect()->route('home.checkout.show')->with('failed', __('conditions.basket.failed_payment'));
        }

        $currentPayment = Payment::where('ref_code', $result['data']['order_id'])->first();
        $currentPayment->update([
            'status' => 'paid',
            'res_id' => $result['data']['track_id']
        ]);

        $currentPayment->order()->update([
            'status' => 'paid',
        ]);

        $orderedImages = $currentPayment->order->orderItems->map(function ($orderItem) {
            return ($orderItem->product->source_url);
        });

        $currentUser = $currentPayment->order->user;

        Mail::to($currentUser)->send(new SendOrderedImages($currentUser, $orderedImages->toArray()));

        Cookie::queue('basket', null);
        return redirect()->route('home.page')->with('success', __('conditions.basket.success_payment'));
    }

    /**
     * It takes a request object and returns an array of the request's keys and values, where the keys
     * are in snake case
     * 
     * @param Request The request object
     * 
     * @return The request is being serialized to snake case.
     */
    private function serializeRequest(Request $request)
    {
        $requestKeys = [];
        foreach ($request->all() as $key => $value) {
            $key = Helper::serializeToSnackCase($key);
            $requestKeys[$key] = $value;
        }

        return $requestKeys;
    }
}
