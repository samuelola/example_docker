Note => chatgpt can make mistake


Steps to Implement Paystack Deposit in Laravel

Install Paystack Laravel package

composer require yansongda/laravel-paystack


Or you can just use raw API calls with Http::post() if you prefer.

Add Paystack credentials to .env

PAYSTACK_SECRET_KEY=sk_test_xxxxxxxxxx
PAYSTACK_PUBLIC_KEY=pk_test_xxxxxxxxxx
PAYSTACK_PAYMENT_URL=https://api.paystack.co


Create a Controller (app/Http/Controllers/PaymentController.php)

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{
    public function initialize(Request $request)
    {
        $response = Http::withToken(env('PAYSTACK_SECRET_KEY'))
            ->post(env('PAYSTACK_PAYMENT_URL') . '/transaction/initialize', [
                'email' => $request->user()->email,
                'amount' => $request->amount * 100, // Paystack works in kobo (NGN cents)
                'callback_url' => route('payment.callback'),
            ]);

        $data = $response->json();

        if ($data['status']) {
            return redirect($data['data']['authorization_url']);
        }

        return back()->with('error', 'Payment initialization failed.');
    }

    public function callback(Request $request)
    {
        $reference = $request->query('reference');

        $response = Http::withToken(env('PAYSTACK_SECRET_KEY'))
            ->get(env('PAYSTACK_PAYMENT_URL') . '/transaction/verify/' . $reference);

        $data = $response->json();

        if ($data['status'] && $data['data']['status'] === 'success') {
            // ✅ Payment verified successfully
            $user = $request->user();
            $user->balance += ($data['data']['amount'] / 100); // convert kobo to NGN
            $user->save();

            return redirect()->route('dashboard')->with('success', 'Deposit successful!');
        }

        return redirect()->route('dashboard')->with('error', 'Payment verification failed.');
    }
}


Define Routes (routes/web.php)

use App\Http\Controllers\PaymentController;

Route::middleware('auth')->group(function () {
    Route::post('/deposit', [PaymentController::class, 'initialize'])->name('payment.initialize');
    Route::get('/payment/callback', [PaymentController::class, 'callback'])->name('payment.callback');
});


Create Deposit Form (Blade View)

<!-- resources/views/deposit.blade.php -->
<form action="{{ route('payment.initialize') }}" method="POST">
    @csrf
    <label for="amount">Enter Amount:</label>
    <input type="number" name="amount" required>
    <button type="submit">Deposit with Paystack</button>
</form>


✅ With this setup:

The user enters an amount.

Laravel calls Paystack’s API to create a transaction.

The user is redirected to Paystack’s payment page.

After payment, Paystack redirects to your callback URL.

The payment is verified, and the user’s balance is updated.

Do you want me to also add a transaction table migration & model so you can log every deposit (with reference, status, amount) for auditing?

You said:
yes
ChatGPT said:

Perfect ✅ — logging transactions is very important for auditing and reconciliation.
Let’s add a Transaction table + Model so every deposit is recorded.

1. Create Migration for Transactions

Run this command:

php artisan make:migration create_transactions_table


Then edit the generated file in database/migrations/xxxx_xx_xx_create_transactions_table.php:

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('reference')->unique();
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('pending'); // pending, success, failed
            $table->string('gateway')->default('paystack');
            $table->json('payload')->nullable(); // store full Paystack response for debugging
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};


Run the migration:

php artisan migrate

2. Create Transaction Model
php artisan make:model Transaction


Edit app/Models/Transaction.php:

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'reference',
        'amount',
        'status',
        'gateway',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

3. Update PaymentController to Log Transactions
<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{
    public function initialize(Request $request)
    {
        $amount = $request->amount;

        $response = Http::withToken(env('PAYSTACK_SECRET_KEY'))
            ->post(env('PAYSTACK_PAYMENT_URL') . '/transaction/initialize', [
                'email' => $request->user()->email,
                'amount' => $amount * 100,
                'callback_url' => route('payment.callback'),
            ]);

        $data = $response->json();

        if ($data['status']) {
            // Save transaction record
            Transaction::create([
                'user_id' => $request->user()->id,
                'reference' => $data['data']['reference'],
                'amount' => $amount,
                'status' => 'pending',
                'gateway' => 'paystack',
                'payload' => $data,
            ]);

            return redirect($data['data']['authorization_url']);
        }

        return back()->with('error', 'Payment initialization failed.');
    }

    public function callback(Request $request)
    {
        $reference = $request->query('reference');

        $response = Http::withToken(env('PAYSTACK_SECRET_KEY'))
            ->get(env('PAYSTACK_PAYMENT_URL') . '/transaction/verify/' . $reference);

        $data = $response->json();

        $transaction = Transaction::where('reference', $reference)->first();

        if ($data['status'] && $data['data']['status'] === 'success') {
            if ($transaction && $transaction->status !== 'success') {
                $transaction->update([
                    'status' => 'success',
                    'payload' => $data,
                ]);

                $user = $transaction->user;
                $user->balance += ($data['data']['amount'] / 100);
                $user->save();
            }

            return redirect()->route('dashboard')->with('success', 'Deposit successful!');
        }

        if ($transaction) {
            $transaction->update([
                'status' => 'failed',
                'payload' => $data,
            ]);
        }

        return redirect()->route('dashboard')->with('error', 'Payment verification failed.');
    }
}

