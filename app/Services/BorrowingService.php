<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ItemReturn;
use App\Models\Loan;
use App\Models\LoanLine;
use App\Models\RenewalRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BorrowingService
{
    /**
     * InnoDB enforces the lockForUpdate() locks these methods rely on, so two
     * concurrent transactions can deadlock or hit a lock-wait timeout. Laravel
     * retries the whole closure that many times before surfacing the error.
     */
    private const DEADLOCK_ATTEMPTS = 3;

    public function issue(User $student, User $staff, array $data): Loan
    {
        return DB::transaction(function () use ($student, $staff, $data) {
            if (! $student->hasRole('student')) {
                throw ValidationException::withMessages(['student_id' => 'Items can only be issued to registered students.']);
            }

            $item = InventoryItem::query()->lockForUpdate()->findOrFail($data['item_id']);
            $quantity = (int) ($data['quantity'] ?? 1);
            $asset = null;

            if (! $item->active) {
                throw ValidationException::withMessages(['item_id' => 'This inventory item is retired and unavailable.']);
            }

            if ($item->tracking_type === 'asset') {
                $asset = Asset::query()->lockForUpdate()
                    ->where('item_id', $item->id)
                    ->whereKey($data['asset_id'] ?? 0)
                    ->first();

                if ($quantity !== 1 || ! $asset || $asset->status !== 'available' || $asset->condition !== 'usable') {
                    throw ValidationException::withMessages(['asset_id' => 'That asset is not currently available in usable condition.']);
                }
            } else {
                $available = $item->availableQuantity();

                if ($quantity < 1 || $quantity > $item->max_per_issue) {
                    throw ValidationException::withMessages(['quantity' => "The issue limit for this item is {$item->max_per_issue} per transaction."]);
                }

                if ($quantity > $available) {
                    throw ValidationException::withMessages(['quantity' => "{$quantity} requested, but only {$available} are currently available."]);
                }
            }

            $issueDate = Carbon::parse($data['issue_date'] ?? today())->startOfDay();
            $loan = Loan::create([
                'student_id' => $student->id,
                'issued_by' => $staff->id,
                'issue_date' => $issueDate,
                'due_date' => $issueDate->copy()->addDays(15),
                'purpose' => $data['purpose'],
                'status' => 'active',
            ]);

            $line = $loan->lines()->create([
                'item_id' => $item->id,
                'asset_id' => $asset?->id,
                'quantity' => $quantity,
                'outstanding_quantity' => $quantity,
            ]);

            if ($asset) {
                $asset->update(['status' => 'issued']);
            }

            $this->recordMovement($item, $staff, 'issued', -$quantity, $loan, $asset);

            return $loan->load(['student', 'lines.item', 'lines.asset']);
        }, self::DEADLOCK_ATTEMPTS);
    }

    public function receiveReturn(LoanLine $line, User $staff, array $data): ItemReturn
    {
        return DB::transaction(function () use ($line, $staff, $data) {
            $line = LoanLine::query()->lockForUpdate()->findOrFail($line->id);
            $item = InventoryItem::query()->lockForUpdate()->findOrFail($line->item_id);
            $quantity = (int) $data['quantity'];

            if ($line->loan->status !== 'active' || $quantity < 1 || $quantity > $line->outstanding_quantity) {
                throw ValidationException::withMessages(['quantity' => 'Return quantity must not exceed the outstanding quantity.']);
            }

            if ($item->tracking_type === 'asset' && ($quantity !== 1 || $line->outstanding_quantity !== 1)) {
                throw ValidationException::withMessages(['quantity' => 'An individually tracked asset must be returned as one complete asset.']);
            }

            $condition = $data['condition'];
            $return = ItemReturn::create([
                'loan_line_id' => $line->id,
                'quantity' => $quantity,
                'condition' => $condition,
                'received_by' => $staff->id,
                'received_at' => now(),
                'remarks' => $data['remarks'] ?? null,
            ]);

            $line->decrement('outstanding_quantity', $quantity);

            if ($line->asset_id) {
                $line->asset()->update(['condition' => $condition, 'status' => $condition === 'usable' ? 'available' : $condition]);
            } elseif ($condition !== 'usable') {
                $column = match ($condition) {
                    'damaged' => 'damaged_quantity',
                    'lost' => 'lost_quantity',
                    'maintenance' => 'maintenance_quantity',
                };
                $item->increment($column, $quantity);
            }

            $this->recordMovement($item, $staff, 'returned_'.$condition, $quantity, $line->loan, $line->asset);

            if (! $line->loan->lines()->where('outstanding_quantity', '>', 0)->exists()) {
                $line->loan->update(['status' => 'completed']);
            }

            return $return;
        }, self::DEADLOCK_ATTEMPTS);
    }

    public function requestRenewal(Loan $loan, User $student): RenewalRequest
    {
        return DB::transaction(function () use ($loan, $student) {
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->id);

            if ($loan->student_id !== $student->id || $loan->status !== 'active' || $loan->due_date->isBefore(today())) {
                throw ValidationException::withMessages(['loan' => 'Renewals can only be requested for your active loans before their due date.']);
            }

            if ($loan->renewals()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['loan' => 'A renewal request is already awaiting review.']);
            }

            $limit = $loan->lines()->with('item')->get()->min('item.max_renewals') ?? 0;
            $approved = $loan->renewals()->where('status', 'approved')->count();

            if ($approved >= $limit) {
                throw ValidationException::withMessages(['loan' => 'The renewal limit for this item has been reached.']);
            }

            return $loan->renewals()->create([
                'requested_by' => $student->id,
                'requested_at' => now(),
                'status' => 'pending',
                'previous_due_date' => $loan->due_date,
            ]);
        }, self::DEADLOCK_ATTEMPTS);
    }

    public function reviewRenewal(RenewalRequest $renewal, User $staff, string $decision, ?string $remarks): RenewalRequest
    {
        return DB::transaction(function () use ($renewal, $staff, $decision, $remarks) {
            $renewal = RenewalRequest::query()->lockForUpdate()->findOrFail($renewal->id);
            $loan = Loan::query()->lockForUpdate()->findOrFail($renewal->loan_id);

            if ($renewal->status !== 'pending') {
                throw ValidationException::withMessages(['renewal' => 'This request has already been reviewed.']);
            }

            if ($decision === 'approved') {
                if ($loan->status !== 'active') {
                    throw ValidationException::withMessages(['renewal' => 'A completed loan cannot be renewed.']);
                }

                if ($loan->due_date->toDateString() !== $renewal->previous_due_date->toDateString()) {
                    throw ValidationException::withMessages(['renewal' => 'The loan due date changed after this request was submitted.']);
                }

                $revised = $renewal->previous_due_date->copy()->addDays(15);
                $loan->update(['due_date' => $revised]);
                $renewal->update(['revised_due_date' => $revised]);
                foreach ($loan->lines as $line) {
                    $this->recordMovement($line->item, $staff, 'renewal_approved', 0, $loan, $line->asset, $remarks);
                }
            }

            $renewal->update([
                'status' => $decision,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
                'remarks' => $remarks,
            ]);

            return $renewal->fresh(['loan', 'requester', 'reviewer']);
        }, self::DEADLOCK_ATTEMPTS);
    }

    private function recordMovement(
        InventoryItem $item,
        User $user,
        string $type,
        int $quantity,
        ?Loan $loan = null,
        ?Asset $asset = null,
        ?string $reason = null,
    ): void {
        InventoryMovement::create([
            'item_id' => $item->id,
            'asset_id' => $asset?->id,
            'loan_id' => $loan?->id,
            'user_id' => $user->id,
            'type' => $type,
            'quantity' => $quantity,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
