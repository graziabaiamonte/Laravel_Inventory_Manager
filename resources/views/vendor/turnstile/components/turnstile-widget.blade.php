@if($isEnabled())
<div>
    <div 
        class="cf-turnstile" 
        data-sitekey="{{ $sitekey }}"
        data-theme="{{ $theme }}"
        data-size="{{ $size }}"
        @if($callback) data-callback="{{ $callback }}" @endif
        @if($errorCallback) data-error-callback="{{ $errorCallback }}" @endif
        @if($expiredCallback) data-expired-callback="{{ $expiredCallback }}" @endif
    ></div>
    
    @push('scripts')
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endpush
</div>
@endif
