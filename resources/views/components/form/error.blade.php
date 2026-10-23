@props(['name'])
@error($name)<p {{ $attributes->class('mt-1.5 text-xs leading-5 text-rose-700') }} role="alert">{{ $message }}</p>
@enderror
