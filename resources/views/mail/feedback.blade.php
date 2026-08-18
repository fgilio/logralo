{{-- Unescaped on purpose, and only safe because of what this view is: the
     mailable renders it as `Content(text:)`, so the output is the mail body
     itself, not markup inside one. Blade's `{{ }}` would put `&amp;` where
     somebody typed `&`, and no mail client decodes that back. --}}
{!! $feedback->body !!}

--
{!! $feedback->user->name !!} <{!! $feedback->user->email !!}>
@if ($feedback->page !== null)
Desde: {!! url($feedback->page) !!}
@endif
@if ($feedback->created_at !== null)
{{ $feedback->user->clock()->localize($feedback->created_at)->format('d/m/Y H:i') }} ({{ $feedback->user->timezone }})
@endif
