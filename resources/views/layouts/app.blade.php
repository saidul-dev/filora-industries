<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Business ERP') }} — {{ $title ?? __('Dashboard') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased bg-slate-100" x-data="{ sidebarOpen: false }">
    <div class="min-h-screen">

        <!-- Mobile sidebar backdrop -->
        <div x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 z-30 bg-brand-950/60 lg:hidden"
            @click="sidebarOpen = false" style="display: none;"></div>

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-40 w-64 bg-gradient-to-b from-brand-900 via-brand-900 to-brand-950 text-brand-100 flex flex-col transform transition-all duration-200 lg:translate-x-0"
            :class="[sidebarOpen ? 'translate-x-0' : '-translate-x-full', $store.sidebar.collapsed ? 'lg:w-20' : 'lg:w-64']">

            <!-- Collapse/expand toggle (desktop only) -->
            <button type="button" @click="$store.sidebar.toggle()" title="Collapse or expand the sidebar"
                class="hidden lg:grid absolute -right-3 top-5 h-6 w-6 place-items-center rounded-full bg-brand-700 text-white ring-2 ring-brand-950 hover:bg-accent-500 z-10">
                <svg class="h-3.5 w-3.5 transition-transform duration-200"
                    :class="$store.sidebar.collapsed && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2.5"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                </svg>
            </button>

            <!-- Brand -->
            <a href="{{ route('dashboard') }}"
                class="flex items-center gap-3 px-5 h-16 border-b border-white/10 shrink-0"
                :class="$store.sidebar.collapsed && 'lg:justify-center lg:px-0'">
                <x-application-logo class="h-9 w-9 shrink-0" />
                <div class="leading-tight nav-label" :class="$store.sidebar.collapsed && 'lg:hidden'">
                    <span class="block text-white font-bold tracking-wide">{{ __('Business ERP') }}</span>
                    <span class="block text-[11px] text-accent-400 font-medium">{{ __('Enterprise Suite') }}</span>
                </div>
            </a>

            <!-- Navigation -->
            <nav class="sidebar-scroll flex-1 overflow-y-auto overflow-x-hidden px-3 py-4 space-y-6">
                <div>
                    <p class="nav-label px-3 mb-2 text-[11px] font-semibold uppercase tracking-wider text-brand-300/70"
                        :class="$store.sidebar.collapsed && 'lg:hidden'">{{ __('Main') }}</p>
                    <x-sidebar-link :href="route('dashboard')" :active="request()->routeIs('dashboard')"
                        :title="__('Dashboard')">
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
                        </svg>
                        <span class="nav-label" :class="$store.sidebar.collapsed && 'lg:hidden'">{{ __('Dashboard')
                            }}</span>
                    </x-sidebar-link>
                </div>

                <div>
                    <p class="nav-label px-3 mb-2 text-[11px] font-semibold uppercase tracking-wider text-brand-300/70"
                        :class="$store.sidebar.collapsed && 'lg:hidden'">{{ __('Operations') }}</p>

                    @can('sales.view')
                    <x-sidebar-dropdown :title="__('Sales')" :active="request()->routeIs('sales-orders.*')">
                        <x-slot name="icon">
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                            </svg>
                        </x-slot>

                        <x-sidebar-sublink :href="route('sales-orders.index')" :active="request()->routeIs('sales-orders.*')">
                            {{ __('Sales Orders') }}
                        </x-sidebar-sublink>
                    </x-sidebar-dropdown>
                    @endcan

                    @can('inventory.view')
                    <x-sidebar-dropdown :title="__('Inventory')"
                        :active="request()->routeIs('products.*', 'categories.*', 'brands.*', 'units.*', 'attributes.*', 'stock.*')">
                        <x-slot name="icon">
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                            </svg>
                        </x-slot>

                        <x-sidebar-sublink :href="route('products.index')" :active="request()->routeIs('products.*')">
                            {{ __('Products') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('stock.report')" :active="request()->routeIs('stock.report')">
                            {{ __('Stock Report') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('stock.initial.index')"
                            :active="request()->routeIs('stock.initial.*')">
                            {{ __('Initial Stock') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('stock.adjustment.index')"
                            :active="request()->routeIs('stock.adjustment.*')">
                            {{ __('Stock Adjustment') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('stock.internal-consumption.index')"
                            :active="request()->routeIs('stock.internal-consumption.*')">
                            {{ __('Internal Consumption') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('stock.transfers.index')"
                            :active="request()->routeIs('stock.transfers.*')">
                            {{ __('Stock Transfer') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('categories.index')"
                            :active="request()->routeIs('categories.*')">
                            {{ __('Categories') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('brands.index')" :active="request()->routeIs('brands.*')">
                            {{ __('Brands') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('units.index')" :active="request()->routeIs('units.*')">
                            {{ __('Units') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('attributes.index')"
                            :active="request()->routeIs('attributes.*')">
                            {{ __('Attributes') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('products.barcode')"
                            :active="request()->routeIs('products.barcode')">
                            {{ __('Barcode') }}
                        </x-sidebar-sublink>
                    </x-sidebar-dropdown>
                    @endcan

                    @can('production.view')
                    <x-sidebar-dropdown :title="__('Production')" :active="request()->routeIs('bom.*')">
                        <x-slot name="icon">
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z" />
                            </svg>
                        </x-slot>

                        <x-sidebar-sublink :href="route('bom.index')" :active="request()->routeIs('bom.*')">
                            {{ __('Bill of Materials') }}
                        </x-sidebar-sublink>
                    </x-sidebar-dropdown>
                    @endcan

                    @can('parties.view')
                    <x-sidebar-link :href="route('parties.index')" :active="request()->routeIs('parties.*')"
                        :title="__('Parties')">
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                        </svg>
                        <span class="nav-label" :class="$store.sidebar.collapsed && 'lg:hidden'">{{ __('Parties')
                            }}</span>
                    </x-sidebar-link>
                    @endcan

                    @can('accounts.view')
                    <x-sidebar-dropdown :title="__('Accounts')"
                        :active="request()->routeIs('bank-accounts.*', 'payments.*', 'collections.*', 'expenses.*', 'incomes.*', 'fund-transfers.*', 'capital-transactions.*', 'day-book.*', 'trial-balance.*', 'balance-sheet.*', 'profit-loss.*', 'due-report.*')">
                        <x-slot name="icon">
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                        </x-slot>

                        <x-sidebar-sublink :href="route('bank-accounts.index')"
                            :active="request()->routeIs('bank-accounts.*')">
                            {{ __('Bank Accounts') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('payments.index')" :active="request()->routeIs('payments.*')">
                            {{ __('Payments') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('collections.index')"
                            :active="request()->routeIs('collections.*')">
                            {{ __('Collections') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('expenses.index')" :active="request()->routeIs('expenses.*')">
                            {{ __('Expenses') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('incomes.index')" :active="request()->routeIs('incomes.*')">
                            {{ __('Income') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('fund-transfers.index')"
                            :active="request()->routeIs('fund-transfers.*')">
                            {{ __('Fund Transfer') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('capital-transactions.index')"
                            :active="request()->routeIs('capital-transactions.*')">
                            {{ __('Investment') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('day-book.index')" :active="request()->routeIs('day-book.*')">
                            {{ __('Day Book') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('trial-balance.index')"
                            :active="request()->routeIs('trial-balance.*')">
                            {{ __('Trial Balance') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('balance-sheet.index')"
                            :active="request()->routeIs('balance-sheet.*')">
                            {{ __('Balance Sheet') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('profit-loss.index')"
                            :active="request()->routeIs('profit-loss.*')">
                            {{ __('Profit & Loss') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('due-report.index')"
                            :active="request()->routeIs('due-report.*')">
                            {{ __('Due Report') }}
                        </x-sidebar-sublink>
                    </x-sidebar-dropdown>
                    @endcan

                    @can('tasks.view')
                    <x-sidebar-dropdown :title="__('Tasks')" :active="request()->routeIs('projects.*', 'tasks.*')">
                        <x-slot name="icon">
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </x-slot>

                        @can('projects.create')
                        <x-sidebar-sublink :href="route('projects.create')" :active="request()->routeIs('projects.create')">
                            {{ __('New Project') }}
                        </x-sidebar-sublink>
                        @endcan

                        @can('projects.view')
                        <x-sidebar-sublink :href="route('projects.index')"
                            :active="request()->routeIs('projects.index', 'projects.show', 'projects.edit')">
                            {{ __('Project List') }}
                        </x-sidebar-sublink>
                        @endcan

                        <x-sidebar-sublink :href="route('tasks.index')" :active="request()->routeIs('tasks.*')">
                            {{ __('Tasks') }}
                        </x-sidebar-sublink>
                    </x-sidebar-dropdown>
                    @endcan

                    @can('hrm.view')
                    <x-sidebar-dropdown :title="__('HRM')"
                        :active="request()->routeIs('employees.*', 'departments.*', 'designations.*', 'attendance.*', 'leave-types.*', 'leave-requests.*', 'payroll-runs.*')">
                        <x-slot name="icon">
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0M12 12.75h.008v.008H12v-.008Z" />
                            </svg>
                        </x-slot>

                        <x-sidebar-sublink :href="route('employees.index')" :active="request()->routeIs('employees.*')">
                            {{ __('Employees') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('departments.index')" :active="request()->routeIs('departments.*')">
                            {{ __('Departments') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('designations.index')" :active="request()->routeIs('designations.*')">
                            {{ __('Designations') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('attendance.register')" :active="request()->routeIs('attendance.register')">
                            {{ __('Attendance Register') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('attendance.summary')" :active="request()->routeIs('attendance.summary')">
                            {{ __('Attendance Summary') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('leave-types.index')" :active="request()->routeIs('leave-types.*')">
                            {{ __('Leave Types') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('leave-requests.index')" :active="request()->routeIs('leave-requests.*')">
                            {{ __('Leave Requests') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('payroll-runs.index')" :active="request()->routeIs('payroll-runs.*')">
                            {{ __('Payroll Runs') }}
                        </x-sidebar-sublink>
                    </x-sidebar-dropdown>
                    @endcan

                    @if (auth()->user()->employee)
                    <x-sidebar-link :href="route('attendance.my')" :title="__('My Attendance')"
                        :active="request()->routeIs('attendance.my')">
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 6v6l4 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        <span class="nav-label" :class="$store.sidebar.collapsed && 'lg:hidden'">{{ __('My Attendance') }}</span>
                    </x-sidebar-link>
                    @endif

                    @can('reports.view')
                    <x-sidebar-link href="#" :title="__('Reports')">
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                        </svg>
                        <span class="nav-label" :class="$store.sidebar.collapsed && 'lg:hidden'">{{ __('Reports')
                            }}</span>
                    </x-sidebar-link>
                    @endcan
                </div>

                @canany(['users.view', 'roles.view', 'settings.view', 'sites.view', 'website.view'])
                <div>
                    <p class="nav-label px-3 mb-2 text-[11px] font-semibold uppercase tracking-wider text-brand-300/70"
                        :class="$store.sidebar.collapsed && 'lg:hidden'">{{ __('Administration') }}</p>

                    @can('sites.view')
                    <x-sidebar-link :href="route('sites.index')" :active="request()->routeIs('sites.*')"
                        :title="__('Sites')">
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6 21v-3.375c0-.621.504-1.125 1.125-1.125h1.75c.621 0 1.125.504 1.125 1.125V21" />
                        </svg>
                        <span class="nav-label" :class="$store.sidebar.collapsed && 'lg:hidden'">{{ __('Sites')
                            }}</span>
                    </x-sidebar-link>
                    @endcan

                    @can('website.view')
                    <x-sidebar-link :href="route('contact-messages.index')"
                        :active="request()->routeIs('contact-messages.*')" :title="__('Contact Messages')">
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                        </svg>
                        <span class="nav-label flex flex-1 items-center justify-between"
                            :class="$store.sidebar.collapsed && 'lg:hidden'">
                            {{ __('Contact Messages') }}
                            @php $unreadContactCount = \App\Models\ContactMessage::where('is_read', false)->count();
                            @endphp
                            @if ($unreadContactCount > 0)
                            <span
                                class="ml-2 rounded-full bg-accent-500 px-2 py-0.5 text-[11px] font-bold text-brand-950">{{
                                $unreadContactCount }}</span>
                            @endif
                        </span>
                    </x-sidebar-link>
                    @endcan

                    @canany(['users.view', 'roles.view'])
                    <x-sidebar-dropdown :title="__('User Management')"
                        :active="request()->routeIs('users.*') || request()->routeIs('roles.*')">
                        <x-slot name="icon">
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                        </x-slot>

                        @can('users.view')
                        <x-sidebar-sublink :href="route('users.index')" :active="request()->routeIs('users.*')">
                            {{ __('Users') }}
                        </x-sidebar-sublink>
                        @endcan

                        @can('roles.view')
                        <x-sidebar-sublink :href="route('roles.index')" :active="request()->routeIs('roles.*')">
                            {{ __('Roles & Permissions') }}
                        </x-sidebar-sublink>
                        @endcan
                    </x-sidebar-dropdown>
                    @endcanany

                    @can('settings.view')
                    <x-sidebar-dropdown :title="__('Settings')" :active="request()->routeIs('settings.*')">
                        <x-slot name="icon">
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                        </x-slot>

                        <x-sidebar-sublink :href="route('settings.edit')" :active="request()->routeIs('settings.edit')">
                            {{ __('General Settings') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('settings.website.edit')"
                            :active="request()->routeIs('settings.website.*')">
                            {{ __('Website Settings') }}
                        </x-sidebar-sublink>
                        <x-sidebar-sublink :href="route('settings.attendance.edit')"
                            :active="request()->routeIs('settings.attendance.*')">
                            {{ __('Attendance Settings') }}
                        </x-sidebar-sublink>
                    </x-sidebar-dropdown>
                    @endcan
                </div>
                @endcanany
            </nav>

            <!-- Sidebar footer -->
            <div class="nav-label px-5 py-4 border-t border-white/10 text-[11px] text-brand-300/60 shrink-0"
                :class="$store.sidebar.collapsed && 'lg:hidden'">
                {{ __('v0.1 · Phase 1 — Sellable Core') }}
            </div>
        </aside>

        <!-- Main column -->
        <div class="flex flex-col min-h-screen transition-all duration-200"
            :class="$store.sidebar.collapsed ? 'lg:pl-20' : 'lg:pl-64'">

            <!-- Topbar -->
            <header
                class="sticky top-0 z-20 h-16 bg-white/90 backdrop-blur border-b border-slate-200 flex items-center gap-2 sm:gap-4 px-3 sm:px-6">
                <button class="lg:hidden shrink-0 text-brand-800" @click="sidebarOpen = true">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>

                <div class="flex-1 min-w-0 hidden sm:block">
                    <div class="relative max-w-md">
                        <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400"
                            fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        <input type="search" placeholder="{{ __('Search invoices, customers, items…') }}"
                            class="w-full rounded-lg border-slate-200 bg-slate-50 pl-9 pr-4 py-2 text-sm focus:border-accent-500 focus:ring-accent-500 placeholder:text-slate-400">
                    </div>
                </div>

                <div class="ml-auto flex items-center gap-1.5 sm:gap-3 shrink-0">
                    <!-- Visit website -->
                    <a href="{{ route('home') }}" target="_blank" rel="noopener"
                        class="hidden sm:flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-brand-800 ring-1 ring-slate-200 hover:bg-slate-50">
                        <svg class="h-4 w-4 shrink-0 text-accent-600" fill="none" viewBox="0 0 24 24"
                            stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0 0c-2.485 0-4.5-4.03-4.5-9s2.015-9 4.5-9 4.5 4.03 4.5 9-2.015 9-4.5 9Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9h18M3 15h18" />
                        </svg>
                        <span>{{ __('Visit Website') }}</span>
                    </a>

                    <!-- Language selector -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open"
                            class="hidden sm:flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-brand-800 ring-1 ring-slate-200 hover:bg-slate-50">
                            <svg class="h-4 w-4 shrink-0 text-accent-600" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0 0c-2.485 0-4.5-4.03-4.5-9s2.015-9 4.5-9 4.5 4.03 4.5 9-2.015 9-4.5 9Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.6 9h16.8M3.6 15h16.8" />
                            </svg>
                            <span>{{ config('app.available_locales')[app()->getLocale()] }}</span>
                            <svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>

                        <div x-show="open" @click.outside="open = false" x-transition
                            class="absolute right-0 mt-2 w-40 rounded-xl bg-white py-1.5 shadow-lg ring-1 ring-slate-200"
                            style="display: none;">
                            @foreach (config('app.available_locales') as $localeCode => $localeName)
                            <form method="POST" action="{{ route('language.switch', $localeCode) }}">
                                @csrf
                                <button type="submit"
                                    class="flex w-full items-center justify-between px-4 py-2 text-sm hover:bg-slate-50
                                            {{ app()->getLocale() === $localeCode ? 'text-accent-600 font-semibold' : 'text-slate-700' }}">
                                    {{ $localeName }}
                                    @if (app()->getLocale() === $localeCode)
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2"
                                        stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                    @endif
                                </button>
                            </form>
                            @endforeach
                        </div>
                    </div>

                    @php
                    $siteSwitcherOptions = Auth::user()->seesAllSites()
                    ? \App\Models\Site::where('status', true)->orderBy('name')->get()
                    : Auth::user()->sites()->where('status', true)->orderBy('name')->get();
                    @endphp
                    @if ($siteSwitcherOptions->count() > 1 || Auth::user()->seesAllSites())
                    <!-- Site selector -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open"
                            class="hidden sm:flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-brand-800 ring-1 ring-slate-200 hover:bg-slate-50">
                            <svg class="h-4 w-4 shrink-0 text-accent-600" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                            </svg>
                            <span>{{ Auth::user()->currentSite->name ?? __('All Sites') }}</span>
                            <svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>

                        <div x-show="open" @click.outside="open = false" x-transition
                            class="absolute right-0 mt-2 w-56 rounded-xl bg-white py-1.5 shadow-lg ring-1 ring-slate-200"
                            style="display: none;">
                            <p class="px-4 pt-1 pb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                {{ __('Current Site') }}</p>
                            @foreach ($siteSwitcherOptions as $site)
                            <form method="POST" action="{{ route('sites.switch') }}">
                                @csrf
                                <input type="hidden" name="site_id" value="{{ $site->id }}">
                                <button type="submit"
                                    class="flex w-full items-center justify-between px-4 py-2 text-sm hover:bg-slate-50
                                            {{ Auth::user()->current_site_id === $site->id ? 'text-accent-600 font-semibold' : 'text-slate-700' }}">
                                    {{ $site->name }}
                                    @if (Auth::user()->current_site_id === $site->id)
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2"
                                        stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                    @endif
                                </button>
                            </form>
                            @endforeach

                            @if (Auth::user()->seesAllSites())
                            <div class="my-1.5 border-t border-slate-100"></div>
                            <form method="POST" action="{{ route('sites.switch') }}">
                                @csrf
                                <button type="submit"
                                    class="flex w-full items-center justify-between px-4 py-2 text-sm hover:bg-slate-50
                                            {{ Auth::user()->current_site_id === null ? 'text-accent-600 font-semibold' : 'text-slate-700' }}">
                                    {{ __('All Sites') }}
                                    @if (Auth::user()->current_site_id === null)
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2"
                                        stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                    @endif
                                </button>
                            </form>
                            @endif
                        </div>
                    </div>
                    @endif

                    @can('settings.view')
                    @php
                        $siteHealth = \App\Models\SiteHealthSnapshot::current();
                        $siteHealthColor = match (true) {
                            $siteHealth === null => 'text-slate-400 ring-slate-200 bg-slate-50',
                            $siteHealth->overall_score >= 90 => 'text-emerald-600 ring-emerald-200 bg-emerald-50',
                            $siteHealth->overall_score >= 70 => 'text-amber-600 ring-amber-200 bg-amber-50',
                            default => 'text-rose-600 ring-rose-200 bg-rose-50',
                        };
                    @endphp
                    <!-- Site health -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open"
                            class="hidden sm:flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold ring-1 hover:opacity-80 {{ $siteHealthColor }}">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                            </svg>
                            <span>{{ $siteHealth ? $siteHealth->overall_score.'%' : __('N/A') }}</span>
                        </button>

                        <div x-show="open" @click.outside="open = false" x-transition
                            class="absolute right-0 mt-2 w-72 rounded-xl bg-white py-3 shadow-lg ring-1 ring-slate-200"
                            style="display: none;">
                            <div class="flex items-center justify-between px-4 pb-2">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Site Health') }}</p>
                                @if ($siteHealth)
                                <span class="text-[11px] text-slate-400">{{ $siteHealth->computed_at->diffForHumans() }}</span>
                                @endif
                            </div>

                            @if ($siteHealth)
                            <div class="px-4 pb-3 space-y-2.5">
                                @foreach ([
                                    'data_consistency' => __('Data Consistency'),
                                    'inventory_accuracy' => __('Inventory Accuracy'),
                                    'financial_integrity' => __('Financial Integrity'),
                                    'pending_backlog' => __('Pending Backlog'),
                                ] as $key => $label)
                                @php $catScore = (int) round($siteHealth->details[$key]['score'] ?? 0); @endphp
                                <div>
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="font-medium text-slate-600">{{ $label }}</span>
                                        <span class="font-semibold {{ $catScore >= 90 ? 'text-emerald-600' : ($catScore >= 70 ? 'text-amber-600' : 'text-rose-600') }}">{{ $catScore }}%</span>
                                    </div>
                                    <div class="mt-1 h-1.5 rounded-full bg-slate-100">
                                        <div class="h-1.5 rounded-full {{ $catScore >= 90 ? 'bg-emerald-500' : ($catScore >= 70 ? 'bg-amber-500' : 'bg-rose-500') }}"
                                             style="width: {{ $catScore }}%"></div>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                            @else
                            <p class="px-4 pb-3 text-xs text-slate-400">{{ __('Not checked yet — recalculate to see a score.') }}</p>
                            @endif

                            <div class="border-t border-slate-100 px-4 pt-2.5">
                                <form method="POST" action="{{ route('site-health.refresh') }}">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold text-accent-700 hover:text-accent-800">{{ __('Recalculate now') }}</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    @endcan

                    <button class="relative rounded-full p-2 text-slate-500 hover:bg-slate-100 hover:text-brand-800">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                        </svg>
                        <span
                            class="absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-accent-500 ring-2 ring-white"></span>
                    </button>

                    <!-- User dropdown -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open"
                            class="flex items-center gap-2 sm:gap-3 rounded-lg px-1.5 sm:px-2 py-1.5 hover:bg-slate-100">
                            <span
                                class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-gradient-to-br from-brand-600 to-accent-500 text-sm font-bold text-white">
                                {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                            </span>
                            <span class="hidden md:block text-left">
                                <span class="block text-sm font-semibold text-slate-800">{{ Auth::user()->name }}</span>
                                <span class="block text-[11px] font-medium text-accent-600">{{
                                    Auth::user()->getRoleNames()->first() ?? __('No role') }}</span>
                            </span>
                            <svg class="h-4 w-4 shrink-0 text-slate-400 hidden sm:block" fill="none" viewBox="0 0 24 24"
                                stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>

                        <div x-show="open" @click.outside="open = false" x-transition
                            class="absolute right-0 mt-2 w-48 rounded-xl bg-white py-1.5 shadow-lg ring-1 ring-slate-200"
                            style="display: none;">
                            <a href="{{ route('profile.edit') }}"
                                class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 hover:text-brand-800">{{
                                __('Profile') }}</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit"
                                    class="block w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 hover:text-brand-800">
                                    {{ __('Log Out') }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Heading -->
            @isset($header)
            <div class="px-4 sm:px-6 pt-6">
                {{ $header }}
            </div>
            @endisset

            <!-- Flash messages -->
            @if (session('success') || session('error'))
            <div class="px-4 sm:px-6 pt-4" x-data="{ show: true }" x-show="show" x-transition.opacity>
                @if (session('success'))
                <div
                    class="flex items-center gap-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <span class="flex-1">{{ session('success') }}</span>
                    <button @click="show = false" class="text-emerald-400 hover:text-emerald-600">&times;</button>
                </div>
                @else
                <div
                    class="flex items-center gap-3 rounded-xl bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700 ring-1 ring-rose-200">
                    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    </svg>
                    <span class="flex-1">{{ session('error') }}</span>
                    <button @click="show = false" class="text-rose-400 hover:text-rose-600">&times;</button>
                </div>
                @endif
            </div>
            @endif

            <!-- Page Content -->
            <main class="flex-1 min-w-0 px-4 sm:px-6 py-6">
                {{ $slot }}
            </main>
        </div>
    </div>
</body>

</html>