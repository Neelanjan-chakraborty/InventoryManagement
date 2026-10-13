<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\BorrowingService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Local/dev convenience only — never run automatically (DatabaseSeeder does not
 * call this). Populates a realistic, varied dataset on top of DevAccountsSeeder:
 * storage locations, bulk and individually tracked inventory, a spread of
 * students across programmes/semesters, and loans/returns/renewals in every
 * state the UI knows how to render (active, due soon, overdue, completed,
 * partially returned with each damage condition, pending/approved/rejected
 * renewals). Everything is written through BorrowingService so the same
 * invariants (locks, movement rows, status transitions) apply as in the app.
 *
 *   docker compose exec app php artisan db:seed --class="Database\\Seeders\\DemoDataSeeder" --force
 *
 * Not idempotent: running it twice creates a second batch of loans. If you
 * need a clean slate, run `php artisan migrate:fresh` first.
 */
class DemoDataSeeder extends Seeder
{
    use WithoutModelEvents;

    private Carbon $today;

    /** @var array<int, User> */
    private array $coordinators = [];

    /** @var array<int, User> */
    private array $students = [];

    public function run(): void
    {
        if (InventoryItem::where('code', 'DEMO-RESIS-01')->exists()) {
            $this->command?->warn('Demo data already present — run `php artisan migrate:fresh` first to reseed.');

            return;
        }

        $this->today = Carbon::today();

        $this->call(DevAccountsSeeder::class);

        $locations = $this->seedLocations();
        $this->coordinators = $this->seedCoordinators();
        $this->students = $this->seedStudents();
        [$bulkItems, $assetItems] = $this->seedInventory($locations);

        $this->seedBulkLoans($bulkItems);
        $this->seedAssetLoans($assetItems);
        $this->seedStockAdjustments($bulkItems);

        $this->command?->info(sprintf(
            'Demo data ready: %d locations, %d items, %d coordinators, %d students, %d loans.',
            count($locations), count($bulkItems) + count($assetItems), count($this->coordinators),
            count($this->students), \App\Models\Loan::count(),
        ));
    }

    /**
     * @return array<string, StorageLocation>
     */
    private function seedLocations(): array
    {
        $rows = [
            ['name' => 'EE Lab 1 – Rack A', 'department' => 'Electronics Engineering', 'laboratory' => 'Electronics Lab 1', 'room' => 'EE-101', 'cupboard' => 'Rack A'],
            ['name' => 'EE Lab 1 – Rack B', 'department' => 'Electronics Engineering', 'laboratory' => 'Electronics Lab 1', 'room' => 'EE-101', 'cupboard' => 'Rack B'],
            ['name' => 'Power Systems Lab – Cupboard 1', 'department' => 'Electronics Engineering', 'laboratory' => 'Power Systems Lab', 'room' => 'EE-204', 'cupboard' => 'Cupboard 1'],
            ['name' => 'Networking Lab – Cupboard 3', 'department' => 'Computer Science', 'laboratory' => 'Networking Lab', 'room' => 'CS-310', 'cupboard' => 'Cupboard 3'],
            ['name' => 'Central Workshop – Tool Crib', 'department' => 'Mechanical Engineering', 'laboratory' => 'Central Workshop', 'room' => 'ME-02', 'cupboard' => 'Tool Crib'],
            ['name' => 'AV Store – Shelf 9', 'department' => 'Central Stores', 'laboratory' => 'Equipment Bay', 'room' => 'ST-01', 'cupboard' => 'Shelf 9', 'active' => false],
        ];

        $locations = [];
        foreach ($rows as $row) {
            $locations[$row['name']] = StorageLocation::create($row + ['active' => $row['active'] ?? true]);
        }

        return $locations;
    }

