<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserAccessController extends Controller
{
    public function index()
    {
        return view('admin.users.index', [
            'users' => User::query()->orderBy('role')->orderBy('name')->paginate(30),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate(['role' => ['required', Rule::in(['student', 'coordinator', 'admin'])]]);

        if ($user->is($request->user()) && $data['role'] !== 'admin') {
            throw ValidationException::withMessages(['role' => 'You cannot remove your own administrator access.']);
        }

        if ($user->role === 'admin' && $data['role'] !== 'admin'
            && User::query()->where('role', 'admin')->whereKeyNot($user->id)->doesntExist()) {
            throw ValidationException::withMessages(['role' => 'At least one administrator account must remain.']);
        }

        $user->update(['role' => $data['role']]);

        return back()->with('success', 'Account access role updated.');
    }
}
