<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Loan;
use App\Models\User;
use App\Services\BorrowingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BorrowingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_issue_uses_fifteen_day_due_date_and_only_approved_renewal_extends_it(): void
    {
        $staff = User::factory()->create(['role' => 'coordinator']);
        $student = User::factory()->create(['role' => 'student']);
        $item = InventoryItem::create([
            'code' => 'USB-01', 'name' => 'USB cable', 'category' => 'Cable',
            'tracking_type' => 'bulk', 'total_quantity' => 20,
        ]);
        $service = app(BorrowingService::class);

        $loan = $service->issue($student, $staff, [
            'item_id' => $item->id, 'quantity' => 2, 'purpose' => 'Lab practical', 'issue_date' => '2026-10-01',
        ]);

        $this->assertSame('2026-10-16', $loan->due_date->toDateString());
        $this->assertSame(18, $item->fresh()->availableQuantity());

        $renewal = $service->requestRenewal($loan, $student);
        $this->assertSame('2026-10-16', $loan->fresh()->due_date->toDateString());
        $this->assertSame('pending', $renewal->status);

        $service->reviewRenewal($renewal, $staff, 'approved', 'Project work continues.');

        $this->assertSame('2026-10-31', $loan->fresh()->due_date->toDateString());
        $this->assertDatabaseHas('inventory_movements', ['type' => 'renewal_approved', 'user_id' => $staff->id]);
    }

    public function test_partial_return_keeps_balance_outstanding_and_quarantines_damaged_units(): void
    {
        $staff = User::factory()->create(['role' => 'coordinator']);
        $student = User::factory()->create(['role' => 'student']);
        $item = InventoryItem::create([
            'code' => 'ADP-01', 'name' => 'Adapter', 'category' => 'Adapter',
            'tracking_type' => 'bulk', 'total_quantity' => 4,
        ]);
        $service = app(BorrowingService::class);
        $loan = $service->issue($student, $staff, [
            'item_id' => $item->id, 'quantity' => 3, 'purpose' => 'Workshop', 'issue_date' => '2026-10-01',
        ]);
        $line = $loan->lines()->first();

        $service->receiveReturn($line, $staff, ['quantity' => 1, 'condition' => 'damaged']);

        $this->assertSame(2, $line->fresh()->outstanding_quantity);
        $this->assertSame(1, $item->fresh()->damaged_quantity);
        $this->assertSame(1, $item->fresh()->availableQuantity());
        $this->assertSame('active', $loan->fresh()->status);

        $service->receiveReturn($line->fresh(), $staff, ['quantity' => 2, 'condition' => 'usable']);

        $this->assertSame(0, $line->fresh()->outstanding_quantity);
        $this->assertSame(3, $item->fresh()->availableQuantity());
        $this->assertSame('completed', $loan->fresh()->status);
    }

    public function test_insufficient_issue_does_not_create_loan_or_change_stock(): void
    {
        $staff = User::factory()->create(['role' => 'coordinator']);
        $student = User::factory()->create(['role' => 'student']);
        $item = InventoryItem::create([
            'code' => 'DEV-01', 'name' => 'Development board', 'category' => 'Board',
            'tracking_type' => 'bulk', 'total_quantity' => 3,
        ]);

        try {
            app(BorrowingService::class)->issue($student, $staff, [
                'item_id' => $item->id, 'quantity' => 5, 'purpose' => 'Lab', 'issue_date' => '2026-10-01',
            ]);
            $this->fail('The issue should be rejected when requested quantity exceeds stock.');
        } catch (ValidationException) {
            $this->assertSame(0, Loan::count());
            $this->assertSame(3, $item->fresh()->availableQuantity());
            $this->assertDatabaseCount('inventory_movements', 0);
        }
    }

    public function test_individually_tracked_asset_cannot_be_issued_to_two_students_at_once(): void
    {
        $staff = User::factory()->create(['role' => 'coordinator']);
        $firstStudent = User::factory()->create(['role' => 'student']);
        $secondStudent = User::factory()->create(['role' => 'student']);
        $item = InventoryItem::create([
            'code' => 'DB-SET', 'name' => 'Development board', 'category' => 'Board',
            'tracking_type' => 'asset', 'total_quantity' => 1,
        ]);
        $asset = $item->assets()->create(['asset_tag' => 'DB-014', 'condition' => 'usable', 'status' => 'available']);
        $service = app(BorrowingService::class);
        $data = ['item_id' => $item->id, 'asset_id' => $asset->id, 'quantity' => 1, 'purpose' => 'Lab', 'issue_date' => '2026-10-01'];

        $service->issue($firstStudent, $staff, $data);
        $this->assertSame('issued', $asset->fresh()->status);
        $this->assertSame(0, $item->fresh()->availableQuantity());

        try {
            $service->issue($secondStudent, $staff, $data);
            $this->fail('An asset cannot be issued to a second student before it is returned.');
        } catch (ValidationException) {
            $this->assertSame(1, Loan::count());
            $this->assertSame('issued', $asset->fresh()->status);
        }
    }
}
