<nav id="sidebar" class="sidebar js-sidebar">
    <div class="sidebar-content js-simplebar">
        <a class="sidebar-brand" href="index.html">
					<span class="sidebar-brand-text align-middle">
						AdminKit
						<sup><small class="badge bg-primary text-uppercase">Pro</small></sup>
					</span>
            <svg class="sidebar-brand-icon align-middle" width="32px" height="32px" viewBox="0 0 24 24" fill="none"
                 stroke="#FFFFFF" stroke-width="1.5"
                 stroke-linecap="square" stroke-linejoin="miter" color="#FFFFFF" style="margin-left: -3px">
                <path d="M12 4L20 8.00004L12 12L4 8.00004L12 4Z"></path>
                <path d="M20 12L12 16L4 12"></path>
                <path d="M20 16L12 20L4 16"></path>
            </svg>
        </a>

        <div class="sidebar-user">
            <div class="d-flex justify-content-center">
                <div class="flex-shrink-0">
                    <img src="{{asset('backend/img/avatars/logo.png')}}" class="avatar img-fluid rounded me-1"
                         alt="{{ Auth::user()->name }}"/>
                </div>
                <div class="flex-grow-1 ps-2">
                    <a class="sidebar-user-title dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        {{ Auth::user()->name }}
                    </a>
                    <div class="dropdown-menu dropdown-menu-start">

                        <a href="{{ route('logout') }}" onclick="event.preventDefault();
                        document.getElementById('logout-form').submit();"
                           class="dropdown-item"><i class="icon-switch2"></i> Logout</a>
                        <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                            @csrf
                        </form>
                    </div>

                    <div class="sidebar-user-subtitle">
                        {{ Auth::user()->roles->pluck('name')->first() }}
                    </div>
                </div>
            </div>
        </div>

        <ul class="sidebar-nav">
            <li class="sidebar-header">
                Pages
            </li>

            <li class="sidebar-item {{ request()->routeIs('our-dashboard') ? 'active' : '' }}">
                <a class="sidebar-link" href="{{ route('dashboard') }}">
                    <i class="align-middle" data-feather="user"></i>
                    <span class="align-middle">Dashboard</span>
                </a>
            </li>


            @can('view-roles')

                <li class="sidebar-item {{ request()->routeIs('show-role') ? 'active' : '' }}">
                    <a class="sidebar-link" href="{{ route('show-role') }}">
                        <i class="align-middle" data-feather="user"></i>
                        <span class="align-middle">Role</span>
                    </a>
                </li>

            @endcan

            @can('view-users')

                <li class="sidebar-item {{ request()->routeIs('show-user') ? 'active' : '' }}">
                    <a class="sidebar-link" href="{{ route('show-user') }}">
                        <i class="align-middle" data-feather="user"></i>
                        <span class="align-middle">User</span>
                    </a>
                </li>
            @endcan

            @can('view-loan-management')

                <li class="sidebar-item  ">
                    <a class="sidebar-link" href="{{ route('dashboard') }}">
                        <i class="fas fa-bank"></i>
                        <span class="align-middle">Loan</span>
                    </a>
                </li>
            @endcan
        </ul>
    </div>
</nav>
