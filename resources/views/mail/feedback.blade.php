{{ $feedback->body }}

--
{{ $feedback->user->name }} <{{ $feedback->user->email }}>
@if ($feedback->page !== null)
Desde: {{ url($feedback->page) }}
@endif
@if ($feedback->created_at !== null)
{{ $feedback->user->clock()->localize($feedback->created_at)->format('d/m/Y H:i') }} ({{ $feedback->user->timezone }})
@endif
