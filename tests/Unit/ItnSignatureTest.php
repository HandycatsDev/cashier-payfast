<?php

namespace Tests\Unit;

use HandycatsDev\CashierPayFast\PayFastClient;
use Tests\TestCase;

/**
 * The inbound ITN is signed by a different rule than the outbound payment
 * form, and validating one with the other's rule rejects every genuine
 * notification as forged.
 *
 * The canonical form is PayFast's own
 * (lib/PaymentIntegrations/Notification.php::dataToString in their PHP SDK):
 * every posted field in order, blanks included, stopping at `signature`. Their
 * `cleanNotificationData()` only runs `stripslashes` — it does not drop empty
 * values, which is the detail the payment-form rule gets wrong.
 *
 * The payloads below are shaped like a real ITN: mostly blanks. That is not
 * incidental — an ITN with no empty fields cannot distinguish the two rules,
 * so a test built from a tidy fixture passes under the bug.
 */
final class ItnSignatureTest extends TestCase
{
    /** @return array<string, string> */
    private function itnPayload(): array
    {
        return [
            'm_payment_id'     => 'tourium-proration-42',
            'pf_payment_id'    => '1089250',
            'payment_status'   => 'COMPLETE',
            'item_name'        => 'Buffalo Ridge Safari Lodge - monthly add-ons',
            'item_description' => '',
            'amount_gross'     => '99.00',
            'amount_fee'       => '-3.24',
            'amount_net'       => '95.76',
            'custom_str1'      => '',
            'custom_str2'      => 'proration',
            'custom_str3'      => '',
            'custom_str4'      => '',
            'custom_str5'      => '',
            'custom_int1'      => '',
            'custom_int2'      => '',
            'name_first'       => '',
            'name_last'        => '',
            'email_address'    => '',
            'merchant_id'      => '10000100',
            'token'            => 'e0dd1234-5678-90ab-cdef-1234567890ab',
            'billing_date'     => '2026-08-26',
        ];
    }

    private function sign(array $payload, string $passphrase = '', bool $encodePassphrase = false): string
    {
        $canonical = '';

        foreach ($payload as $key => $value) {
            $canonical .= $key.'='.urlencode((string) $value).'&';
        }

        $canonical = substr($canonical, 0, -1);

        if ($passphrase !== '') {
            $canonical .= '&passphrase='.($encodePassphrase ? urlencode($passphrase) : $passphrase);
        }

        return md5($canonical);
    }

    private function client(string $passphrase = ''): PayFastClient
    {
        return new PayFastClient('10000100', '46f0cd694581a', $passphrase, true);
    }

    public function test_it_accepts_a_notification_whose_blank_fields_are_signed(): void
    {
        $payload              = $this->itnPayload();
        $payload['signature'] = $this->sign($payload);

        $this->assertTrue($this->client()->validateSignature($payload));
    }

    public function test_it_accepts_either_passphrase_encoding(): void
    {
        // PayFast's SDK appends the passphrase raw; their documentation
        // examples url-encode it. A passphrase with a space distinguishes the
        // two, and both must be accepted or real notifications are refused.
        $passphrase = 'a secret phrase';

        foreach ([false, true] as $encoded) {
            $payload              = $this->itnPayload();
            $payload['signature'] = $this->sign($payload, $passphrase, $encoded);

            $this->assertTrue(
                $this->client($passphrase)->validateSignature($payload),
                $encoded ? 'url-encoded passphrase rejected' : 'raw passphrase rejected',
            );
        }
    }

    public function test_it_rejects_a_signature_built_by_the_payment_form_rule(): void
    {
        // The regression itself: signing the same payload while skipping empty
        // values is what the outbound payment form does, and what this class
        // used to do on the way in.
        $payload = $this->itnPayload();

        $canonical = '';
        foreach ($payload as $key => $value) {
            if ($value !== '') {
                $canonical .= $key.'='.urlencode(trim((string) $value)).'&';
            }
        }

        $payload['signature'] = md5(substr($canonical, 0, -1));

        $this->assertFalse($this->client()->validateSignature($payload));
    }

    public function test_it_rejects_a_tampered_amount(): void
    {
        $payload              = $this->itnPayload();
        $payload['signature'] = $this->sign($payload);
        $payload['amount_gross'] = '9900.00';

        $this->assertFalse($this->client()->validateSignature($payload));
    }

    public function test_it_rejects_a_notification_with_no_signature(): void
    {
        $this->assertFalse($this->client()->validateSignature($this->itnPayload()));
    }

    public function test_fields_after_the_signature_are_not_signed(): void
    {
        // PayFast stops at `signature` rather than skipping it, so anything
        // posted afterwards is outside the signed set.
        $payload              = $this->itnPayload();
        $payload['signature'] = $this->sign($payload);
        $payload['trailing_field'] = 'ignored';

        $this->assertTrue($this->client()->validateSignature($payload));
    }
}
