<?php
namespace App\Http\Controllers;

use App\Http\Resources\PaymentGatewayResource;
use App\Models\Provider as PaymentProvider;
use App\Models\WebhookLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentGatewayController extends Controller
{
    public function index()
    {
        $methods = settings("payment_methods", []);
        Log::info("Payment methods from settings: " . json_encode($methods));
        $paymentProviders = PaymentProvider::withCount([
            'transactions as total_transactions',
            'transactions as successful_transactions' => function ($query) {
                $query->where('status', 'successful'); // Make sure this matches your schema status
            },
            'transactions as failed_transactions' => function ($query) {
                $query->where('status', 'failed');
            }
        ])
        ->withSum('transactions as total_volume', 'amount') // Sums the 'amount' column
        ->get();
        return PaymentGatewayResource::collection($paymentProviders);
    }

    public function toggle(Request $request, PaymentProvider $provider)
    {
        $provider->update(['is_active' => !$provider->is_active]);
        return response()->json($provider);
    }

    public function setDefault(PaymentProvider $provider)
    {
        PaymentProvider::query()->update(['is_default' => false]);
        $provider->update(['is_default' => true]);
        return response()->json($provider);
    }

    public function webhookLogs(Request $request)
    {
        $logs = WebhookLog::latest()
            ->when($request->provider, fn($q, $v) => $q->where('provider', $v))
            ->when($request->status,   fn($q, $v) => $q->where('status', $v))
            ->limit(100)
            ->get();
        return response()->json($logs);
    }

    public function retryWebhook(WebhookLog $log)
    {
        // re-dispatch the webhook payload through your WebhookController
        $log->update(['retries' => $log->retries + 1, 'status' => 'pending']);
        // TODO: dispatch job
        return response()->json(['ok' => true]);
    }

    public function testConnection(PaymentProvider $provider)
    {
        // ping the provider's API with a lightweight call
        try {
            $payment = \App\Classes\Payment\PaymentFactory::provider($provider->slug);
            $banks = $payment->listBanks();
            return response()->json(['ok' => true, 'latency_ms' => 0]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'              => 'required|string',
            'slug'              => 'required|string|unique:providers,slug',
            'public_key'        => 'nullable|string',
            'secret_key'        => 'nullable|string',
            'webhook_secret'    => 'nullable|string',
            'mode'              => 'required|in:live,test',
            'is_default'        => 'nullable|boolean', // Added to capture your is_default flag
            'status'            => 'nullable|string',  // Added to capture your status: "active"

            // Matched snake_case with your frontend payload
            'supported_methods' => 'nullable|array',

            // Changed numeric to string because values contain "₦" and "%"
            'fees'              => 'nullable|array',
            'fees.card'         => 'nullable|string',
            'fees.bank'         => 'nullable|string',
            'fees.ussd'         => 'nullable|string', // Added to catch the ussd key sent by frontend
        ]);

        $data = [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'public_key' => $data['public_key'] ?? null,
            'secret_key' => $data['secret_key'] ?? null,
            'webhook_secret' => $data['webhook_secret'] ?? null,
            'mode' => $data['mode'],
            'is_default' => $data['is_default'] ?? false,
            'is_active' => ($data['status'] ?? 'inactive') === 'active',
            'meta' => [
                'supported_methods' => $data['supported_methods'] ?? [],
                'fees' => $data['fees'] ?? [],
            ],
        ];


        $provider = PaymentProvider::create($data);
        return response()->json($provider, 201);
    }
}
