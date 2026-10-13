<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_registration_always_creates_a_student_even_if_role_is_submitted(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Riya Student',
            'email' => 'riya@example.edu',
            'enrollment_number' => 'DFIS-001',
            'programme' => 'M.Sc. DFIS',
            'semester' => 'I',
            'contact' => '9000000000',
            'password' => 'SafePassword123',
            'password_confirmation' => 'SafePassword123',
            'role' => 'admin',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', ['email' => 'riya@example.edu', 'role' => 'student']);
    }

    public function test_students_are_blocked_from_staff_inventory_and_access_control_routes(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student)->get(route('inventory.index'))->assertForbidden();
        $this->actingAs($student)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_coordinators_cannot_grant_themselves_administrator_access(): void
    {
        $coordinator = User::factory()->create(['role' => 'coordinator']);

        $this->actingAs($coordinator)
            ->put(route('admin.users.role', $coordinator), ['role' => 'admin'])
            ->assertForbidden();
        $this->assertSame('coordinator', $coordinator->fresh()->role);
    }

    public function test_administrator_can_assign_a_coordinator_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($admin)
            ->put(route('admin.users.role', $student), ['role' => 'coordinator'])
            ->assertRedirect();
        $this->assertSame('coordinator', $student->fresh()->role);
    }

    public function test_role_specific_workspaces_render(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($student)->get(route('dashboard'))->assertOk();
        $this->actingAs($student)->get(route('my-loans'))->assertOk();
        $this->actingAs($coordinator)->get(route('dashboard'))->assertOk();
        $this->actingAs($coordinator)->get(route('inventory.index'))->assertOk();
        $this->actingAs($coordinator)->get(route('loans.index'))->assertOk();
        $this->actingAs($coordinator)->get(route('renewals.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
    }

    public function test_retiring_an_asset_removes_it_from_total_stock_only_once(): void
    {
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $item = InventoryItem::create([
            'code' => 'ASSET-SET', 'name' => 'Development board', 'category' => 'Board',
            'tracking_type' => 'asset', 'total_quantity' => 1,
        ]);
        $asset = $item->assets()->create(['asset_tag' => 'DB-020', 'condition' => 'usable', 'status' => 'available']);

        $this->actingAs($coordinator)->post(route('assets.condition', $asset), [
            'condition' => 'usable', 'status' => 'retired', 'reason' => 'End of service life',
        ])->assertRedirect();
        $this->assertSame(0, $item->fresh()->total_quantity);

        $this->post(route('assets.condition', $asset), [
            'condition' => 'usable', 'status' => 'retired', 'reason' => 'Confirmed retirement',
        ])->assertRedirect();
        $this->assertSame(0, $item->fresh()->total_quantity);
    }

    public function test_transaction_export_includes_stock_movements_and_neutralizes_formula_values(): void
    {
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $item = InventoryItem::create([
            'code' => 'USB-CSV', 'name' => 'USB cable', 'category' => 'Cable',
            'tracking_type' => 'bulk', 'total_quantity' => 5,
        ]);
        InventoryMovement::create([
            'item_id' => $item->id,
            'user_id' => $coordinator->id,
            'type' => 'stock_add',
            'quantity' => 5,
            'reason' => '=HYPERLINK("https://example.invalid")',
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($coordinator)->get(route('loans.export'));
        $response->assertOk();
        $this->assertStringContainsString('USB cable', $response->streamedContent());
        $this->assertStringContainsString('Stock Add', $response->streamedContent());
        $this->assertStringContainsString("'=HYPERLINK", $response->streamedContent());
    }

    public function test_coordinator_can_render_and_retire_an_inventory_item_with_a_reason(): void
    {
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $item = InventoryItem::create([
            'code' => 'CABLE-20', 'name' => 'USB cable', 'category' => 'Cable',
            'tracking_type' => 'bulk', 'total_quantity' => 20,
        ]);

        $this->actingAs($coordinator)->get(route('inventory.index'))
            ->assertOk()->assertSee('USB cable')->assertSee('Retire item');

        $this->post(route('inventory.status', $item), ['active' => 0, 'reason' => 'Model discontinued'])
            ->assertRedirect();

        $this->assertFalse($item->fresh()->active);
        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $item->id,
            'user_id' => $coordinator->id,
            'type' => 'item_retired',
            'reason' => 'Model discontinued',
        ]);
    }
}