4. Optional: Show Transactions in Dashboard

Example route:

Route::get('/transactions', function () {
    $transactions = auth()->user()->transactions;
    return view('transactions.index', compact('transactions'));
})->middleware('auth');


In User.php model:

public function transactions()
{
    return $this->hasMany(Transaction::class);
}


Now every deposit attempt is logged in the transactions table with status tracking 🚀

Do you want me to also show you how to handle Paystack webhooks (so deposits are verified automatically, even if the user closes the browser after payment)?

You said:
yes
ChatGPT said:

Great choice ✅ — using Paystack webhooks makes your deposit system much more reliable, because sometimes users pay but close their browser before hitting your callback. The webhook will catch those events server-to-server.

Here’s how to set it up:

1. Add a Webhook Route

In routes/web.php:

use App\Http\Controllers\PaymentWebhookController;

Route::post('/paystack/webhook', [PaymentWebhookController::class, 'handle'])->name('paystack.webhook');


⚠️ This route should not be protected by auth middleware. Paystack’s servers need public access.

2. Create a Webhook Controller
php artisan make:controller PaymentWebhookController


Edit app/Http/Controllers/PaymentWebhookController.php:

<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Verify webhook signature
        $paystackSignature = $request->header('x-paystack-signature');
        $computedSignature = hash_hmac('sha512', $request->getContent(), env('PAYSTACK_SECRET_KEY'));

        if ($paystackSignature !== $computedSignature) {
            Log::warning('Paystack webhook signature mismatch.', $request->all());
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event = $request->event;
        $data = $request->data;

        if ($event === 'charge.success') {
            $transaction = Transaction::where('reference', $data['reference'])->first();

            if ($transaction && $transaction->status !== 'success') {
                $transaction->update([
                    'status' => 'success',
                    'payload' => $data,
                ]);

                $user = $transaction->user;
                $user->balance += ($data['amount'] / 100); // convert from kobo
                $user->save();

                Log::info("Transaction {$data['reference']} verified via webhook.");
            }
        }

        return response()->json(['message' => 'Webhook processed'], 200);
    }
}

3. Configure Webhook in Paystack Dashboard

Log into Paystack Dashboard
.

Go to Settings → API Keys & Webhooks.

Add your webhook URL, e.g.:

https://yourdomain.com/paystack/webhook

4. (Optional) Secure Webhook Route

You may want to:

Restrict requests to Paystack’s IP ranges.

Log all webhook payloads for auditing (storage/logs/laravel.log).

5. Testing Webhooks

You can test locally using a tunneling service like ngrok:

ngrok http 8000


Then set the webhook URL in Paystack to your ngrok URL:

https://random-id.ngrok.app/paystack/webhook


✅ With this, even if the user never returns to your app after paying, the webhook will credit their account when Paystack confirms the payment.

Do you also want me to add a queue job so webhook processing doesn’t slow down requests (and can handle spikes in payments)?

You said:
yes
ChatGPT said:

Awesome ✅ Using queues for webhook handling is best practice — keeps requests fast and lets Laravel retry if something fails. Let’s refactor the webhook flow with a job.

1. Create a Job for Processing Webhook Events
php artisan make:job ProcessPaystackWebhook


This will generate app/Jobs/ProcessPaystackWebhook.php.
Edit it like this:

<?php

