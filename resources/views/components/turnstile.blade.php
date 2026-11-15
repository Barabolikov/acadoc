{{-- Капча Cloudflare Turnstile. Віджет малює скрипт у layouts/app, коли форма стає видимою. --}}
@props(['bag' => 'default', 'showError' => true])

<div>
    <div class="turnstile-box min-h-[65px] flex justify-center" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
    @if ($showError)
        @error(\App\Rules\Turnstile::FIELD, $bag)
            <p class="text-red-500 text-xs mt-1 text-center">{{ $message }}</p>
        @enderror
    @endif
</div>
