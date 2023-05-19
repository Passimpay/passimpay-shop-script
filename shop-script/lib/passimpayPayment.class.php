<?php

/**
 * @property-read string $merchant_currency
 */

class passimpayPayment extends waPayment
{
    private $settings = null;

    public $url = 'http://www.cbr.ru/scripts/XML_daily.asp';

    protected static $currencies = array(
        'RUB',
        'USD',
    );

    public function allowedCurrency()
    {
        $currency = $this->merchant_currency ? $this->merchant_currency : reset(self::$currencies);
        if (!in_array($currency, self::$currencies)) {
            $currency = reset(self::$currencies);
        }
        return $currency;
    }

    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        $url = 'https://passimpay.io/api/createorder';
        $platform_id = $this->getSettings('platform_id'); // ID платформы
        $apikey = $this->getSettings('apikey'); // Секретный ключ
        $order_id = $order_data['id']; // Payment ID Вашей платформы
        $amount = $order_data['total'];

        if ($order_data['currency'] !== 'USD') {
            $rates = $this->getRates();
            $amount = $amount / $rates['USD'];
        }

        $amount = number_format( round($amount), 2, '.', '' );

        $payload = http_build_query(['platform_id' => $platform_id, 'order_id' => $order_id, 'amount' => $amount]);
        $hash = hash_hmac('sha256', $payload, $apikey);

        $options = [
            'platform_id' => $platform_id,
            'order_id' => $order_id,
            'amount' => $amount,
            'hash' => $hash,
            'app_id' => $this->app_id,
            'merchant_id' => $this->merchant_id
        ];

        $headers = array(
            'Content-Type' => 'application/x-www-form-urlencoded',
        );

        try {

            $net = new waNet($options, $headers);
            $response = $net->query($url, $options, waNet::METHOD_POST);

            $result = json_decode($response, true);

            $transaction_model = new waTransactionModel();

            $wa_transaction_data['plugin'] = $this->id;
            $wa_transaction_data['app_id'] = $this->app_id;
            $wa_transaction_data['merchant_id'] = $this->merchant_id;
            $wa_transaction_data['native_id'] = $this->getSettings('platform_id');
            $wa_transaction_data['type'] = self::OPERATION_CAPTURE;
            $wa_transaction_data['result'] = json_encode($result);
            $wa_transaction_data['order_id'] = $order_id;
            $wa_transaction_data['amount'] = $amount;

            $wa_transaction_data['id'] = $transaction_model->insert($wa_transaction_data);

        } catch (waException $ex) {
            $debug['message'] = $ex->getMessage();
            $debug['exception'] = get_class($ex);
            if (!empty($net)) {
                if (!isset($debug['response'])) {
                    $debug['raw_response'] = $net->getResponse(true);
                }
                $debug['header'] = $net->getResponseHeader();
            }
            self::log($this->id, $debug);
            throw $ex;
        }

        // Варианты ответов
        // В случае успеха
        if (isset($result['result']) && $result['result'] == 1) {
            $url = $result['url'];
        } // В случае ошибки
        else {
            throw new waPaymentException($result['message']);
        }

        self::log($this->id, 'Create Payment Passimpay');