namespace App\Jobs;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPaystackWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $payload;

    /**
     * Create a new job instance.
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $event = $this->payload['event'] ?? null;
        $data = $this->payload['data'] ?? [];

        if ($event === 'charge.success') {
            $transaction = Transaction::where('reference', $data['reference'])->first();

            if ($transaction && $transaction->status !== 'success') {
                $transaction->update([
                    'status' => 'success',
                    'payload' => $data,
                ]);

                $user = $transaction->user;
                $user->balance += ($data['amount'] / 100); // convert kobo to NGN
                $user->save();

                Log::info("✅ Transaction {$data['reference']} credited via job.");
            }
        }
    }
}

2. Dispatch Job in Webhook Controller

Update PaymentWebhookController:

<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPaystackWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Verify webhook signature
        $paystackSignature = $request->header('x-paystack-signature');
        $computedSignature = hash_hmac('sha512', $request->getContent(), env('PAYSTACK_SECRET_KEY'));

        if ($paystackSignature !== $computedSignature) {
            Log::warning('🚨 Paystack webhook signature mismatch.', $request->all());
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        // Dispatch job to queue
        ProcessPaystackWebhook::dispatch($request->all());

        return response()->json(['message' => 'Webhook queued'], 200);
    }
}

3. Configure Laravel Queue

By default, Laravel uses the sync driver. For production, switch to something like database, Redis, or SQS.

Use database queue (simple option)
php artisan queue:table
php artisan migrate


Set in .env:

QUEUE_CONNECTION=database


Run worker:

php artisan queue:work

4. Benefits

Webhook request returns instantly.

Heavy lifting (balance update, logging, DB writes) happens in background.

If something fails, Laravel retries automatically.

🚀 Now your deposit system is secure, reliable, and scalable:

Callback handles immediate flow.

Webhook ensures reliability.

Job queues make it fast and fault-tolerant.

Do you also want me to add an admin dashboard view where you (or staff) can see all transactions with filters (pending, success, failed)?

You said:
what about withdraw
ChatGPT said:

Great question 👍 — deposits are incoming (handled by Paystack or another gateway), while withdrawals are outgoing (you paying users).
Withdrawals usually have more control and checks, since you don’t want to lose money.

Let’s structure withdrawals in Laravel.

🔹 Withdrawal Flow

User requests withdrawal (fills form with amount + destination account).

System validates (check balance, min/max rules, etc).

Transaction record created with status pending.

Admin approval (optional) — many apps require staff to review requests.

Payout execution:

If using Paystack Transfers API, Laravel can trigger an automated bank transfer.

Otherwise, an admin can mark the withdrawal as processed after sending money manually.

User notified of withdrawal status.

1. Migration for Withdrawals
php artisan make:migration create_withdrawals_table


Edit file:

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->decimal('amount', 12, 2);
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('account_name')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected, processed, failed
            $table->json('payload')->nullable(); // response from Paystack or logs
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};


Run:

php artisan migrate

2. Withdrawal Model

app/Models/Withdrawal.php

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'bank_name',
        'account_number',
        'account_name',
        'status',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

3. Withdrawal Controller

app/Http/Controllers/WithdrawalController.php

<?php

namespace App\Http\Controllers;

use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WithdrawalController extends Controller
{
    public function requestForm()
    {
        return view('withdrawals.request');
    }

    public function submit(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1000', // min withdrawal NGN 1000
            'bank_name' => 'required|string',
            'account_number' => 'required|string',
        ]);

        $user = Auth::user();

        if ($user->balance < $request->amount) {
            return back()->with('error', 'Insufficient balance.');
        }

        DB::transaction(function () use ($request, $user) {
            // Deduct balance immediately (hold funds)
            $user->balance -= $request->amount;
            $user->save();

            // Log withdrawal
            Withdrawal::create([
                'user_id' => $user->id,
                'amount' => $request->amount,
                'bank_name' => $request->bank_name,
                'account_number' => $request->account_number,
                'status' => 'pending',
            ]);
        });

        return redirect()->route('dashboard')->with('success', 'Withdrawal request submitted. Awaiting approval.');
    }
}

4. Routes

routes/web.php

use App\Http\Controllers\WithdrawalController;

Route::middleware('auth')->group(function () {
    Route::get('/withdraw', [WithdrawalController::class, 'requestForm'])->name('withdraw.form');
    Route::post('/withdraw', [WithdrawalController::class, 'submit'])->name('withdraw.submit');
});

5. Simple Form

resources/views/withdrawals/request.blade.php

<form action="{{ route('withdraw.submit') }}" method="POST">
    @csrf
    <label>Amount:</label>
    <input type="number" name="amount" required>

    <label>Bank Name:</label>
    <input type="text" name="bank_name" required>

    <label>Account Number:</label>
    <input type="text" name="account_number" required>

    <button type="submit">Request Withdrawal</button>
</form>

6. (Optional) Automating with Paystack Transfers

If you want withdrawals to be auto-processed via Paystack:

You need to enable Transfers on your Paystack account.

Then you can hit their Transfer API from Laravel. Example:

$response = Http::withToken(env('PAYSTACK_SECRET_KEY'))
    ->post(env('PAYSTACK_PAYMENT_URL').'/transfer', [
        'source' => 'balance',
        'amount' => $withdrawal->amount * 100,
        'recipient' => $recipientCode, // must create a transfer recipient first
        'reason' => 'User withdrawal',
    ]);


This updates withdrawals.status to processed or failed depending on API response.

✅ So now you have:

Deposits (Paystack payments + webhook).

Withdrawals (user requests + optional auto payout).

Do you want me to show you how to integrate Paystack Transfer Recipients (so you can fully automate withdrawals to Nigerian banks)?

You said:
yes
ChatGPT said:

Perfect 🚀 — let’s make withdrawals fully automatic using Paystack Transfers API.
The process has 2 main parts:

Create a Transfer Recipient (one-time per bank account).

Trigger a Transfer (when a withdrawal is approved).

🔹 Step 1: Create Transfer Recipient

Before Paystack can send money, you must register the user’s bank account with them.

Example (inside WithdrawalController or a PaystackService class):
use Illuminate\Support\Facades\Http;

public function createRecipient($name, $accountNumber, $bankCode)
{
    $response = Http::withToken(env('PAYSTACK_SECRET_KEY'))
        ->post(env('PAYSTACK_PAYMENT_URL').'/transferrecipient', [
            'type' => 'nuban',
            'name' => $name,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode, // Example: 058 for GTBank
            'currency' => 'NGN',
        ]);

    $data = $response->json();

    if ($data['status']) {
        return $data['data']['recipient_code']; // Save this in DB
    }

    throw new \Exception('Failed to create transfer recipient: ' . $data['message']);
}


👉 You should save the recipient_code in your withdrawals table (or a separate bank_accounts table) so you don’t re-create it every time.

🔹 Step 2: Trigger a Transfer

Once you have a recipient code, you can initiate the transfer:

public function initiateTransfer($withdrawal, $recipientCode)
{
    $response = Http::withToken(env('PAYSTACK_SECRET_KEY'))
        ->post(env('PAYSTACK_PAYMENT_URL').'/transfer', [
            'source' => 'balance',
            'amount' => $withdrawal->amount * 100, // Paystack uses kobo
            'recipient' => $recipientCode,
            'reason' => 'Withdrawal for user ID '.$withdrawal->user_id,
        ]);

    $data = $response->json();

    if ($data['status']) {
        $withdrawal->update([
            'status' => 'processing',
            'payload' => $data,
        ]);
        return true;
    }

    $withdrawal->update([
        'status' => 'failed',
        'payload' => $data,
    ]);

    return false;
}

🔹 Step 3: Handle Transfer Webhooks

Paystack sends events like transfer.success or transfer.failed.
Update your webhook controller to handle them:

if ($event === 'transfer.success') {
    $reference = $data['reference'];

    $withdrawal = Withdrawal::where('id', $data['metadata']['withdrawal_id'] ?? null)->first();

    if ($withdrawal) {
        $withdrawal->update([
            'status' => 'processed',
            'payload' => $data,
        ]);
    }
}

if ($event === 'transfer.failed') {
    $withdrawal = Withdrawal::where('id', $data['metadata']['withdrawal_id'] ?? null)->first();

    if ($withdrawal) {
        $withdrawal->update([
            'status' => 'failed',
            'payload' => $data,
        ]);

        // Optionally refund user balance
        $withdrawal->user->increment('balance', $withdrawal->amount);
    }
}


⚠️ Best practice: include metadata when creating transfers (e.g., withdrawal_id) so you can link webhook events back to your DB records.

🔹 Step 4: Admin Flow (Optional)

You may want admins to review withdrawals before calling Paystack API:

User submits withdrawal → status = pending.

Admin approves → call createRecipient() (if needed) + initiateTransfer().

This prevents fraud and mistakes.

🔹 Step 5: Bank Codes

Paystack provides a bank list API:

$response = Http::withToken(env('PAYSTACK_SECRET_KEY'))
    ->get(env('PAYSTACK_PAYMENT_URL').'/bank?currency=NGN');

$banks = $response->json()['data'];


Use this to populate a dropdown so users select their bank properly.

✅ With this, withdrawals are now automated:

User requests → stored.

Recipient created once.

Paystack Transfer API moves funds.

Webhook updates status.

Do you want me to package all this into a PaystackService class (so your controllers stay clean and reusable)?

You said:
yes
ChatGPT said:

Nice 🚀 Let’s clean things up by wrapping all Paystack logic into a service class.
This way, your controllers stay lean and reusable.

🔹 Step 1: Create Service Class

Create file: app/Services/PaystackService.php

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PaystackService
{
    protected string $baseUrl;
    protected string $secretKey;

    public function __construct()
    {
        $this->baseUrl = env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co');
        $this->secretKey = env('PAYSTACK_SECRET_KEY');
    }

    /**
     * Initialize Payment (Deposit)
     */
    public function initializePayment(string $email, int $amount, string $callbackUrl)
    {
        return $this->post('/transaction/initialize', [
            'email' => $email,
            'amount' => $amount * 100,
            'callback_url' => $callbackUrl,
        ]);
    }

    /**
     * Verify Payment
     */
    public function verifyPayment(string $reference)
    {
        return $this->get('/transaction/verify/' . $reference);
    }

    /**
     * Get Banks
     */
    public function getBanks(string $currency = 'NGN')
    {
        return $this->get('/bank', ['currency' => $currency]);
    }

    /**
     * Create Transfer Recipient
     */
    public function createRecipient(string $name, string $accountNumber, string $bankCode)
    {
        return $this->post('/transferrecipient', [
            'type' => 'nuban',
            'name' => $name,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'currency' => 'NGN',
        ]);
    }

    /**
     * Initiate Transfer
     */
    public function initiateTransfer(int $amount, string $recipientCode, string $reason, array $metadata = [])
    {
        return $this->post('/transfer', [
            'source' => 'balance',
            'amount' => $amount * 100,
            'recipient' => $recipientCode,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Internal GET
     */
    protected function get(string $endpoint, array $params = [])
    {
        $response = Http::withToken($this->secretKey)->get($this->baseUrl . $endpoint, $params);
        return $response->json();
    }

    /**
     * Internal POST
     */
    protected function post(string $endpoint, array $data)
    {
        $response = Http::withToken($this->secretKey)->post($this->baseUrl . $endpoint, $data);
        return $response->json();
    }
}

🔹 Step 2: Use Service in Controllers
Deposit Example
use App\Services\PaystackService;

public function initialize(Request $request, PaystackService $paystack)
{
    $data = $paystack->initializePayment(
        $request->user()->email,
        $request->amount,
        route('payment.callback')
    );

    if ($data['status']) {
        // Save transaction record
        Transaction::create([
            'user_id' => $request->user()->id,
            'reference' => $data['data']['reference'],
            'amount' => $request->amount,
            'status' => 'pending',
            'gateway' => 'paystack',
            'payload' => $data,
        ]);

        return redirect($data['data']['authorization_url']);
    }

    return back()->with('error', 'Payment initialization failed.');
}

Withdrawal Example
use App\Services\PaystackService;

public function processWithdrawal(Withdrawal $withdrawal, PaystackService $paystack)
{
    // Step 1: Create recipient (or fetch if saved already)
    $recipient = $paystack->createRecipient(
        $withdrawal->account_name ?? $withdrawal->user->name,
        $withdrawal->account_number,
        $withdrawal->bank_code // store this in DB from bank list
    );

    if (!$recipient['status']) {
        $withdrawal->update(['status' => 'failed', 'payload' => $recipient]);
        return back()->with('error', 'Recipient creation failed.');
    }

    $recipientCode = $recipient['data']['recipient_code'];

    // Step 2: Initiate transfer
    $transfer = $paystack->initiateTransfer(
        $withdrawal->amount,
        $recipientCode,
        'Withdrawal for user ID '.$withdrawal->user_id,
        ['withdrawal_id' => $withdrawal->id] // metadata for webhook tracking
    );

    if ($transfer['status']) {
        $withdrawal->update(['status' => 'processing', 'payload' => $transfer]);
        return back()->with('success', 'Withdrawal initiated successfully.');
    }

    $withdrawal->update(['status' => 'failed', 'payload' => $transfer]);
    return back()->with('error', 'Withdrawal failed.');
}

🔹 Step 3: Webhook (Deposit + Withdrawal)

Now your webhook controller only needs to handle events and update DB, since PaystackService handles API calls.

✅ Benefits of PaystackService

Centralizes all Paystack logic.

Easier to test & maintain.

Reusable for both deposits and withdrawals.

Would you like me to also include a full sample admin dashboard page (with transactions + withdrawals + filters) to tie it all together?

You said:
what about transfer 
ChatGPT said:

Got it 👍 — you’re asking about transfers (moving money out of your Paystack balance to a bank account or beneficiary).
This is basically the withdrawal automation we started, but let’s go deeper into the Paystack Transfers API workflow.

🔹 Paystack Transfer Workflow

Get Bank Codes → So the user selects the correct bank.
Endpoint: GET /bank

Create Transfer Recipient → Register the destination account with Paystack (only once per bank account).
Endpoint: POST /transferrecipient

Initiate Transfer → Move money from your Paystack balance to that recipient.
Endpoint: POST /transfer

Handle Transfer Webhooks → Paystack sends transfer.success or transfer.failed events.

🔹 Example in Laravel with PaystackService

We already built PaystackService. Let’s show transfer flow end-to-end.

1. Get Banks
$banks = $paystack->getBanks('NGN');
foreach ($banks['data'] as $bank) {
    echo $bank['name'] . " (" . $bank['code'] . ")";
}


👉 Save bank codes in your DB or show in a dropdown for users.

2. Create Recipient
$recipient = $paystack->createRecipient(
    'John Doe',
    '0123456789',
    '058' // Example: GTBank code
);

if ($recipient['status']) {
    $recipientCode = $recipient['data']['recipient_code'];
}


👉 Store $recipientCode in your Withdrawals table or a separate BankAccounts table.

3. Initiate Transfer
$transfer = $paystack->initiateTransfer(
    5000, // amount in NGN
    $recipientCode,
    'Withdrawal for user #123',
    ['withdrawal_id' => $withdrawal->id] // metadata for webhook tracking
);

if ($transfer['status']) {
    $withdrawal->update([
        'status' => 'processing',
        'payload' => $transfer,
    ]);
}


👉 At this stage, the transfer is queued in Paystack. Status will be updated via webhook.

4. Handle Transfer Webhooks

In your PaymentWebhookController:

if ($event === 'transfer.success') {
    $withdrawal = Withdrawal::find($data['metadata']['withdrawal_id']);
    if ($withdrawal) {
        $withdrawal->update([
            'status' => 'processed',
            'payload' => $data,
        ]);
    }
}

if ($event === 'transfer.failed') {
    $withdrawal = Withdrawal::find($data['metadata']['withdrawal_id']);
    if ($withdrawal) {
        $withdrawal->update([
            'status' => 'failed',
            'payload' => $data,
        ]);

        // refund user balance
        $withdrawal->user->increment('balance', $withdrawal->amount);
    }
}

🔹 Notes About Transfers

Transfers only work if you’ve enabled Transfers in your Paystack dashboard (KYC must be complete).

The account must have enough Paystack balance.

You can use bulk transfers if you want to process multiple withdrawals at once (POST /transfer/bulk).

Always include metadata with your withdrawal ID to easily reconcile webhook events.

✅ With this, your system now supports:

Deposits (via Paystack checkout).

Withdrawals (via Paystack transfer API).

Webhooks (to verify payments & transfer statuses).

Do you want me to extend PaystackService with a bulk transfer method too (for sending payouts to multiple users at once)?

You said:
yes
ChatGPT said:

Perfect 🚀 — let’s add bulk transfers to your PaystackService.
This lets you payout to multiple users at once in a single API call — useful if you’re processing many withdrawals daily.

🔹 Bulk Transfer Workflow (Paystack)

Ensure each beneficiary has a recipient code (transferrecipient endpoint).

Build an array of transfers (each with amount + recipient code).

Call POST /transfer/bulk with that array.

Handle transfer.success / transfer.failed via webhooks.

🔹 Step 1: Extend PaystackService
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PaystackService
{
    protected string $baseUrl;
    protected string $secretKey;

    public function __construct()
    {
        $this->baseUrl = env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co');
        $this->secretKey = env('PAYSTACK_SECRET_KEY');
    }

    // ... existing methods (initializePayment, verifyPayment, createRecipient, initiateTransfer)

    /**
     * Bulk Transfer
     */
    public function bulkTransfer(array $transfers, string $currency = 'NGN')
    {
        return $this->post('/transfer/bulk', [
            'currency' => $currency,
            'source' => 'balance',
            'transfers' => $transfers,
        ]);
    }

    protected function get(string $endpoint, array $params = [])
    {
        $response = Http::withToken($this->secretKey)->get($this->baseUrl . $endpoint, $params);
        return $response->json();
    }

    protected function post(string $endpoint, array $data)
    {
        $response = Http::withToken($this->secretKey)->post($this->baseUrl . $endpoint, $data);
        return $response->json();
    }
}

🔹 Step 2: Example Usage (Bulk Withdrawals)

Suppose you want to process all pending withdrawals in one go:

use App\Models\Withdrawal;
use App\Services\PaystackService;

public function processBulkWithdrawals(PaystackService $paystack)
{
    $pendingWithdrawals = Withdrawal::where('status', 'pending')->get();

    $transfers = [];
    foreach ($pendingWithdrawals as $withdrawal) {
        // Assume recipient_code is already stored in withdrawals table
        if (!$withdrawal->recipient_code) {
            continue; // skip if no recipient
        }

        $transfers[] = [
            'amount' => $withdrawal->amount * 100, // in kobo
            'recipient' => $withdrawal->recipient_code,
            'reference' => 'wd_'.$withdrawal->id, // unique reference
            'reason' => 'Withdrawal for user '.$withdrawal->user_id,
            'metadata' => ['withdrawal_id' => $withdrawal->id],
        ];

        $withdrawal->update(['status' => 'processing']);
    }

    if (count($transfers) > 0) {
        $response = $paystack->bulkTransfer($transfers);

        if (!$response['status']) {
            return back()->with('error', 'Bulk transfer failed: '.$response['message']);
        }

        return back()->with('success', 'Bulk transfer initiated successfully.');
    }

    return back()->with('info', 'No withdrawals to process.');
}

🔹 Step 3: Webhook Handling (Bulk Transfers)

The webhook events are the same as single transfers (transfer.success, transfer.failed).
Since we included metadata.withdrawal_id, we can map each webhook back to the DB:

if ($event === 'transfer.success') {
    $withdrawalId = $data['metadata']['withdrawal_id'] ?? null;
    $withdrawal = Withdrawal::find($withdrawalId);

    if ($withdrawal) {
        $withdrawal->update([
            'status' => 'processed',
            'payload' => $data,
        ]);
    }
}

if ($event === 'transfer.failed') {
    $withdrawalId = $data['metadata']['withdrawal_id'] ?? null;
    $withdrawal = Withdrawal::find($withdrawalId);

    if ($withdrawal) {
        $withdrawal->update([
            'status' => 'failed',
            'payload' => $data,
        ]);

        // refund balance
        $withdrawal->user->increment('balance', $withdrawal->amount);
    }
}

✅ Benefits of Bulk Transfers

Fewer API calls (cheaper + faster).

Process hundreds of withdrawals in one shot.

Works seamlessly with existing webhook system.

👉 Do you also want me to design a command (artisan + scheduler) so withdrawals can be auto-processed in bulk (e.g., every hour) without manual admin clicks?

You said:
yes
ChatGPT said:

Perfect 🚀 — let’s automate bulk withdrawals with a Laravel Artisan command + Scheduler.
This way, the system can run payouts automatically every hour (or any interval you choose).

🔹 Step 1: Create an Artisan Command

Run:

php artisan make:command ProcessBulkWithdrawals


This creates app/Console/Commands/ProcessBulkWithdrawals.php.

Edit it:

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Withdrawal;
use App\Services\PaystackService;

class ProcessBulkWithdrawals extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'withdrawals:process-bulk';

    /**
     * The console command description.
     */
    protected $description = 'Process all pending withdrawals in bulk using Paystack Transfers API';

    /**
     * Execute the console command.
     */
    public function handle(PaystackService $paystack)
    {
        $pendingWithdrawals = Withdrawal::where('status', 'pending')->get();

        if ($pendingWithdrawals->isEmpty()) {
            $this->info('No pending withdrawals found.');
            return Command::SUCCESS;
        }

        $transfers = [];
        foreach ($pendingWithdrawals as $withdrawal) {
            if (!$withdrawal->recipient_code) {
                $this->warn("Skipping withdrawal {$withdrawal->id}, missing recipient_code.");
                continue;
            }

            $transfers[] = [
                'amount' => $withdrawal->amount * 100,
                'recipient' => $withdrawal->recipient_code,
                'reference' => 'wd_' . $withdrawal->id,
                'reason' => 'Withdrawal for user ' . $withdrawal->user_id,
                'metadata' => ['withdrawal_id' => $withdrawal->id],
            ];

            $withdrawal->update(['status' => 'processing']);
        }

        if (empty($transfers)) {
            $this->info('No valid withdrawals to process.');
            return Command::SUCCESS;
        }

        $response = $paystack->bulkTransfer($transfers);

        if ($response['status']) {
            $this->info('✅ Bulk withdrawal request sent to Paystack successfully.');
        } else {
            $this->error('❌ Bulk withdrawal failed: ' . $response['message']);
        }

        return Command::SUCCESS;
    }
}

🔹 Step 2: Register in Kernel (Scheduler)

Open app/Console/Kernel.php and inside schedule():

protected function schedule(Schedule $schedule)
{
    // Run every hour
    $schedule->command('withdrawals:process-bulk')->hourly();

    // Or run every 10 minutes
    // $schedule->command('withdrawals:process-bulk')->everyTenMinutes();
}

🔹 Step 3: Setup Laravel Scheduler on Server

On your server, add to crontab:

* * * * * php /path-to-your-project/artisan schedule:run >> /dev/null 2>&1


This runs Laravel’s scheduler every minute.
Laravel will then trigger withdrawals:process-bulk according to your config (hourly, etc.).

🔹 Step 4: Webhook Updates

Since Paystack will still send transfer.success / transfer.failed, your webhook handler keeps balances accurate.
Failed withdrawals can be refunded automatically.

✅ Benefits

Fully hands-off withdrawal system.

Admins don’t need to click “Process Withdrawals”.

Runs reliably in the background with retry logic via queue + webhook reconciliation.

👉 Do you also want me to extend this so that admins can toggle between auto-processing vs manual approval (e.g., a setting in DB or .env)?

You said:
yes
ChatGPT said:

Perfect ✅ — let’s add a configurable toggle so you (admin) can switch between:

Manual mode → withdrawals stay pending until an admin approves.

Auto mode → withdrawals are auto-processed via Paystack bulk transfers.

🔹 Step 1: Add Config Setting

You can store the mode either in .env or in a database settings table.
Let’s start with .env for simplicity:

WITHDRAWAL_MODE=auto   # options: auto | manual

🔹 Step 2: Add Config Wrapper

Create a config file config/withdrawals.php:

<?php

return [
    'mode' => env('WITHDRAWAL_MODE', 'manual'), // default manual
];


Now you can call:

config('withdrawals.mode');

🔹 Step 3: Update WithdrawalController

When a user requests a withdrawal:

public function submit(Request $request)
{
    $request->validate([
        'amount' => 'required|numeric|min:1000',
        'bank_name' => 'required|string',
        'account_number' => 'required|string',
    ]);

    $user = Auth::user();

    if ($user->balance < $request->amount) {
        return back()->with('error', 'Insufficient balance.');
    }

    DB::transaction(function () use ($request, $user) {
        $user->decrement('balance', $request->amount);

        $withdrawal = Withdrawal::create([
            'user_id' => $user->id,
            'amount' => $request->amount,
            'bank_name' => $request->bank_name,
            'account_number' => $request->account_number,
            'status' => 'pending',
        ]);

        // 🔹 If auto mode, process immediately
        if (config('withdrawals.mode') === 'auto') {
            dispatch(new \App\Jobs\ProcessWithdrawalJob($withdrawal->id));
        }
    });

    return redirect()->route('dashboard')->with('success', 'Withdrawal request submitted.');
}

🔹 Step 4: Background Job for Processing

php artisan make:job ProcessWithdrawalJob

<?php

namespace App\Jobs;

use App\Models\Withdrawal;
use App\Services\PaystackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWithdrawalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $withdrawalId;

    public function __construct(int $withdrawalId)
    {
        $this->withdrawalId = $withdrawalId;
    }

    public function handle(PaystackService $paystack)
    {
        $withdrawal = Withdrawal::find($this->withdrawalId);

        if (!$withdrawal || $withdrawal->status !== 'pending') {
            return;
        }

        // Ensure recipient exists
        if (!$withdrawal->recipient_code) {
            $recipient = $paystack->createRecipient(
                $withdrawal->account_name ?? $withdrawal->user->name,
                $withdrawal->account_number,
                $withdrawal->bank_code
            );

            if (!$recipient['status']) {
                $withdrawal->update(['status' => 'failed', 'payload' => $recipient]);
                return;
            }

            $withdrawal->update(['recipient_code' => $recipient['data']['recipient_code']]);
        }

        // Initiate transfer
        $transfer = $paystack->initiateTransfer(
            $withdrawal->amount,
            $withdrawal->recipient_code,
            'Withdrawal for user ' . $withdrawal->user_id,
            ['withdrawal_id' => $withdrawal->id]
        );

        if ($transfer['status']) {
            $withdrawal->update(['status' => 'processing', 'payload' => $transfer]);
        } else {
            $withdrawal->update(['status' => 'failed', 'payload' => $transfer]);
            $withdrawal->user->increment('balance', $withdrawal->amount);
        }
    }
}

🔹 Step 5: Adjust Bulk Command

In your ProcessBulkWithdrawals command, only run if WITHDRAWAL_MODE=auto:

public function handle(PaystackService $paystack)
{
    if (config('withdrawals.mode') !== 'auto') {
        $this->info('Withdrawals in manual mode. Skipping bulk processing.');
        return Command::SUCCESS;
    }

    // ... existing bulk transfer logic
}

🔹 Step 6: Admin UI (Switch Mode)

If you want admins to toggle from the UI:

Add a settings table (key, value).

Replace config('withdrawals.mode') with Setting::get('withdrawal_mode', 'manual').

Provide a simple dropdown in your admin panel:

manual → requires admin approval.

auto → queue + scheduler handles automatically.