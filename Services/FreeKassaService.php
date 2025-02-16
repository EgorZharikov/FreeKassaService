<?php

namespace App\Services;

use App\Http\Request;

class FreeKassaApiService
{
    private string $url;
    private string $api_key;
    private string $merchant_id;
    private string $currency;
    private string $secret;

    function __construct()
    {
        $this->url = config('freekassa.api_url');
        $this->api_key = config('freekassa.api_key');
        $this->merchant_id = config('freekassa.merchant_id');
        $this->currency = config('freekassa.currency');
        $this->secret = config('freekassa.freekassa.secret_key');
    }

    /**
     * @param $data
     * @return string
     */
    public function sign($data): string
    {
        ksort($data);
        $sign = hash_hmac('sha256', implode('|', $data), $this->api_key);
        return $sign;
    }

    /**
     * @param array $data
     * @param string $path
     * @return array
     */
    private function sendCurl(array $data, string $path): array
    {
        $request = json_encode($data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url . $path);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FAILONERROR, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
        $result = trim(curl_exec($ch));
        curl_close($ch);

        $response = json_decode($result, true);
        return $response;
    }

    public function getPayUrl(Request $request)
    {
        $order = $this->createOrder($request);
        if ($order['type'] != 'success') {
            return response()->json(['message' => 'Could not create order. Please try again.'], 400);
        }

        response()->json($order, 200);
    }

    // создаем заказ на FreeKassa, возвращаем ссылку на оплату
    private function createOrder(Request $request): array
    {
        $data = $request->validate([
            'paymentId' => 'required',
            'amount' => 'required',
            'email' => 'required',
            'i' => 'required'
        ]);

        $data['shopId'] = $this->merchant_id; // id магазина
        $data['nonce'] = time();
        $data['ip'] = $this->getRealIpAddr(); // IP покупателя
        $data['currency'] = $this->currency; // Валюта оплаты
        $data['signature'] = $this->sign($data); //Подпись запроса
        return $this->sendCurl($data, 'orders/create');
    }


    public function getOrder(Request $request): array
    {
        $data['shopId'] = $this->merchant; // ID магазина
        $data['nonce'] = time(); // Уникальный ID запроса
        $data['paymentId'] = $request['MERCHANT_ORDER_ID']; // Номер заказа в нашем магазине

        $data['signature'] = $this->sign($data); // Подпись запроса
        return $this->sendCurl($data, 'orders');
    }

    private function getRealIpAddr(): string
    {
        if (isset($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        return $_SERVER['REMOTE_ADDR'];
    }

    private function allowIP()
    {
        $ip = $this->getRealIpAddr();

        if (!in_array($ip, array('168.119.157.136', '168.119.60.227',  '178.154.197.79', '51.250.54.238'))) {

            return false;
        } else {

            return true;
        }
    }

    private function getSignature(Request $request)
    {
        $hashStr = $this->merchant_id . ':' . $request['AMOUNT'] . ':' . $this->secret . ':' . $this->currency . ':' . $request['MERCHANT_ORDER_ID'];

        return md5($hashStr);
    }


    private function checkSignature($request, $signature)
    {
        if ($request['SIG'] === $signature) {

            return true;
        } else {

            return false;
        }
    }

    //Примерная логика подтверждения заказа
    private function paidOrder(Request $request)
    {
        $orderFK = $this->getOrder($request); // Получем заказ с fk
        $order = Order::where('id', $request['MERCHANT_ORDER_ID'])->first(); // заказ из нашей бд

        // проверяем сумму и статус оплаты заказа
        if ($orderFK['status'] === 1 && $order['amount'] === $request['AMOUNT']) {
            $wallet = Wallet::where('user_id', $order['user_id'])->first();
            try {
                DB::beginTransaction();

                $order->status = 1;
                $order->save();

                $wallet->balance += $order['amount'];

                DB::commit();

                return 'YES';
            } catch (\Exception $exception) {
                DB::rollBack();
            }
        }
    }

    public function handler(Request $request)
    {
        if (!$this->allowIP()) {
            return 'hacking attempt!';
        }

        $sign = $this->getSignature($request);

        if (!$this->checkSignature($request, $sign)) {
            return 'bad sign';
        }

        $this->paidOrder($request);
    }
}
