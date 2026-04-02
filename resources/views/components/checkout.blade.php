<form method="POST" action="{{ $action() }}" id="{{ $id }}" class="cashier-checkout-form" data-auto-submit="true" {{ $attributes }}>
    @foreach ($fields() as $key => $value)
        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
    @endforeach
    {{ $slot }}
</form>