        wa()->getResponse()->redirect($url);

    }

    protected function callbackInit($request)
    {

        $this->order_id = ifset($request, 'order_id', null);
        $this->app_id = ifset($request, 'app_id', null);
        //$this->merchant_id = ifset($request, 'merchant_id', null);

        $transaction_model = new waTransactionModel();

        $search = array(
            'plugin'    => $this->id,
            'order_id'  => $this->order_id
        );
        $wa_transactions = $transaction_model->getByField($search, $transaction_model->getTableId());

        if ($wa_transactions) {
            ksort($wa_transactions, SORT_NUMERIC);
        }

        $app_id = array();
        $merchant_id = array();
        foreach ($wa_transactions as $wa_transaction) {
            if (!empty($wa_transaction['app_id'])) {
                $app_id[] = $wa_transaction['app_id'];
            }
            if (!empty($wa_transaction['merchant_id'])) {
                $merchant_id[] = $wa_transaction['merchant_id'];
            }
        }

        $app_id = array_unique($app_id);
        $merchant_id = array_unique($merchant_id);

        if ((count($merchant_id) === 1) && (count($app_id) === 1)) {
            $this->merchant_id = reset($merchant_id);
            $this->app_id = reset($app_id);
        }

        self::log($this->id, 'callbackInit');

        return parent::callbackInit($request); //обязательный вызов метода базового класса
    }

    protected function callbackHandler($request)
    {
        $transaction_data = $this->formalizeData($request);

        if ( waRequest::get('transaction_result') == 'failure' ){

            return array(
                'redirect' => $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data), //укажите требуемый URL, на который нужно перенаправить покупателя
            );

        }elseif ( waRequest::get('transaction_result') == 'success' ){

            return array(
                'redirect' => $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data), //укажите требуемый URL, на который нужно перенаправить покупателя
            );
        }

        $apikey = $this->getSettings('apikey'); // Секретный ключ

        $hash = $request['hash'];

        $data = [
            'platform_id' => (int) $request['platform_id'], // ID платформы
            'payment_id' => (int) $request['payment_id'], // ID валюты
            'order_id' => (int) $request['order_id'], // Payment ID Вашей платформы
            'amount' => $request['amount'], // сумма транзакции
            'txhash' => $request['txhash'], // Хэш или ID транзакции. ID транзакции можно найти в истории транзакций PassimPay в Вашем аккаунте.
            'address_from' => $request['address_from'], // адрес отправителя
            'address_to' => $request['address_to'], // адрес получателя
            'fee' => $request['fee'], // комиссия сети
        ];

        if (isset($request['confirmations']))
        {
            $data['confirmations'] = $request['confirmations']; // количество подтверждений сети (Bitcoin, Litecoin, Dogecoin, Bitcoin Cash)
        }

        $payload = http_build_query($data);

        if (!isset($hash) || hash_hmac('sha256', $payload, $apikey) != $hash)
        {
            return array(
                'redirect' => $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data),
            );
        }

        // платеж зачислен
        // ваш код...

        $url = 'https://passimpay.io/api/orderstatus';
        $platform_id = $data['platform_id']; // ID платформы
        //$apikey = $apikey; // Секретный ключ
        $order_id = $data['order_id']; // Payment ID Вашей платформы

        $payload = http_build_query(['platform_id' => $platform_id, 'order_id' => $order_id ]);
        $hash = hash_hmac('sha256', $payload, $apikey);

        $data_request = [
            'platform_id' => $platform_id,
            'order_id' => $order_id,
            'hash' => $hash
        ];

        $post_data = http_build_query($data_request);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        $result = curl_exec($curl);
        curl_close( $curl );

        $result = json_decode($result, true);

        // Варианты ответов
        // В случае успеха
        if (isset($result['result']) && $result['result'] == 1)
        {
            $status = $result['status']; // paid, error, wait
            if ($status == 'paid'){

                // 1. Получить информацию о заказе по ID
                $order_model = new shopOrderModel();
                $order = $order_model->getById($data['order_id']);

                // 2. Получить экземпляр класса потока
                // и массив доступных действий для заказа по его статусу
                $workflow = new shopWorkflow();
                $actions = $workflow->getStateById($order['state_id'])->getActions($order);

                $action_id = 'pay';

                if (isset($actions[$action_id])) {
                    $workflow->getActionById($action_id)->run($data['order_id']);
                }
                self::log($this->id, 'execAppCallback');
                $result = $this->execAppCallback(self::CALLBACK_PAYMENT, $transaction_data);

                if (empty($result['result'])) {
                    $message = !empty($result['error']) ? $result['error'] : 'wa transaction error';
                    throw new waPaymentException($message, ifempty($result['code'], 403));
                }

                return array(
                    'redirect' => $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transaction_data), //укажите требуемый URL, на который нужно перенаправить покупателя
                );

            }
        }
        // В случае ошибки
        else
        {
            $error = $result['message']; // Текст ошибки
            self::log($this->id, $error);
            return array(
                'redirect' => $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transaction_data), //укажите требуемый URL, на который нужно перенаправить покупателя
            );
        }

    }

    public function getRates()
    {
        try {
            $obj = simplexml_load_file($this->url);
        } catch (waException $ex) {
            $debug['message'] = $ex->getMessage();
            $debug['exception'] = get_class($ex);
            if (!empty($net)) {
                if (!isset($debug['response'])) {
                    $debug['raw_response'] = $net->getResponse(true);
                }
                $debug['header'] = $net->getResponseHeader();
            }
            self::log($this->id, $debug);
            throw $ex;
        }

        $array = array(
            'date' => (string)$obj->attributes()['Date']
        );
        foreach ($obj->Valute as $key => $currency) {
            $array += array((string)$currency->CharCode => (str_replace(",", ".", (string)$currency->Value) / (string)$currency->Nominal));
        }
        return $array;
    }

}