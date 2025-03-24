<?php

namespace App\Http\Controllers;

use Exception;
use Midtrans\Snap;
use App\Models\Cart;
use Midtrans\Config;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\CheckoutRequest;
use App\Models\TransactionItem;

class FrontendController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with(['galleries'])->latest()->get();

        return view('pages.frontend.index', compact('products'));
    }

    public function details(Request $request, $slug)
    {
        $product = Product::with(['galleries'])->where('slug', $slug)->firstOrFail();
        $recommendations = Product::with(['galleries'])->inRandomOrder()->limit(4)->get();

        return view('pages.frontend.details', compact('product','recommendations'));
    }

    public function cartAdd(Request $request, $id)
    {
        Cart::create([
            'users_id' => Auth::user()->id,
            'products_id' => $id,
        ]);

        return redirect('cart');
    }

    public function cartDelete(Request $request, $id)
    {
        $item = Cart::findOrFail($id);

        $item->delete();

        return redirect('cart');
    }

    public function cart(Request $request)
    {
        $carts = Cart::with(['product.galleries'])->where('users_id', Auth::user()->id)->get();

        return view('pages.frontend.cart', compact('carts'));
    }

    public function checkout(CheckoutRequest $request)
    {
        $data = $request->all();

        // Get Carts data
        $carts = Cart::with(['product'])->where('users_id', Auth::user()->id)->get();

        // Add to Transaction data
        $data['users_id'] = Auth::user()->id;
        $data['total_price'] = $carts->sum('product.price');
    
        // Create Transaction
        $transaction = Transaction::create($data);

        // Create Transaction item
        foreach($carts as $cart) {
            $items[] = TransactionItem::create([
                'transactions_id' => $transaction->id,
                'users_id' => $cart->users_id,
                'products_id' => $cart->products_id,
            ]);
        }
        
        // Delete cart after transaction
        Cart::where('users_id', Auth::user()->id)->delete();

        // Konfigurasi midtrans
        Config::$serverKey = config('services.midtrans.serverKey');
        Config::$isProduction = config('services.midtrans.isProduction');
        Config::$isSanitized = config('services.midtrans.isSanitized');
        Config::$is3ds = config('services.midtrans.is3ds');

        // Set up Midtrans payment data
    $midtransParams = [
        'transaction_details' => [
            'order_id' => 'LX-' . $transaction->id,
            'gross_amount' => (int) $transaction->total_price,
        ],
        'customer_details' => [
            'first_name' => $transaction->name,
            'email' => $transaction->email,
        ],
        'enabled_payments' => ['gopay', 'bank_transfer'],
        'callbacks' => [
            'finish' => route('checkout-success'),
        ],
        'vtweb' => [],
    ];

    try {
        // Create transaction and get payment URL
        $paymentUrl = Snap::createTransaction($midtransParams)->redirect_url;

        // Save payment URL to transaction
        $transaction->payment_url = $paymentUrl;
        $transaction->save();

        // Redirect to payment page
        return redirect($paymentUrl);
    } catch (Exception $e) {
        return redirect()->back()->with('error', 'Midtrans Error: ' . $e->getMessage());
    }
    }
    // CALLBACK UNTUK MIDTRANS
    public function callback(Request $request)
    {
        $notif = new Notification();

        $transactionStatus = $notif->transaction_status;
        $paymentType = $notif->payment_type;
        $fraudStatus = $notif->fraud_status;
        $orderId = $notif->order_id;

        $transaction_id = str_replace('LX-', '', $orderId);
        $transaction = Transaction::find($transaction_id);

        if (!$transaction) {
            return response()->json(['error' => 'Transaction not found.'], 404);
        }

        if ($transactionStatus == 'capture') {
            if ($paymentType == 'credit_card') {
                $transaction->status = ($fraudStatus == 'challenge') ? 'pending' : 'success';
            }
        } elseif ($transactionStatus == 'settlement') {
            $transaction->status = 'success';
        } elseif ($transactionStatus == 'pending') {
            $transaction->status = 'pending';
        } elseif (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
            $transaction->status = 'failed';
        }

        $transaction->save();

        return response()->json(['message' => 'Transaction status updated']);
    }
    

    public function success(Request $request)
    {
        return view('pages.frontend.success');
    }
}
