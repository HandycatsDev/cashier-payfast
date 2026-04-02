<?php $nonce = $nonce ?? ''; ?>

<script @if ($nonce) nonce="{{ $nonce }}" @endif>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('form.cashier-checkout-form').forEach(function(form) {
            if (form.dataset.autoSubmit === 'true') {
                form.submit();
            }
        });
    });
</script>
