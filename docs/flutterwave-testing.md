Manual testing for Flutterwave card verification

This document shows how to exercise the charge -> authorize (pin/otp) flow locally against the Flutterwave sandbox using the code in `app/Classes/Payment/FlutterwaveProvider.php`.

Pre-requisites
- Ensure `.env` contains FLW_ENCRYPTION_KEY (the dashboard key base64 string) and any client credentials if using a real sandbox.
- App should be able to reach `https://developersandbox-api.flutterwave.com` from your dev machine.

Steps
1. Create a charge via the app (deposit or payContribution) using a saved card. This will create a Transaction with `status = pending` and `meta.provider_payload` containing the provider response.
2. Inspect the Transaction row for `provider_reference` or `meta.provider_payload.id` / `meta.provider_payload.flw_ref`. This is the id used for verification.
3. Call the Verify endpoint (HTTP POST /payments/verify-card-payment) with body:
   {
     "user_id": <user id>,
     "card_id": <bank card id>,
     "transaction_reference": "<transaction reference>",
     "pin": "1234"  // or "otp": "123456"
   }

Behaviour
- The server will look up the Transaction by `transaction_reference` and pass `charge_id` to the provider.
- `FlutterwaveProvider::cardPaymentVerification` now calls `PUT /charges/{id}/authorize` with either encrypted pin or otp depending on payload.
- The provider returns a normalized response. Transaction reconciliation should happen via webhook; if you want immediate processing, adapt the service to call webhook/verify endpoints after provider confirm.

Notes
- Pin is AES-256-GCM encrypted using the `FLW_ENCRYPTION_KEY`. The `Encryptor` in `app/Classes/Encryptor.php` implements compatible encryption.
- If your sandbox requires different endpoints/headers, update `baseUrl()` or `header()` in `FlutterwaveProvider`.

Troubleshooting
- If verification fails with 401/403, check your client credentials and access token flow in `authorize()`.
- If encryption errors occur, ensure `FLW_ENCRYPTION_KEY` is a base64-encoded 32-byte key.
- Logs: see Laravel logs or `Log::info` entries containing `flutterwave_verify` to see raw provider responses.
