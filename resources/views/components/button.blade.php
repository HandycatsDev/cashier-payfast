<form method="POST" action="{{ $checkout->url() }}" class="cashier-checkout-form" style="display: inline;">
    @foreach ($checkout->fields() as $key => $value)
        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
    @endforeach
    <button {{ $attributes->merge(['type' => 'submit']) }}>
        {{ $slot }}
    </button>
</form>
