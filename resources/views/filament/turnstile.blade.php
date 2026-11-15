{{-- Капча Cloudflare Turnstile для форми входу Filament (Livewire). Токен записується у стан поля. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div wire:ignore
         x-data="{ widgetId: null }"
         x-init="
            const path = @js($getStatePath());
            const render = () => {
                widgetId = turnstile.render($refs.box, {
                    sitekey: @js(config('services.turnstile.site_key')),
                    language: 'uk',
                    callback: (token) => $wire.set(path, token, false),
                    'expired-callback': () => $wire.set(path, null, false),
                });
            };
            const wait = setInterval(() => { if (window.turnstile) { clearInterval(wait); render(); } }, 100);
         "
         x-on:turnstile-reset.window="if (widgetId !== null) turnstile.reset(widgetId)">
        <div x-ref="box" class="flex justify-center" style="min-height: 65px"></div>
    </div>
</x-dynamic-component>
