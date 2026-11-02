<?php

namespace App\Http\Controllers;

use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\Billing\BillingRecords;
use App\Services\Billing\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingManagementController extends Controller
{
    public function receipt(Request $request, string $reference)
    {
        $payment = BillingPayment::where('reference', $reference)->where('user_id', $request->user()->id)->firstOrFail();
        $receipt = DB::table('billing_receipts')->where('billing_payment_id', $payment->id)->first();
        abort_unless($receipt, 404);
        $details = json_decode($receipt->details, true, flags: JSON_THROW_ON_ERROR);

        return response()->view('workspace.receipt', compact('receipt', 'details', 'payment'))
            ->header('Content-Disposition', 'attachment; filename="receipt-'.$receipt->number.'.html"')
            ->header('Cache-Control', 'private, no-store');
    }

    public function cancel(Request $request, Subscription $subscription, BillingRecords $records)
    {
        abort_unless($subscription->user_id === $request->user()->id, 404);
        DB::transaction(function () use ($request, $subscription, $records) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $current = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            abort_unless(BillingPayment::where('subscription_id', $current->id)->where('provider', 'paynow')->exists(), 409);
            abort_unless(! $current->auto_renew && $current->status === 'active', 409);
            if (! $current->cancelled_at) {
                $current->forceFill(['cancelled_at' => now()])->save();
                $records->message($current->user_id, 'subscription:'.$current->id.':cancelled', 'Renewal reminders cancelled',
                    'You chose not to renew this paid period. Access continues until '.$current->ends_at->toDateString().'. No automatic deductions are enabled.');
            }
        });

        return to_route('subscription')->with('billing_status', 'Renewal reminders cancelled. Paid access continues until the end of your paid period.');
    }

    public function admin(Request $request)
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|in:pending,paid,test_paid,cancelled,failed,reversed', 'export' => 'nullable|in:csv']);
        $query = BillingPayment::where('provider', 'paynow');
        if (! empty($data['search'])) {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$data['search'].'%')->orWhere('provider_reference', 'like', '%'.$data['search'].'%'));
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (($data['export'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($query) {
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Reference', 'Paynow reference', 'Status', 'Provider status', 'Amount cents', 'Currency', 'Mode']);
                foreach ($query->orderBy('id')->cursor() as $payment) {
                    $cells = [$payment->reference, $payment->provider_reference, $payment->status, $payment->provider_status, $payment->amount_cents, $payment->currency, $payment->mode];
                    fputcsv($output, array_map(fn ($v) => preg_match('/^[=+@\-\t\r]/', (string) $v) ? "'".$v : $v, $cells));
                }
                fclose($output);
            }, 'paynow-payments.csv');
        }

        return view('workspace.admin-billing', ['payments' => $query->latest()->paginate(25)->withQueryString(),
            'totals' => BillingPayment::where('provider', 'paynow')->selectRaw('status, mode, count(*) as total, sum(amount_cents) as cents')->groupBy('status', 'mode')->get(),
            'subscriptions' => Subscription::selectRaw('status, count(*) as total')->groupBy('status')->get(),
            'plans' => BillingPayment::where('provider', 'paynow')->where('mode', 'live')->where('status', 'paid')
                ->join('subscription_plans', 'subscription_plans.id', '=', 'billing_payments.subscription_plan_id')
                ->selectRaw('subscription_plans.name, count(*) as total, sum(billing_payments.amount_cents) as cents, sum(billing_payments.credits) as credits')
                ->groupBy('subscription_plans.name')->get(),
            'monthlyRevenue' => BillingPayment::where('provider', 'paynow')->where('mode', 'live')->where('status', 'paid')
                ->where('paid_at', '>=', now()->startOfMonth())->sum('amount_cents'),
            'annualRevenue' => BillingPayment::where('provider', 'paynow')->where('mode', 'live')->where('status', 'paid')
                ->where('paid_at', '>=', now()->startOfYear())->sum('amount_cents'),
            'notifications' => DB::table('payment_notifications')->whereNull('processed_at')->latest('id')->limit(25)->get(),
            'reviews' => DB::table('billing_reviews')->latest('id')->limit(25)->get()]);
    }

    public function reconcile(Request $request, string $reference, PaymentService $service)
    {
        abort_unless($request->user()->is_admin, 403);
        $payment = BillingPayment::where('provider', 'paynow')->where('reference', $reference)->firstOrFail();
        if (! $payment->poll_url && ! $payment->merchant_trace) {
            return back()->withErrors(['billing' => 'No provider poll URL has been recovered. Review this reference with Paynow before any new payment or adjustment.']);
        }
        try {
            $service->refresh($payment);
        } catch (\Throwable) {
            return back()->withErrors(['billing' => 'Provider verification unavailable. The payment remains unresolved.']);
        }

        return back()->with('billing_status', 'Paynow status reconciled.');
    }

    public function review(Request $request, string $reference, AuditTrail $audit)
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['type' => 'required|in:refund_requested,refund_recorded,dispute_review', 'notes' => 'required|string|min:10|max:2000']);
        $payment = BillingPayment::where('provider', 'paynow')->where('reference', $reference)->firstOrFail();
        DB::transaction(function () use ($request, $data, $payment, $audit) {
            DB::table('billing_reviews')->insert(['billing_payment_id' => $payment->id, 'admin_id' => $request->user()->id,
                ...$data, 'created_at' => now(), 'updated_at' => now()]);
            $audit->record($payment->user_id, 'payment.'.$data['type'], 'BillingPayment', $payment->id);
        });

        return back()->with('billing_status', 'Review recorded. Process any money movement through Paynow, then reconcile to confirm the provider status.');
    }
}
