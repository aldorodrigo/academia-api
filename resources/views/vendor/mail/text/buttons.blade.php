@foreach ($actions as $action)
{{ $action['label'] }}: {{ $action['url'] }}
@endforeach
