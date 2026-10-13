@extends('layouts.app')

@section('title', 'Access control')

@section('content')
<div class="page-heading"><div><span class="eyebrow">ADMINISTRATION</span><h1>Access control</h1><p>Assign staff access only to trusted, registered accounts.</p></div><span class="pill">{{ $users->total() }} accounts</span></div>
@if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif
<section class="section"><div class="section-head"><h2>Accounts and roles</h2><span class="table-sub">Self-demotion and removal of the last admin are blocked.</span></div>
    @if($users->isEmpty())<div class="empty-state">No accounts registered.</div>@else
    <div class="table-wrap"><table><thead><tr><th>Account</th><th>Student details</th><th>Current role</th><th>Change access</th></tr></thead><tbody>
        @foreach($users as $user)
            <tr><td><strong>{{ $user->name }}</strong><span class="table-sub">{{ $user->email }}</span></td><td>{{ $user->enrollment_number ?? 'Staff account' }}<span class="table-sub">{{ $user->programme }}{{ $user->semester ? ' · Semester '.$user->semester : '' }}</span></td><td><span class="pill">{{ ucfirst($user->role) }}</span></td><td>
                <form method="POST" action="{{ route('admin.users.role', $user) }}" class="inline-form">@csrf @method('PUT')
                    <label class="sr-only" for="role-{{ $user->id }}">Role for {{ $user->name }}</label><select id="role-{{ $user->id }}" name="role"><option value="student" @selected($user->role === 'student')>Student</option><option value="coordinator" @selected($user->role === 'coordinator')>Coordinator</option><option value="admin" @selected($user->role === 'admin')>Admin</option></select><button class="button button-secondary button-small" type="submit">Save role</button>
                </form>
            </td></tr>
        @endforeach
    </tbody></table></div><div class="pagination">{{ $users->links() }}</div>@endif
</section>
@endsection