    /**
     * @return array<int, User>
     */
    private function seedCoordinators(): array
    {
        $names = ['Priya Nair', 'Arjun Mehta', 'Kavya Iyer'];
        $coordinators = [];

        foreach ($names as $i => $name) {
            $coordinators[] = User::updateOrCreate(
                ['email' => 'coordinator'.($i + 2).'@stockroom.test'],
                [
                    'name' => $name,
                    'password' => 'Password123!',
                    'role' => 'coordinator',
                    'enrollment_number' => null,
                    'programme' => 'Staff',
                    'semester' => '-',
                    'contact' => '90000000'.($i + 1),
                ],
            );
        }

        // Fold in the account DevAccountsSeeder already created so callers can use either pool.
        $coordinators[] = User::where('email', 'coordinator@stockroom.test')->first();

        return $coordinators;
    }

    /**
     * @return array<int, User>
     */
    private function seedStudents(): array
    {
        $programmes = [
            ['code' => 'CSE', 'name' => 'B.Tech Computer Science'],
            ['code' => 'ECE', 'name' => 'B.Tech Electronics & Communication'],
            ['code' => 'EEE', 'name' => 'B.Tech Electrical Engineering'],
            ['code' => 'MECH', 'name' => 'B.Tech Mechanical Engineering'],
            ['code' => 'VLSI', 'name' => 'M.Tech VLSI Design'],
            ['code' => 'PHY', 'name' => 'B.Sc Physics'],
        ];

        $firstNames = ['Aarav', 'Vivaan', 'Aditi', 'Diya', 'Kabir', 'Anaya', 'Rohan', 'Ishita', 'Sai', 'Meera', 'Dev', 'Tara', 'Krish', 'Zara', 'Aryan', 'Niharika', 'Ved', 'Sanya', 'Reyansh', 'Myra', 'Yash', 'Pari', 'Arnav', 'Riya'];
        $lastNames = ['Sharma', 'Verma', 'Gupta', 'Nair', 'Reddy', 'Iyer', 'Kapoor', 'Joshi', 'Menon', 'Patel', 'Singh', 'Rao', 'Pillai', 'Bose', 'Chawla', 'Kulkarni', 'Mehta', 'Desai', 'Shah', 'Agarwal', 'Pandey', 'Khan', 'Thomas', 'Bhat'];

        $students = [];
        foreach ($firstNames as $i => $first) {
            $programme = $programmes[$i % count($programmes)];
            $semester = (string) (($i % 8) + 1);
            $year = 2022 + intdiv($i, 12);

            $students[] = User::create([
                'name' => "{$first} {$lastNames[$i]}",
                'email' => strtolower("{$first}.{$lastNames[$i]}{$i}@stockroom.test"),
                'password' => 'Password123!',
                'role' => 'student',
                'enrollment_number' => "{$year}-{$programme['code']}-".str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'programme' => $programme['name'],
                'semester' => $semester,
                'contact' => '98'.str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT),
            ]);
        }

        // Fold in the account DevAccountsSeeder already created.
        $students[] = User::where('email', 'student@stockroom.test')->first();

