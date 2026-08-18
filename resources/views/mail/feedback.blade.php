{{ $feedback->body }}

--
{{ $feedback->user->name }} <{{ $feedback->user->email }}>
@if ($feedback->page !== null)
Desde: {{ url($feedback->page) }}
@endif
{{ $feedback->created_at?->format('d/m/Y H:i') }} UTC
