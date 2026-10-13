<?php

namespace App\Http\Controllers;

use App\Models\RenewalRequest;
use App\Services\BorrowingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RenewalController extends Controller
{
    public function index()
    {
        $renewals = RenewalRequest::query()->with('loan.student', 'loan.lines.item', 'requester', 'reviewer')
            ->latest('requested_at')->paginate(25);

        return view('renewals.index', compact('renewals'));
    }

    public function review(Request $request, RenewalRequest $renewal, BorrowingService $borrowing)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $borrowing->reviewRenewal($renewal, $request->user(), $data['decision'], $data['remarks'] ?? null);

        return back()->with('success', $data['decision'] === 'approved'
            ? 'Renewal approved. The due date has been extended by 15 days.'
            : 'Renewal rejected with the recorded remarks.');
    }
}
