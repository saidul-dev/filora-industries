@php
    $company = \App\Models\CompanySetting::current();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Sign In') }} — {{ config('app.name', 'Business ERP') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased text-slate-900 bg-slate-100">
    <div class="h-1 bg-gradient-to-r from-brand-800 via-brand-600 to-accent-500"></div>

    <main class="flex min-h-[calc(100vh-0.25rem)] flex-col items-center justify-center px-4 py-12">
        {{-- Brand --}}
        <div class="mb-8 flex flex-col items-center text-center">
            @if ($company->logo_url)
                <img src="{{ $company->logo_url }}" alt="{{ $company->name }}" class="h-14 w-14 rounded-xl object-cover shadow-sm">
            @else
                <x-application-logo class="h-14 w-14" />
            @endif
            <h1 class="mt-4 text-xl font-bold tracking-wide text-brand-900">{{ $company->name }}</h1>
            <p class="mt-1 text-xs font-semibold uppercase tracking-[0.2em] text-accent-600">
                {{ __('Quality. Performance. Reliability.') }}
            </p>
        </div>

        {{-- Sign-in card --}}
        <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-lg shadow-brand-900/5 ring-1 ring-slate-200">
            <h2 class="text-lg font-bold text-brand-900">{{ __('Sign in to your account') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('Sales, procurement, production and inventory in one place.') }}</p>

            @if (session('status'))
                <div class="mt-5 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">{{ __('Email') }}</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                        autocomplete="username"
                        class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm focus:border-brand-600 focus:ring-brand-600">
                    @error('email') <p class="mt-1.5 text-xs font-medium text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <label for="password" class="block text-sm font-medium text-slate-700">{{ __('Password') }}</label>
                        @if (Route::has('password.request'))
                            <a href="{{ route('password.request') }}" class="text-xs font-medium text-brand-600 hover:text-brand-800">{{ __('Forgot password?') }}</a>
                        @endif
                    </div>
                    <input id="password" type="password" name="password" required autocomplete="current-password"
                        class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm focus:border-brand-600 focus:ring-brand-600">
                    @error('password') <p class="mt-1.5 text-xs font-medium text-rose-600">{{ $message }}</p> @enderror
                </div>

                <label for="remember_me" class="inline-flex items-center gap-2 text-sm text-slate-600">
                    <input id="remember_me" type="checkbox" name="remember"
                        class="rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                    {{ __('Remember me') }}
                </label>

                <button type="submit"
                    class="w-full rounded-lg bg-brand-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-accent-500 focus:ring-offset-2">
                    {{ __('Sign in') }}
                </button>
            </form>

            @if (app()->environment('local'))
                @php
                    $demoAccounts = [
                        ['role' => 'Super Admin', 'email' => 'admin@businesserp.test'],
                        ['role' => 'Sales Person', 'email' => 'sales@businesserp.test'],
                        ['role' => 'Factory Manager', 'email' => 'factory@businesserp.test'],
                        ['role' => 'Accountant', 'email' => 'accountant@businesserp.test'],
                    ];
                @endphp
                <div class="mt-6 border-t border-slate-100 pt-4">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ __('Quick fill') }} &middot; {{ __('dev only') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($demoAccounts as $account)
                            <button type="button"
                                onclick="document.getElementById('email').value = '{{ $account['email'] }}'; document.getElementById('password').value = 'password';"
                                class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600 hover:border-brand-300 hover:bg-brand-50 hover:text-brand-800">
                                {{ $account['role'] }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <p class="mt-8 text-center text-xs text-slate-400">
            &copy; {{ date('Y') }} {{ $company->name }} &middot; {{ __('Powered by') }}
            <a href="https://vexasoft.net" target="_blank" rel="noopener" class="font-semibold text-slate-500 hover:text-brand-700">Vexasoft</a>
        </p>
    </main>
</body>

</html>