        return $students;
    }

    /**
     * @param  array<string, StorageLocation>  $locations
     * @return array{0: array<string, InventoryItem>, 1: array<string, InventoryItem>}
     */
    private function seedInventory(array $locations): array
    {
        $bulkRows = [
            ['code' => 'DEMO-RESIS-01', 'name' => 'Resistor Assortment Kit', 'category' => 'Components', 'make_model' => 'E12 Series, 600pcs', 'total_quantity' => 200, 'max_per_issue' => 20, 'max_renewals' => 2, 'location' => 'EE Lab 1 – Rack A'],
            ['code' => 'DEMO-BRD-01', 'name' => 'Breadboard', 'category' => 'Components', 'make_model' => '830 tie-point', 'total_quantity' => 60, 'max_per_issue' => 4, 'max_renewals' => 1, 'location' => 'EE Lab 1 – Rack A'],
            ['code' => 'DEMO-WIRE-01', 'name' => 'Jumper Wire Set', 'category' => 'Components', 'make_model' => 'M-M / M-F / F-F', 'total_quantity' => 100, 'max_per_issue' => 10, 'max_renewals' => 1, 'location' => 'EE Lab 1 – Rack B'],
            ['code' => 'DEMO-ARD-01', 'name' => 'Arduino Uno Starter Kit', 'category' => 'Electronics', 'make_model' => 'Uno R3', 'total_quantity' => 25, 'max_per_issue' => 2, 'max_renewals' => 2, 'location' => 'Networking Lab – Cupboard 3'],
            ['code' => 'DEMO-CBL-01', 'name' => 'HDMI Cable', 'category' => 'Cables', 'make_model' => '2m, v2.1', 'total_quantity' => 40, 'max_per_issue' => 3, 'max_renewals' => 1, 'location' => 'Networking Lab – Cupboard 3'],
            ['code' => 'DEMO-SFT-01', 'name' => 'Safety Goggles', 'category' => 'Safety', 'make_model' => 'ANSI Z87.1', 'total_quantity' => 0, 'max_per_issue' => 2, 'max_renewals' => 0, 'location' => 'Central Workshop – Tool Crib', 'retired' => true],
        ];

        $bulkItems = [];
        foreach ($bulkRows as $row) {
            $retired = $row['retired'] ?? false;
            $locationId = $locations[$row['location']]->id;
            unset($row['retired'], $row['location']);

            $item = InventoryItem::create([
                ...$row,
                'tracking_type' => 'bulk',
                'description' => "Demo stock for {$row['name']}.",
                'location_id' => $locationId,
                'active' => true,
            ]);

            if ($item->total_quantity > 0) {
                $this->movement($item, $this->admin(), 'initial_stock', $item->total_quantity, 'Opening stock (demo data)');
            }

            if ($retired) {
                $item->update(['active' => false]);
                $this->movement($item, $this->admin(), 'item_retired', 0, 'Discontinued line — replaced by lab-issued safety kits.');
            }

            $bulkItems[$row['code']] = $item->fresh();
        }

        $assetRows = [
            ['code' => 'DEMO-OSC-01', 'name' => 'Digital Oscilloscope', 'category' => 'Lab Equipment', 'make_model' => 'Rigol DS1054Z', 'count' => 5, 'max_per_issue' => 1, 'max_renewals' => 2, 'location' => 'Power Systems Lab – Cupboard 1'],
            ['code' => 'DEMO-DMM-01', 'name' => 'Digital Multimeter', 'category' => 'Lab Equipment', 'make_model' => 'Fluke 117', 'count' => 6, 'max_per_issue' => 1, 'max_renewals' => 2, 'location' => 'Power Systems Lab – Cupboard 1'],
            ['code' => 'DEMO-LAP-01', 'name' => 'Lab Laptop', 'category' => 'Computing', 'make_model' => 'Dell Latitude 5440', 'count' => 8, 'max_per_issue' => 1, 'max_renewals' => 1, 'location' => 'Networking Lab – Cupboard 3'],
            ['code' => 'DEMO-PSU-01', 'name' => 'Bench Power Supply', 'category' => 'Lab Equipment', 'make_model' => 'Keysight E36103B', 'count' => 4, 'max_per_issue' => 1, 'max_renewals' => 1, 'location' => 'EE Lab 1 – Rack B'],
            ['code' => 'DEMO-DRL-01', 'name' => 'Cordless Drill', 'category' => 'Tools', 'make_model' => 'Bosch GSR 120-Li', 'count' => 3, 'max_per_issue' => 1, 'max_renewals' => 1, 'location' => 'Central Workshop – Tool Crib'],
            ['code' => 'DEMO-PROJ-01', 'name' => 'Portable Projector', 'category' => 'AV Equipment', 'make_model' => 'Epson EB-X51', 'count' => 3, 'max_per_issue' => 1, 'max_renewals' => 1, 'location' => 'AV Store – Shelf 9'],
        ];

        $assetItems = [];
        foreach ($assetRows as $row) {
            $count = $row['count'];
            $locationId = $locations[$row['location']]->id;
            unset($row['count'], $row['location']);

            $item = InventoryItem::create([
                ...$row,
                'tracking_type' => 'asset',
                'description' => "Individually tracked {$row['name']} units.",
                'location_id' => $locationId,
                'total_quantity' => 0,
                'active' => true,
            ]);

            for ($i = 1; $i <= $count; $i++) {
                $asset = Asset::create([
                    'item_id' => $item->id,
                    'asset_tag' => "{$row['code']}-".str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                    'location_id' => $locationId,
                    'condition' => 'usable',
                    'status' => 'available',
                ]);
                $item->increment('total_quantity');
                $this->movement($item, $this->admin(), 'asset_added', 1, 'Asset registered (demo data)', $asset);
            }

            $assetItems[$row['code']] = $item->fresh();
        }

        return [$bulkItems, $assetItems];
    }

    /**
     * @param  array<string, InventoryItem>  $items
     */
    private function seedBulkLoans(array $items): void
    {
        $purposes = [
            'Semester lab practical', 'Final year project', 'Hackathon prototype', 'Workshop assignment',
            'Research demonstration', 'Robotics club build', 'Capstone project', 'Circuit design practical',
        ];

        /**
         * Each row drives one loan: how many days ago it was issued (which
         * determines whether it reads as overdue, due soon, or comfortably
         * active), and what happens to it afterwards.
         */
        $plan = [
            ['item' => 'DEMO-RESIS-01', 'qty' => 15, 'daysAgo' => 2, 'then' => 'none'],
            ['item' => 'DEMO-BRD-01', 'qty' => 3, 'daysAgo' => 3, 'then' => 'none'],
            ['item' => 'DEMO-WIRE-01', 'qty' => 8, 'daysAgo' => 11, 'then' => 'none'],
            ['item' => 'DEMO-ARD-01', 'qty' => 2, 'daysAgo' => 12, 'then' => 'none'],
            ['item' => 'DEMO-CBL-01', 'qty' => 2, 'daysAgo' => 25, 'then' => 'none'],
            ['item' => 'DEMO-RESIS-01', 'qty' => 10, 'daysAgo' => 22, 'then' => 'none'],
            ['item' => 'DEMO-WIRE-01', 'qty' => 6, 'daysAgo' => 13, 'then' => 'renew_pending'],
            ['item' => 'DEMO-ARD-01', 'qty' => 1, 'daysAgo' => 13, 'then' => 'renew_pending'],
            ['item' => 'DEMO-BRD-01', 'qty' => 2, 'daysAgo' => 13, 'then' => 'renew_approved'],
            ['item' => 'DEMO-CBL-01', 'qty' => 1, 'daysAgo' => 13, 'then' => 'renew_approved'],
            ['item' => 'DEMO-RESIS-01', 'qty' => 12, 'daysAgo' => 10, 'then' => 'renew_rejected'],
            ['item' => 'DEMO-WIRE-01', 'qty' => 4, 'daysAgo' => 10, 'then' => 'renew_rejected'],
            ['item' => 'DEMO-BRD-01', 'qty' => 2, 'daysAgo' => 20, 'then' => 'return_full_usable'],
            ['item' => 'DEMO-ARD-01', 'qty' => 1, 'daysAgo' => 18, 'then' => 'return_full_usable'],
            ['item' => 'DEMO-RESIS-01', 'qty' => 20, 'daysAgo' => 16, 'then' => 'return_full_usable'],
            ['item' => 'DEMO-CBL-01', 'qty' => 3, 'daysAgo' => 6, 'then' => 'return_partial_damaged'],
            ['item' => 'DEMO-WIRE-01', 'qty' => 5, 'daysAgo' => 7, 'then' => 'return_partial_lost'],
            ['item' => 'DEMO-ARD-01', 'qty' => 2, 'daysAgo' => 8, 'then' => 'return_partial_maintenance'],
            ['item' => 'DEMO-RESIS-01', 'qty' => 6, 'daysAgo' => 5, 'then' => 'return_partial_damaged'],
            ['item' => 'DEMO-BRD-01', 'qty' => 1, 'daysAgo' => 4, 'then' => 'return_partial_lost'],
        ];

        $service = app(BorrowingService::class);

        foreach ($plan as $i => $row) {
            $student = $this->students[$i % count($this->students)];
            $staff = $this->coordinators[$i % count($this->coordinators)];
            $purpose = $purposes[$i % count($purposes)];

            $loan = $service->issue($student, $staff, [
                'item_id' => $items[$row['item']]->id,
                'quantity' => $row['qty'],
                'purpose' => $purpose,
                'issue_date' => $this->today->copy()->subDays($row['daysAgo'])->toDateString(),
            ]);

            match ($row['then']) {
                'renew_pending' => $service->requestRenewal($loan, $student),
                'renew_approved' => $service->reviewRenewal(
                    $service->requestRenewal($loan, $student), $staff, 'approved', 'Extended for continued coursework.'
                ),
                'renew_rejected' => $service->reviewRenewal(
                    $service->requestRenewal($loan, $student), $staff, 'rejected', 'Item needed back for the next practical batch.'
                ),
                'return_full_usable' => $service->receiveReturn(
                    $loan->lines->first(), $staff, ['quantity' => $row['qty'], 'condition' => 'usable', 'remarks' => 'Returned complete and in good condition.']
                ),
                'return_partial_damaged' => $service->receiveReturn(
                    $loan->lines->first(), $staff, ['quantity' => max(1, intdiv($row['qty'], 2)), 'condition' => 'damaged', 'remarks' => 'Visible damage on part of the returned units.']
                ),
                'return_partial_lost' => $service->receiveReturn(
                    $loan->lines->first(), $staff, ['quantity' => 1, 'condition' => 'lost', 'remarks' => 'Student reported one unit missing.']
                ),
                'return_partial_maintenance' => $service->receiveReturn(
                    $loan->lines->first(), $staff, ['quantity' => 1, 'condition' => 'maintenance', 'remarks' => 'Needs recalibration before reissue.']
                ),
                default => null,
            };
        }
    }

    /**
     * @param  array<string, InventoryItem>  $items
     */
    private function seedAssetLoans(array $items): void
    {
        $purposes = ['Final year project', 'Lab practical', 'Research demonstration', 'Workshop repair job', 'Capstone project'];
        $service = app(BorrowingService::class);

        /**
         * One scenario per asset-tracked item: which asset index to touch,
         * how long ago it was issued, and what happens to it afterwards.
         */
        $plan = [
            ['item' => 'DEMO-OSC-01', 'asset' => 1, 'daysAgo' => 4, 'then' => 'none'],
            ['item' => 'DEMO-OSC-01', 'asset' => 2, 'daysAgo' => 18, 'then' => 'return_usable'],
            ['item' => 'DEMO-DMM-01', 'asset' => 1, 'daysAgo' => 12, 'then' => 'none'],
            ['item' => 'DEMO-DMM-01', 'asset' => 2, 'daysAgo' => 9, 'then' => 'return_damaged'],
            ['item' => 'DEMO-LAP-01', 'asset' => 1, 'daysAgo' => 3, 'then' => 'none'],
            ['item' => 'DEMO-LAP-01', 'asset' => 2, 'daysAgo' => 22, 'then' => 'none'],
            ['item' => 'DEMO-LAP-01', 'asset' => 3, 'daysAgo' => 16, 'then' => 'return_usable'],
            ['item' => 'DEMO-PSU-01', 'asset' => 1, 'daysAgo' => 7, 'then' => 'return_maintenance'],
            ['item' => 'DEMO-DRL-01', 'asset' => 1, 'daysAgo' => 13, 'then' => 'renew_approved'],
            ['item' => 'DEMO-PROJ-01', 'asset' => 1, 'daysAgo' => 10, 'then' => 'return_lost'],
        ];

        foreach ($plan as $i => $row) {
            $student = $this->students[($i + 7) % count($this->students)];
            $staff = $this->coordinators[$i % count($this->coordinators)];
            $purpose = $purposes[$i % count($purposes)];

            $asset = Asset::where('item_id', $items[$row['item']]->id)
                ->where('asset_tag', "{$row['item']}-".str_pad((string) $row['asset'], 3, '0', STR_PAD_LEFT))
                ->firstOrFail();

            $loan = $service->issue($student, $staff, [
                'item_id' => $items[$row['item']]->id,
                'asset_id' => $asset->id,
                'quantity' => 1,
                'purpose' => $purpose,
                'issue_date' => $this->today->copy()->subDays($row['daysAgo'])->toDateString(),
            ]);

            match ($row['then']) {
                'return_usable' => $service->receiveReturn(
                    $loan->lines->first(), $staff, ['quantity' => 1, 'condition' => 'usable', 'remarks' => 'Checked and returned in working order.']
                ),
                'return_damaged' => $service->receiveReturn(
                    $loan->lines->first(), $staff, ['quantity' => 1, 'condition' => 'damaged', 'remarks' => 'Cracked display, needs repair.']
                ),
                'return_maintenance' => $service->receiveReturn(
                    $loan->lines->first(), $staff, ['quantity' => 1, 'condition' => 'maintenance', 'remarks' => 'Due for annual calibration.']
                ),
                'return_lost' => $service->receiveReturn(
                    $loan->lines->first(), $staff, ['quantity' => 1, 'condition' => 'lost', 'remarks' => 'Not returned after the workshop event; reported missing.']
                ),
                'renew_approved' => $service->reviewRenewal(
                    $service->requestRenewal($loan, $student), $staff, 'approved', 'Repair job running longer than expected.'
                ),
                default => null,
            };
        }

        // One asset taken fully out of service, independent of any loan, for variety in the asset register.
        $retiredAsset = Asset::where('item_id', $items['DEMO-DRL-01']->id)
            ->where('asset_tag', 'DEMO-DRL-01-003')->firstOrFail();
        $retiredAsset->update(['condition' => 'damaged', 'status' => 'retired']);
        $items['DEMO-DRL-01']->decrement('total_quantity');
        $this->movement($items['DEMO-DRL-01'], $this->coordinators[0], 'asset_status_changed', 0, 'Housing cracked beyond repair — retired from the pool.', $retiredAsset);
    }

    /**
     * @param  array<string, InventoryItem>  $items
     */
    private function seedStockAdjustments(array $items): void
    {
        $staff = $this->coordinators[0];

        $item = $items['DEMO-WIRE-01']->fresh();
        $item->increment('total_quantity', 25);
        $this->movement($item, $staff, 'stock_add', 25, 'Replenished from central stores.');

        $item = $items['DEMO-RESIS-01']->fresh();
        if ($item->damaged_quantity > 0) {
            $recover = min(2, $item->damaged_quantity);
            $item->decrement('damaged_quantity', $recover);
            $this->movement($item, $staff, 'stock_restore_damaged', 0, 'Recovered after inspection — usable for non-critical practicals.');
        }
    }

    private function movement(InventoryItem $item, User $user, string $type, int $quantity, string $reason, ?Asset $asset = null): void
    {
        InventoryMovement::create([
            'item_id' => $item->id,
            'asset_id' => $asset?->id,
            'user_id' => $user->id,
            'type' => $type,
            'quantity' => $quantity,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::where('role', 'admin')->firstOrFail();
    }
}
