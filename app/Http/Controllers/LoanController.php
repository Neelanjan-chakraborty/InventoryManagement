<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Loan;
use App\Models\LoanLine;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\BorrowingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoanController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->filteredLoans($request);

        return view('loans.index', [
            'students' => User::query()->where('role', 'student')->orderBy('name')->get(),
            'items' => InventoryItem::query()->with('assets')->orderBy('name')->get(),
            'programmes' => User::query()->whereNotNull('programme')->distinct()->orderBy('programme')->pluck('programme'),
            'locations' => StorageLocation::query()->where('active', true)->orderBy('name')->get(),
            'loans' => $query->orderBy('due_date')->paginate(20)->withQueryString(),
        ]);
    }

    public function export(Request $request)
    {
        $filters = $request->only(['student_id', 'programme', 'item_id', 'location_id', 'status']);

        return response()->streamDownload(function () use ($filters) {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Date', 'Event', 'Student', 'Enrollment', 'Programme', 'Item', 'Asset ID', 'Quantity', 'Outstanding', 'Due date', 'Responsible user', 'Reason']);
            $query = InventoryMovement::query()->with('item.location', 'asset', 'user', 'loan.student', 'loan.lines');
            if ($filters['student_id'] ?? null) {
                $query->whereHas('loan', fn ($loans) => $loans->where('student_id', $filters['student_id']));
            }
            if ($filters['programme'] ?? null) {
                $query->whereHas('loan.student', fn ($students) => $students->where('programme', $filters['programme']));
            }
            if ($filters['item_id'] ?? null) {
                $query->where('item_id', $filters['item_id']);
            }
            if ($filters['location_id'] ?? null) {
                $query->whereHas('item', fn ($items) => $items->where('location_id', $filters['location_id']));
            }
            if (($filters['status'] ?? 'all') !== 'all') {
                $query->whereHas('loan', fn ($loans) => $loans->where('status', $filters['status']));
            }

            $safe = fn ($value) => is_string($value) && preg_match('/^\s*[=+\-@]/', $value) ? "'".$value : $value;
            $query->orderBy('occurred_at')->chunk(200, function ($movements) use ($output, $safe) {
                foreach ($movements as $movement) {
                    $line = $movement->loan?->lines->first(fn ($entry) => $entry->item_id === $movement->item_id && $entry->asset_id === $movement->asset_id);
                    fputcsv($output, array_map($safe, [
                        $movement->occurred_at->toDateTimeString(),
                        str($movement->type)->replace('_', ' ')->title()->toString(),
                        $movement->loan?->student?->name,
                        $movement->loan?->student?->enrollment_number,
                        $movement->loan?->student?->programme,
                        $movement->item->name,
                        $movement->asset?->asset_tag,
                        $movement->quantity,
                        $line?->outstanding_quantity,
                        $movement->loan?->due_date?->toDateString(),
                        $movement->user->name,
                        $movement->reason,
                    ]));
                }
            });
            fclose($output);
        }, 'department-transactions.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request, BorrowingService $borrowing)
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:users,id'],
            'item_id' => ['required', 'exists:inventory_items,id'],
            'asset_id' => ['nullable', 'exists:assets,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'issue_date' => ['nullable', 'date'],
            'purpose' => ['required', 'string', 'max:1000'],
        ]);

        $student = User::query()->findOrFail($data['student_id']);
        $loan = $borrowing->issue($student, $request->user(), $data);

        return view('loans.acknowledgement', compact('loan'));
    }

    public function receiveReturn(Request $request, LoanLine $line, BorrowingService $borrowing)
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'condition' => ['required', Rule::in(['usable', 'damaged', 'lost', 'maintenance'])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $borrowing->receiveReturn($line, $request->user(), $data);

        return back()->with('success', 'Return recorded with the inspected condition.');
    }

    public function myLoans(Request $request)
    {
        $loans = Loan::query()->where('student_id', $request->user()->id)
            ->with('issuer', 'lines.item', 'lines.asset', 'lines.returns.receiver', 'renewals.reviewer')
            ->latest('issue_date')->paginate(20);

        return view('loans.mine', compact('loans'));
    }

    public function requestRenewal(Request $request, Loan $loan, BorrowingService $borrowing)
    {
        abort_unless($loan->student_id === $request->user()->id, 404);
        $borrowing->requestRenewal($loan, $request->user());

        return back()->with('success', 'Renewal request sent. Your due date will not change until it is approved.');
    }

    private function filteredLoans(Request $request)
    {
        $query = Loan::query()->with('student', 'lines.item', 'lines.asset', 'lines.returns', 'renewals');

        if (($request->input('status', 'active')) !== 'all') {
            $query->where('status', $request->input('status', 'active'));
        }
        if ($request->filled('student_id')) {
            $query->where('student_id', $request->input('student_id'));
        }
        if ($request->filled('programme')) {
            $query->whereHas('student', fn ($students) => $students->where('programme', $request->input('programme')));
        }
        if ($request->filled('item_id')) {
            $query->whereHas('lines', fn ($lines) => $lines->where('item_id', $request->input('item_id')));
        }
        if ($request->filled('location_id')) {
            $query->whereHas('lines.item', fn ($items) => $items->where('location_id', $request->input('location_id')));
        }

        return $query;
    }
}
