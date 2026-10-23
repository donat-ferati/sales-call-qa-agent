<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Call QA' }} · Sales Call QA Agent</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body x-data="{ navigationOpen: false }">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-3">Skip to content</a>
<header class="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 lg:hidden">
    <a href="{{ route('dashboard') }}" class="font-semibold tracking-tight">Call QA<span
            class="ml-2 text-xs font-normal text-slate-500">Sales quality review</span></a>
    <button type="button" @click="navigationOpen = !navigationOpen" :aria-expanded="navigationOpen"
            aria-controls="navigation" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Menu
    </button>
</header>
<aside id="navigation" :class="navigationOpen ? 'block' : 'hidden'"
       class="fixed inset-y-0 left-0 z-30 w-60 border-r border-slate-200 bg-white p-5 max-lg:top-16 lg:block">
    <a href="{{ route('dashboard') }}" class="mb-10 hidden items-center gap-3 lg:flex">
        <span class="flex size-9 items-center justify-center rounded-xl bg-teal-800 text-white" aria-hidden="true"><svg
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-5"><path
                    d="M5 12v5m4-10v13m4-16v16m4-13v13m4-9v5"/></svg></span>
        <span><span class="block font-semibold tracking-tight">Call QA</span><span class="block text-xs text-slate-500">Sales quality review</span></span>
    </a>
    <nav aria-label="Main navigation" class="space-y-1">
        @foreach ([['dashboard', 'Overview'], ['calls.index', 'Call library'], ['templates.index', 'QA templates']] as [$routeName, $label])
            <a href="{{ route($routeName) }}"
               @class(['flex items-center rounded-lg px-3 py-2.5 text-sm font-medium transition-colors', 'bg-teal-50 text-teal-900' => request()->routeIs(str_replace('.index', '.*', $routeName)), 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => !request()->routeIs(str_replace('.index', '.*', $routeName))]) @if(request()->routeIs(str_replace('.index', '.*', $routeName))) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <div class="mt-8 border-t border-slate-200 pt-5">
        <a href="{{ route('calls.create') }}"
           class="flex items-center justify-center gap-2 rounded-lg bg-teal-800 px-3 py-2.5 text-sm font-semibold text-white hover:bg-teal-900"><span
                aria-hidden="true">+</span> Upload calls</a>
    </div>
    <div class="mt-10 border-t border-slate-200 pt-4 lg:absolute lg:inset-x-5 lg:bottom-5">
        <p class="truncate text-sm font-medium">{{ auth()->user()->name }}</p>
        <p class="mt-1 text-xs capitalize text-slate-500">{{ auth()->user()->role }}</p>
        <form method="POST" action="{{ route('logout') }}" class="mt-3">
            @csrf
            <button class="text-xs font-medium text-slate-600 hover:text-slate-950">Sign out</button>
        </form>
    </div>
</aside>
<div class="lg:pl-60">
    <div class="hidden h-16 items-center justify-between border-b border-slate-200 bg-white px-8 lg:flex">
        <span class="text-xs font-medium text-slate-500">Your quality workspace</span>
        <span class="flex items-center gap-2 text-xs text-slate-600">
            <span class="size-1.5 rounded-full bg-teal-700" aria-hidden="true">
            </span>Local processing · Qwen3 14B</span>
    </div>
    <main id="main" class="mx-auto w-full max-w-screen-2xl px-4 py-7 sm:px-6 lg:px-8 lg:py-8">
        @if (session('status'))
            <div role="status"
                 class="mb-6 rounded-xl bg-teal-50 px-4 py-3 text-sm text-teal-950">{{ session('status') }}</div>
        @endif
        {{ $slot }}
    </main>
</div>
@livewireScripts
</body>
</html>
