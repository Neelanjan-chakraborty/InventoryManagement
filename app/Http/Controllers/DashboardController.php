<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\Loan;
use App\Models\RenewalRequest;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        if ($user->hasRole('student')) {
            $loans = $user->loans()->with('lines.item', 'lines.asset', 'renewals')
                ->latest('issue_date')->get();
            $active = $loans->where('status', 'active');

            return view('dashboard', [
                'loans' => $loans,
                'activeLoans' => $active->count(),
                'overdueLoans' => $active->filter(fn ($loan) => $loan->due_date->isBefore(today()))->count(),
                'dueSoonLoans' => $active->filter(fn ($loan) => $loan->due_date->between(today(), today()->addDays(5)))->count(),
            ]);
        }

        $activeLoans = Loan::query()->where('status', 'active');

        return view('dashboard', [
            'itemCount' => InventoryItem::query()->where('active', true)->count(),
            'availableCount' => InventoryItem::query()->where('active', true)->get()->sum(fn ($item) => $item->availableQuantity()),
            'activeCount' => (clone $activeLoans)->count(),
            'overdueCount' => (clone $activeLoans)->whereDate('due_date', '<', today())->count(),
            'pendingRenewals' => RenewalRequest::query()->where('status', 'pending')->count(),
            'upcomingLoans' => (clone $activeLoans)->with('student', 'lines.item')->whereBetween('due_date', [today(), today()->addDays(5)])->orderBy('due_date')->limit(8)->get(),
            'overdueLoans' => (clone $activeLoans)->with('student', 'lines.item')->whereDate('due_date', '<', today())->orderBy('due_date')->limit(8)->get(),
        ]);
    }
}
