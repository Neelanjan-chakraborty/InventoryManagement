<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Department Inventory') | Stockroom</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @auth
        <div class="app-shell">
            <aside class="sidebar">
                <a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark">S</span><span>STOCKROOM<small>DEPARTMENT INVENTORY</small></span></a>
                <div class="nav-label">WORKSPACE</div>
                <nav class="primary-nav">
                    <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}"><span>◫</span> Overview</a>
                    @if(auth()->user()->hasRole('coordinator', 'admin'))
                        <a href="{{ route('inventory.index') }}" class="{{ request()->routeIs('inventory.*') ? 'active' : '' }}"><span>▤</span> Inventory</a>
                        <a href="{{ route('loans.index') }}" class="{{ request()->routeIs('loans.*') ? 'active' : '' }}"><span>↗</span> Issue desk</a>
                        <a href="{{ route('renewals.index') }}" class="{{ request()->routeIs('renewals.*') ? 'active' : '' }}"><span>⟳</span> Renewals</a>
                    @else
                        <a href="{{ route('my-loans') }}" class="{{ request()->routeIs('my-loans') ? 'active' : '' }}"><span>▤</span> My items</a>
                    @endif
                    @if(auth()->user()->hasRole('admin'))
                        <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}"><span>♙</span> Access control</a>
                    @endif
                </nav>
                <div class="sidebar-bottom">
                    <div class="user-chip"><span class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span><span>{{ auth()->user()->name }}<small>{{ ucfirst(auth()->user()->role) }}</small></span></div>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-logout" type="submit">↪ <span>Sign out</span></button></form>
                </div>
            </aside>
            <main class="main-area">
                <header class="topbar"><span>Department services <span class="slash">/</span> Inventory</span><span class="topbar-date">{{ now()->format('D, d M Y') }}</span></header>
                <div class="page-content">
                    @if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
                    @if(session('error')) <div class="alert alert-error">{{ session('error') }}</div> @endif
                    @yield('content')
                </div>
            </main>
        </div>
    @else
        <main class="auth-page">
            <div class="auth-art">
                <a class="brand brand-light" href="{{ url('/') }}"><span class="brand-mark">S</span><span>STOCKROOM<small>DEPARTMENT INVENTORY</small></span></a>
                <div class="auth-art-copy"><span class="eyebrow">DEPARTMENT SERVICES</span><h1>Equipment, accounted for.</h1><p>One place for every issue, return, renewal and storeroom movement.</p></div>
                <div class="auth-art-foot">INVENTORY OPERATIONS <span>·</span> {{ now()->format('Y') }}</div>
            </div>
            <div class="auth-panel">@yield('content')</div>
        </main>
    @endauth
</body>
</html>
