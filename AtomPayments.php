<?php

namespace App\Http\Helpers\Payment;

use Carbon\Carbon;
use GuzzleHttp\Client;
use App\Http\Helpers\FinancialHelper;

Class AtomPayments {

    public $provider_id = 2;
    private $testMode;

    public function setTestMode($testMode) {
        $this->testMode = $testMode;
    }

    private $testCred = [
        'payment_url' => 'https://caller.atomtech.in/ots/aipay/auth',
        'merchant_id' => '317157',
        'user_id' => '317157',
        'transaction_password' => 'Test@123',
        'product_id' => 'NSE',
        'hash_request_key' => 'KEY123657234',
        'hash_response_key' => 'KEYRESP123657234',
        'aes_request_key' => 'A4476C2062FFA58980DC8F79EB6A799E',
        'aes_request_salt' => 'A4476C2062FFA58980DC8F79EB6A799E',
        'aes_response_key' => '75AEF0FA1B94B3C10D4F5B268F757F11',
        'aes_response_salt' => '75AEF0FA1B94B3C10D4F5B268F757F11'
    ];

    private $prodCred = [
        'payment_url' => 'https://payment1.atomtech.in/ots/aipay/auth',
        'merchant_id' => '317157',
        'user_id' => '317157',
        'transaction_password' => 'Test@123',
        'product_id' => 'NSE',
        'hash_request_key' => 'KEY123657234',
        'hash_response_key' => 'KEYRESP123657234',
        'aes_request_key' => 'A4476C2062FFA58980DC8F79EB6A799E',
        'aes_request_salt' => 'A4476C2062FFA58980DC8F79EB6A799E',
        'aes_response_key' => '75AEF0FA1B94B3C10D4F5B268F757F11',
        'aes_response_salt' => '75AEF0FA1B94B3C10D4F5B268F757F11'
    ];

    private function generateTransaction() {
        return uniqid();
    }

    public function generateToken($paymentData) {

        $creds = ($this->testMode == 1) ? $this->testCred : $this->prodCred;

        $transactionId = $this->generateTransaction();

        $payload = '{
            "payInstrument": {
                "headDetails": {
                    "version": "OTSv1.1",      
                    "api": "AUTH",  
                    "platform": "FLASH"	
                },
                "merchDetails": {
                    "merchId": "'.$creds['merchant_id'].'",
                    "userId": "",
                    "password": "'.$creds['transaction_password'].'",
                    "merchTxnId": "'.$transactionId.'",      
                    "merchTxnDate": "'.date('Y-m-d H:i:s').'"
                },
                "payDetails": {
                    "amount": "'.FinancialHelper::getFormattedAmount($paymentData['amount']).'",
                    "product": "'.$creds['product_id'].'",
                    "custAccNo": "213232323",
                    "txnCurrency": "'.$paymentData['currency']->currency_name.'"
                },	
                "custDetails": {
                    "custEmail": "'.$paymentData['user']->email.'",
                    "custMobile": "'.$paymentData['user']->phone.'"
                },
                "extras": {
                    "udf1": "",  
                    "udf2": "",  
                    "udf3": "", 
                    "udf4": "",  
                    "udf5": "" 
                }
            }  
        }';

        $encData = $this->encryptData($payload, $creds['aes_request_salt'], $creds['aes_request_key']);
         
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $creds['payment_url'].'?encData='.$encData.'&merchId='.$creds['merchant_id'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CAINFO => storage_path('cacert.pem'),
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded'
            ),
        ));
        
        $atomTokenId = null;
        $response = curl_exec($curl);

        if(!empty($response)) {
            $data = parse_str($response, $responseArr);
            if(is_array($responseArr) && isset($responseArr['encData']) && !empty($responseArr['encData'])) {
                $decodedData = $this->decryptData($responseArr['encData'], $creds['aes_response_salt'], $creds['aes_response_key']);
                $decodedArr = json_decode($decodedData, true);
                if(is_array($decodedArr) && isset($decodedArr['atomTokenId']) && !empty($decodedArr['atomTokenId'])) {
                    $atomTokenId = $decodedArr['atomTokenId'];
                    return [
                        'provider_id' => $this->provider_id,
                        'currency_id' => $paymentData['currency']->id,
                        'test_mode' => $this->testMode,
                        'transaction_id' => $transactionId,
                        'amount' => FinancialHelper::getFormattedAmount($paymentData['amount']),
                        'provider_token' => $atomTokenId,
                        'merchant_id' => $creds['merchant_id'],
                        'customer_email' => $paymentData['user']->email,
                        'customer_phone' => $paymentData['user']->phone,
                        'returnUrl' => route('api.user.payment.ipn', ['tid'=>$transactionId])
                    ];
                }
            }
        }

        return $atomTokenId;

    }

    private function encryptData($data, $salt, $key) { 
        $method = "AES-256-CBC";
        $iv = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];
        $chars = array_map("chr", $iv);
        $IVbytes = join($chars);
        $salt1 = mb_convert_encoding($salt, "UTF-8"); //Encoding to UTF-8
        $key1 = mb_convert_encoding($key, "UTF-8"); //Encoding to UTF-8
        $hash = openssl_pbkdf2($key1,$salt1,'256','65536', 'sha512'); 
        $encrypted = openssl_encrypt($data, $method, $hash, OPENSSL_RAW_DATA, $IVbytes);
        return strtoupper(bin2hex($encrypted));
    }  
    
    private function decryptData($data, $salt, $key) {
        $dataEncypted = hex2bin($data);
        $method = "AES-256-CBC";
        $iv = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];
        $chars = array_map("chr", $iv);
        $IVbytes = join($chars);
        $salt1 = mb_convert_encoding($salt, "UTF-8");//Encoding to UTF-8
        $key1 = mb_convert_encoding($key, "UTF-8");//Encoding to UTF-8
        $hash = openssl_pbkdf2($key1,$salt1,'256','65536', 'sha512'); 
        $decrypted = openssl_decrypt($dataEncypted, $method, $hash, OPENSSL_RAW_DATA, $IVbytes);
        return $decrypted;
    }


    public function getResponse($response){
        try {
            $creds = ($this->testMode == 1) ? $this->testCred : $this->prodCred;
            $data = $this->decryptData($response['encData'],$creds['aes_response_salt'],$creds['aes_response_key']);
            $getWayresponseDecode = json_decode($data,true);
            $getWayData = false;

            if($getWayresponseDecode['payInstrument']['responseDetails']['statusCode']=='OTS0000'){

                $getWayData = [
                    'gateway_transaction_id'=>$getWayresponseDecode['payInstrument']['payDetails']['atomTxnId'],
                    'provider_response_data'=>$getWayresponseDecode,
                    'status'=>'completed'
                ];

            }else{
                $getWayData = [
                    'gateway_transaction_id'=>$getWayresponseDecode['payInstrument']['payDetails']['atomTxnId'],
                    'provider_response_data'=>$getWayresponseDecode,
                    'status'=>'failed'
                ];
            }
            return $getWayData;
        } catch(\Exception) {
            return false;
        }
    }
    public function confirmPayment($paydata){
    
        $creds = ($this->testMode == 1) ? $this->testCred : $this->prodCred;
        $payload = '{
            "payInstrument": {
                "headDetails": {
                    "version": "OTSv1.1",      
                    "api": "AUTH",  
                    "platform": "FLASH"	
                },
                "merchDetails": {
                    "merchId": "'.$creds['merchant_id'].'",
                    "userId": "",
                    "password": "'.$creds['transaction_password'].'",
                    "merchTxnId": "'.$paydata['merchanttxnid'].'",      
                    "merchTxnDate": "'.date('Y-m-d H:i:s').'"
                },
                "payDetails": {
                    "amount": "'.FinancialHelper::getFormattedAmount($paydata['amt']).'",
                    "product": "'.$creds['product_id'].'",
                    "custAccNo": "213232323",
                },	
                "custDetails": {
                    "custEmail": "'.$paydata['user']->email.'",
                    "custMobile": "'.$paydata['user']->phone.'"
                },
                "extras": {
                    "udf1": "",  
                    "udf2": "",  
                    "udf3": "", 
                    "udf4": "",  
                    "udf5": "" 
                }
            }  
        }';
       
        $encryptedData = $this->encryptData("merchanttxnid=".$paydata['merchanttxnid']."&amt=100.00&tdate=2022-09-09", $creds['aes_request_salt'], $creds['aes_request_key']);
        $url = "https://payment1.atomtech.in/ots/aipay/auth?login=".$creds['merchant_id']."&merchanttxnid=".$paydata['merchanttxnid']."&amt=100.00&tdate=2022-09-09";
        dd($url);
        // 
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CAINFO => storage_path('cacert.pem'),
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded'
        ),
    ));
    $response = curl_exec($curl);
        return $response;

    }
}