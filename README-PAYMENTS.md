Payment system overview

This project includes a minimal, pluggable payment system scaffold.

Files added/changed
- app/Payable.php  — Trait added to `User` to manage wallet, charge(), withdrawFromWallet(), creditToWallet(), transactions relationship and `contributions` accessor.
- app/Models/Transaction.php — Expanded model with fillable fields: amount, provider, status, reference, type, meta, plus relations to user/group.
- database/migrations/2025_10_12_200000_update_transactions_table_add_fields.php — Adds columns to `transactions` table.
- app/Classes/Payment/* — PaymentInterface and provider stubs: SystemProvider, FlutterwaveProvider, PaystackProvider and updated PaymentFactory.
- app/Http/Controllers/Payment/WebhookController.php — Generic webhook handler: POST /api/payments/webhook/{provider}
- app/Models/Group.php — `group_transaction` attribute now returns transactions for the group.
- routes/web.php — webhook route added under /api/payments/webhook/{provider}

Quick usage
- Charge a user using system provider (local):
  - Create a Transaction via user: `$tx = $user->charge(50.0, 'system', ['reference' => 'ref_123']);`
  - For external providers, `charge()` will create a pending transaction; use provider SDK to initiate payment and configure webhooks.

- Withdraw from wallet:
  - `$tx = $user->withdrawFromWallet(20.0);` // immediately deducts available_wallet and records a completed debit transaction

- Webhooks
  - Configure payment provider webhook URL to: `https://yourdomain.com/api/payments/webhook/{provider}`
  - The controller will try to find transaction by reference and update its status (completed/failed) and append webhook data to `meta`.

Next steps / to do
- Implement real provider SDK integrations for Flutterwave/Paystack in their provider classes.
- Add authentication & signature verification for webhooks.
- Add tests for Payable trait and WebhookController.
- Consider queueing expensive webhook processing.

