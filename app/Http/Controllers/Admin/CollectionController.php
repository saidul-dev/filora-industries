<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\CompanySetting;
use App\Models\LedgerAccount;
use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\Project;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment In — settles the Accounts Receivable a Sale (or a Customer's
 * opening balance) created. Symmetric counterpart to PaymentController
 * (Payment Out). See docs/accounting-foundation.md.
 */
class CollectionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:accounts.view', only: ['index', 'show', 'print']),
            new Middleware('permission:accounts.create', only: ['create', 'store']),
        ];
    }

    public function index(Request $request)
    {
        $partyId = $request->filled('party_id') ? $request->integer('party_id') : null;

        $collections = Collection::with(['party', 'account', 'proformaInvoice'])
            ->when($partyId, fn ($q) => $q->where('party_id', $partyId))
            ->orderByDesc('collection_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $customers = Party::where('is_customer', true)->where('status', true)->orderBy('name')->get(['id', 'name']);

        return view('admin.collections.index', compact('collections', 'customers', 'partyId'));
    }

    /**
     * Picking a Customer reveals their current receivable balance so the
     * amount can be judged against it — not enforced as a hard cap, since
     * collecting more than currently due is a valid advance-from-customer
     * case (the signed balance math already handles that correctly).
     */
    public function create(Request $request)
    {
        $customers = Party::where('is_customer', true)->where('status', true)->orderBy('name')->get(['id', 'name']);
        $accounts = LedgerAccount::where('group', 'cash_bank')->where('status', true)->orderBy('name')->get(['id', 'name']);

        $selectedInvoice = $request->filled('proforma_invoice_id')
            ? ProformaInvoice::whereNotIn('status', ['draft', 'cancelled'])->find($request->integer('proforma_invoice_id'))
            : null;

        $party = $request->filled('party_id')
            ? Party::findOrFail($request->integer('party_id'))
            : $selectedInvoice?->party;
        $receivable = $party?->receivableBalance();

        // The buyer's issued PIs, so a payment (LC proceeds, advance,
        // balance) can be recorded against the one it settles.
        $invoices = $party
            ? ProformaInvoice::where('party_id', $party->id)->whereNotIn('status', ['draft', 'cancelled'])->orderByDesc('id')->get()
                ->map(fn ($pi) => (object) ['id' => $pi->id, 'name' => $pi->pi_no.' — '.__('due').' '.number_format($pi->dueAmount(), 2)])
            : collect();

        return view('admin.collections.create', compact('customers', 'accounts', 'party', 'receivable', 'invoices', 'selectedInvoice'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'party_id' => ['required', 'integer', 'exists:parties,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'proforma_invoice_id' => ['nullable', 'integer', 'exists:proforma_invoices,id'],
            'ledger_account_id' => ['required', 'integer', 'exists:ledger_accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'collection_date' => ['required', 'date'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $party = Party::findOrFail($validated['party_id']);
        if (! $party->is_customer) {
            throw ValidationException::withMessages(['party_id' => 'Pick a party marked as a Customer.']);
        }

        $project = null;
        if (! empty($validated['project_id'])) {
            $project = Project::findOrFail($validated['project_id']);
            if ($project->party_id !== $party->id) {
                throw ValidationException::withMessages(['project_id' => 'This project does not belong to the selected party.']);
            }
        }

        $invoice = null;
        if (! empty($validated['proforma_invoice_id'])) {
            $invoice = ProformaInvoice::findOrFail($validated['proforma_invoice_id']);
            if ($invoice->party_id !== $party->id) {
                throw ValidationException::withMessages(['proforma_invoice_id' => 'This Proforma Invoice does not belong to the selected customer.']);
            }
            if (in_array($invoice->status, ['draft', 'cancelled'], true)) {
                throw ValidationException::withMessages(['proforma_invoice_id' => 'Payments can only be collected against an issued Proforma Invoice.']);
            }
        }

        $account = LedgerAccount::where('group', 'cash_bank')->findOrFail($validated['ledger_account_id']);

        $collection = DB::transaction(function () use ($validated, $party, $project, $invoice, $account) {
            $collection = Collection::create([
                'collection_no' => 'PENDING',
                'party_id' => $party->id,
                'project_id' => $project?->id,
                'proforma_invoice_id' => $invoice?->id,
                'ledger_account_id' => $account->id,
                'amount' => $validated['amount'],
                'collection_date' => $validated['collection_date'],
                'reference_no' => $validated['reference_no'] ?? null,
                'note' => $validated['note'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $collection->update(['collection_no' => 'COL-'.str_pad($collection->id, 6, '0', STR_PAD_LEFT)]);

            LedgerService::post([
                'type' => 'payment_in',
                'date' => $validated['collection_date'],
                // The receiving account's own site wins when it's tied to
                // one (e.g. "Warehouse A Cash"); a company-wide account
                // (site_id null) falls back to the linked project's site,
                // so Day Book still shows something meaningful instead of
                // a blank dash.
                'site_id' => $account->site_id ?? $project?->site_id,
                'narration' => "Collection from {$party->name} ({$collection->collection_no})".($invoice ? " against {$invoice->pi_no}" : ''),
                'reference' => $collection,
                'created_by' => Auth::id(),
                'lines' => [
                    ['account' => $account, 'debit' => $validated['amount']],
                    ['account' => 'accounts_receivable', 'party_id' => $party->id, 'credit' => $validated['amount']],
                ],
            ]);

            return $collection;
        });

        $message = "Collection {$collection->collection_no} recorded.";

        return match (true) {
            $project !== null => redirect()->route('projects.show', $project)->with('success', $message),
            $invoice !== null && Auth::user()->can('sales.view') => redirect()->route('proforma-invoices.show', $invoice)->with('success', $message),
            default => redirect()->route('collections.show', $collection)->with('success', $message),
        };
    }

    public function show(Collection $collection)
    {
        $collection->load(['party', 'account', 'creator', 'proformaInvoice', 'ledgerTransaction.lines.account']);

        return view('admin.collections.show', compact('collection'));
    }

    public function print(Collection $collection)
    {
        $collection->load(['party', 'account', 'creator']);
        $company = CompanySetting::current();

        return view('admin.collections.print', compact('collection', 'company'));
    }
}
