<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'PriceWatch MY' }}</title>
    <meta name="description" content="Track Malaysian grocery prices from KPDN PriceCatcher open data.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">

<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4">
        <a href="{{ route('items.index') }}" class="flex items-center gap-2.5" wire:navigate>
            <span class="grid h-9 w-9 place-items-center rounded-lg bg-slate-900 text-sm font-bold text-white">RM</span>
            <span>
                <span class="block text-base font-semibold leading-tight">PriceWatch MY</span>
                <span class="block text-xs leading-tight text-slate-500">Harga barang, dari data terbuka KPDN</span>
            </span>
        </a>

        <nav class="flex items-center gap-1 text-sm font-medium">
            <a href="{{ route('items.index') }}" wire:navigate
               class="rounded-lg px-3 py-2 transition {{ request()->routeIs('items.*') ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                Browse
            </a>
            <a href="{{ route('pipeline') }}" wire:navigate
               class="rounded-lg px-3 py-2 transition {{ request()->routeIs('pipeline') ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                Data pipeline
            </a>

            @auth
                <a href="{{ route('watchlist') }}" wire:navigate
                   class="rounded-lg px-3 py-2 transition {{ request()->routeIs('watchlist') ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                    Watchlist
                </a>

                <form method="POST" action="{{ route('logout') }}" class="contents">
                    @csrf
                    <button type="submit"
                            class="rounded-lg px-3 py-2 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                        Sign out
                    </button>
                </form>
            @else
                <a href="{{ route('login') }}" wire:navigate
                   class="rounded-lg px-3 py-2 transition {{ request()->routeIs('login') ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                    Sign in
                </a>
            @endauth
        </nav>
    </div>
</header>

<main class="mx-auto max-w-6xl px-4 py-8">
    {{ $slot }}
</main>

<footer class="mt-16 border-t border-slate-200 bg-white">
    <div class="mx-auto max-w-6xl px-4 py-6 text-xs leading-relaxed text-slate-500">
        <p>
            Price data published by the Ministry of Domestic Trade and Cost of Living (KPDN) through
            <a href="https://data.gov.my" class="font-medium text-slate-700 underline underline-offset-2" target="_blank" rel="noopener noreferrer">data.gov.my</a>
            under the PriceCatcher programme.
        </p>
        <p class="mt-1">
            An independent academic project. Not affiliated with, nor endorsed by, KPDN or the Government of Malaysia.
        </p>
    </div>
</footer>

</body>
</html>
