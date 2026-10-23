<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Call QA</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="min-h-screen">
<main class="grid min-h-screen lg:grid-cols-2">
    <section class="hidden flex-col justify-between bg-teal-950 p-12 text-teal-50 lg:flex">
        <p class="text-xl font-semibold tracking-tight">Call QA</p>
        <div class="max-w-lg"><h1 class="text-4xl font-semibold leading-tight tracking-tight">Every judgment.<br>Backed
                by the call.</h1>
            <p class="mt-6 text-base leading-7 text-teal-200">Review sales conversations against your standards. Listen
                to the evidence, resolve uncertainty, and keep the final decision in human hands.</p></div>
        <p class="text-xs text-teal-300">Private recordings. Local transcription and analysis.</p>
    </section>
    <section class="flex items-center justify-center px-6 py-16 sm:px-12">
        <div class="w-full max-w-sm"><p class="mb-10 text-lg font-semibold text-teal-900 lg:hidden">Call QA</p>
            <h2 class="text-2xl font-semibold tracking-tight">Welcome to your workspace</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">Sign in to review calls and manage QA templates.</p>
            <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-5">
                @csrf
                <x:form.input name="email" label="Email address" type="email" :value="old('email')"
                              autocomplete="username" required autofocus/>
                <x:form.input name="password" label="Password" type="password" autocomplete="current-password"
                              required/>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="remember"
                           class="size-4 accent-teal-800">
                    Keep me signed in</label>
                <x:button type="submit" class="w-full">Sign in</x:button>
            </form>
            <p class="mt-8 text-xs leading-5 text-slate-500">Access is provisioned by your workspace administrator.</p>
        </div>
    </section>
</main>
</body>
</html>
