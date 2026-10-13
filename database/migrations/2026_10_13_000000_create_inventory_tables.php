<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('department')->nullable();
            $table->string('laboratory')->nullable();
            $table->string('room')->nullable();
            $table->string('cupboard')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category');
            $table->text('description')->nullable();
            $table->string('make_model')->nullable();
            $table->string('tracking_type')->default('bulk');
            $table->unsignedInteger('total_quantity')->default(0);
            $table->unsignedInteger('damaged_quantity')->default(0);
            $table->unsignedInteger('lost_quantity')->default(0);
            $table->unsignedInteger('maintenance_quantity')->default(0);
            $table->unsignedInteger('max_per_issue')->default(10);
            $table->unsignedInteger('max_renewals')->default(2);
            $table->foreignId('location_id')->nullable()->constrained('storage_locations')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('asset_tag')->unique();
            $table->string('serial_number')->nullable()->unique();
            $table->foreignId('location_id')->nullable()->constrained('storage_locations')->restrictOnDelete();
            $table->string('condition')->default('usable');
            $table->string('status')->default('available');
            $table->timestamps();
        });

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('purpose');
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->index(['student_id', 'status']);
            $table->index(['status', 'due_date']);
        });

        Schema::create('loan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('outstanding_quantity');
            $table->timestamps();
            $table->index(['item_id', 'outstanding_quantity']);
        });

        Schema::create('item_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_line_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('condition');
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('renewal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->string('status')->default('pending')->index();
            $table->date('previous_due_date');
            $table->date('revised_due_date')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->restrictOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type');
            $table->integer('quantity')->default(0);
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['item_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('renewal_requests');
        Schema::dropIfExists('item_returns');
        Schema::dropIfExists('loan_lines');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('storage_locations');
    }
};
