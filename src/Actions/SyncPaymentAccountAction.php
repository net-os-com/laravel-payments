<?php

namespace NetOS\Payments\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use NetOS\Payments\Data\ProviderAccountData;
use NetOS\Payments\Enums\PaymentAccountStatus;
use NetOS\Payments\Events\PaymentAccountUpdated;
use NetOS\Payments\Facades\Payments;
use NetOS\Payments\Models\PaymentAccount;
use NetOS\Payments\PaymentContext;

/**
 * Copies the provider's view of an account onto the local record. Pass the provider data when it was just fetched.
 */
class SyncPaymentAccountAction
{
    use AsAction;

    public function handle(PaymentAccount $account, ?ProviderAccountData $data = null): PaymentAccount
    {
        $context = PaymentContext::forAccount($account->provider, $account->provider_account_id);
        $data ??= Payments::accounts($context)->retrieve($context);

        $account->fill([
            // A deactivated account stays deactivated locally, whatever the provider says.
            'status' => $account->deactivated_at ? PaymentAccountStatus::DEACTIVATED : $data->status,
            'business_name' => $data->businessName,
            'email' => $data->email,
            'capabilities' => $data->capabilities,
            'requirements_due' => $data->requirementsDue,
            'synced_at' => now(),
        ]);

        if ($data->status === PaymentAccountStatus::ACTIVE && $account->activated_at === null) {
            $account->activated_at = now();
        }

        $account->save();

        PaymentAccountUpdated::dispatch($account);

        return $account;
    }
}
