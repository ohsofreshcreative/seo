{{-- Nonce WordPressa dla formularzy panelu (CSRF) — weryfikowany przez VerifyNonce. --}}
@props(['action' => \App\Http\Middleware\Panel\VerifyNonce::ACTION])
{!! wp_nonce_field($action, \App\Http\Middleware\Panel\VerifyNonce::FIELD, false, false) !!}
