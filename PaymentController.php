<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Helpers\Payment\AtomPayments;
use App\Models\Currency;
use App\Models\PaymentTransaction;
use App\Http\Requests\Payment\GenerateTokenRequest;
use App\Http\Requests\Payment\GetTransacgtionByIdRequest;
use App\Http\Helpers\FinancialHelper;
use App\Models\TransactionHistory;
use Illuminate\Support\Facades\Http;
use App\Models\User;

class PaymentController extends Controller
{

    private $paymentObjArr;
    private $currencyModel;
    private $paymentTransactionModel;
    private $trasactionHistoryModel;

    public function __construct() {
        $this->paymentObjArr = [
            'atom' => new AtomPayments()
        ];
        $this->currencyModel = new Currency();
        $this->paymentTransactionModel = new PaymentTransaction();
        $this->trasactionHistoryModel = new TransactionHistory();
    }
    public function index(Request $request){

        $user = User::find($request->input('uid'));

        if($user) {
            $authToken = $user->createToken('LoginAuth')->accessToken;
            return view('payment/atomPay')->with('authToken', $authToken);
        }
        abort(404);
    }

    public function generatePaymentToken(GenerateTokenRequest $request) {
        $providerId = $request->input('provider_id');
        $amount = $request->input('amount');
        $user = auth()->user();
        $currency = $this->currencyModel->find($request->input('currency_id'));
        $paymentObj = null;
        foreach($this->paymentObjArr as $payObj) {
            if($payObj->provider_id == $providerId) {
                $paymentObj = $payObj;
                break;
            }
        }

        if(!$paymentObj) {
            return response()->json([
                'status'=>false,
                'error'=>'Invalid payment provider!'
            ],422);
        }

        $paymentObj->setTestMode($request->input('test_mode'));

        $token = $paymentObj->generateToken([
            'amount' => $amount,
            'user' => $user,
            'currency' => $currency
        ]);

        if($token && is_array($token)) {
            $paymentTransaction = $this->paymentTransactionModel->create([
                'user_id' => $user->id,
                'provider_id' => $token['provider_id'],
                'currency_id' => $token['currency_id'],
                'test_mode' => (string)$token['test_mode'],
                'transaction_id' => $token['transaction_id'],
                'amount' => $token['amount'],
                'provider_token' => $token['provider_token'],
                'status' => 'pending'
            ]);

            if($paymentTransaction) {
                return response()->json([
                    'status' => true,
                    'message' => "Payment token fetched successfully!",
                    'data' => $token,
                ], 200);
            }
        }

        return response()->json([
            'status'=>false,
            'error'=>'Something went wrong. Please Try Again!'
        ],500);
    }

    public function updatePaymentIpn(Request $request){
        $transactionRec = $this->paymentTransactionModel->where([
            'transaction_id' => $request->input('tid'),
            'status' => 'pending'
        ])->first();

        if($transactionRec) {
            $providerId = $transactionRec->provider_id;
            $requestData = $request->all();
            $paymentObj = null;
            foreach($this->paymentObjArr as $payObj) {
                if($payObj->provider_id == $providerId) {
                    $paymentObj = $payObj;
                    break;
                }
            }

            if(!$paymentObj) {
                abort(404);
            }

            $paymentObj->setTestMode((int)$transactionRec->test_mode);

            $paymentResponse = $paymentObj->getResponse($requestData);

            if($paymentResponse) {
                $transactionRec->provider_transaction_id =$paymentResponse['gateway_transaction_id'];
                $transactionRec->provider_response_data =json_encode($paymentResponse['provider_response_data']);
                $transactionRec->status =$paymentResponse['status'];
                $transactionRec->save();

                if($paymentResponse['status']=='completed'){
                    FinancialHelper::addTransaction(0,$transactionRec->user_id,$transactionRec->user_id,$transactionRec->currency_id,$transactionRec->amount,$transactionRec->transaction_id);
                    return redirect()->route('payment.success');
                }else{
                    return redirect()->route('payment.faild');
                }
            }
        }
        abort(404);
    }

    public function getTransactionById(GetTransacgtionByIdRequest $request){
        $transaction_id = $request->input('transaction_id');
        echo $transaction_id;die;
        $transactionRec = $this->paymentTransactionModel->where([
            'transaction_id' =>$transaction_id,
        ])->first();
        if($transactionRec->status =='completed'){
            $data = $this->trasactionHistoryModel->where('transaction_id',$transaction_id)->first();
            if($data){
                return response()->json([
                    'status' => true,
                    'message' => "Payment transaction fetched successfully!",
                    'data' => $data,
                ], 200);
            }
        }else{
            $transactionRec = $this->paymentTransactionModel->where([
                'transaction_id' =>$transaction_id,
                'status' => 'pending'
            ])->first();
            if($transactionRec) {
                $providerId = $transactionRec->provider_id;
                // $requestData = $request->all();
                // dd($requestData);
                $paymentObj = null;
                foreach($this->paymentObjArr as $payObj) {
                    if($payObj->provider_id == $providerId) {
                        $paymentObj = $payObj;
                        break;
                    }
                }
                if(!$paymentObj) {
                    abort(404);
                }
                $paymentObj->setTestMode((int)$transactionRec->test_mode);
               $requestData = $paymentObj->confirmPayment([
                    'merchanttxnid' => $transaction_id,
                    'amt'=>$transactionRec->amount,
                    'tdate'=>date('Y-m-d'),
                    'user'=>auth()->user()
                ]);
                dd($requestData);
                $paymentResponse = $paymentObj->getResponse($requestData);
                dd($paymentResponse);
    
                if($paymentResponse) {
                    $transactionRec->provider_transaction_id =$paymentResponse['gateway_transaction_id'];
                    $transactionRec->provider_response_data =json_encode($paymentResponse['provider_response_data']);
                    $transactionRec->status =$paymentResponse['status'];
                    $transactionRec->save();
                    if($paymentResponse['status']=='completed'){
                        FinancialHelper::addTransaction(0,$transactionRec->user_id,$transactionRec->user_id,$transactionRec->currency_id,$transactionRec->amount,$transactionRec->transaction_id);
                        $data = $this->trasactionHistoryModel->where('transaction_id',$transaction_id)->first();
                        if($data){
                            return response()->json([
                                'status' => true,
                                'message' => "Payment transaction fetched successfully!",
                                'data' => $data,
                            ], 200);
                        }
                    }
                }
            }
        }
    }
}
