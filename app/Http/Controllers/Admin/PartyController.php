<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Party;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PartyController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:parties.view', only: ['index', 'ledger']),
            new Middleware('permission:parties.create', only: ['create', 'store', 'quickStore']),
            new Middleware('permission:parties.edit', only: ['edit', 'update', 'toggleStatus']),
            new Middleware('permission:parties.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $role = $request->get('role');

        $parties = Party::query()
            ->when($role === 'customer', fn ($q) => $q->where('is_customer', true))
            ->when($role === 'supplier', fn ($q) => $q->where('is_supplier', true))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->q;
                $q->where(function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.parties.index', compact('parties', 'role'));
    }

    public function create()
    {
        return view('admin.parties.create');
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        $party = Party::create($validated);

        return redirect()->route('parties.index')->with('success', "Party \"{$party->name}\" created.");
    }

    /**
     * Minimal party created from inside an order form (sales order customer,
     * purchase order supplier) without leaving the page. Answers JSON so the
     * form can add and select it in place.
     */
    public function quickStore(Request $request)
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(['customer', 'supplier'])],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($existing = Party::where('phone', $validated['phone'])->first()) {
            throw ValidationException::withMessages([
                'phone' => "{$existing->name} already uses this phone — pick that party from the list instead.",
            ]);
        }

        $party = Party::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'contact_person' => $validated['contact_person'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'is_customer' => $validated['role'] === 'customer',
            'is_supplier' => $validated['role'] === 'supplier',
            'status' => true,
        ]);

        return response()->json(['id' => (string) $party->id, 'name' => $party->name], 201);
    }

    public function edit(Party $party)
    {
        return view('admin.parties.edit', ['party' => $party]);
    }

    public function ledger(Party $party)
    {
        $lines = $party->lines()
            ->with(['transaction', 'account'])
            ->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_transaction_lines.ledger_transaction_id')
            ->orderBy('ledger_transactions.date')
            ->orderBy('ledger_transaction_lines.id')
            ->select('ledger_transaction_lines.*')
            ->get();

        // A Customer+Supplier party can carry both balances at once.
        $receivable = $party->receivableBalance();
        $payable = $party->payableBalance();

        return view('admin.parties.ledger', compact('party', 'lines', 'receivable', 'payable'));
    }

    public function update(Request $request, Party $party)
    {
        $validated = $this->validated($request, $party);

        $party->update($validated);

        return redirect()->route('parties.index')->with('success', "Party \"{$party->name}\" updated.");
    }

    public function toggleStatus(Party $party)
    {
        $party->update(['status' => ! $party->status]);

        return back()->with('success', "Party \"{$party->name}\" is now ".($party->status ? 'active' : 'inactive').'.');
    }

    public function destroy(Party $party)
    {
        $party->delete();

        return redirect()->route('parties.index')->with('success', "Party \"{$party->name}\" deleted.");
    }

    protected function validated(Request $request, ?Party $party = null): array
    {
        $validated = $request->validate([
            'is_customer' => ['nullable', 'boolean'],
            'is_supplier' => ['nullable', 'boolean'],
            'is_company' => ['nullable', 'boolean'],
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('parties', 'phone')->ignore($party?->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'nid_no' => ['nullable', 'string', 'max:50'],
            'bin_no' => ['nullable', 'string', 'max:50'],
            'tin_no' => ['nullable', 'string', 'max:50'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'credit_days' => ['nullable', 'integer', 'min:0'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'opening_balance_type' => ['required', Rule::in(Party::OPENING_BALANCE_TYPES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach (['is_customer', 'is_supplier', 'is_company'] as $flag) {
            $validated[$flag] = $request->boolean($flag);
        }

        if (! $validated['is_customer'] && ! $validated['is_supplier']) {
            throw ValidationException::withMessages([
                'is_customer' => 'Pick at least one — Customer or Supplier.',
            ]);
        }

        $validated['credit_limit'] ??= 0;
        $validated['credit_days'] ??= 0;
        $validated['opening_balance'] ??= 0;

        return $validated;
    }
}
