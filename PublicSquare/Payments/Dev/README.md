# Dev-only: simulated card security code rule

**Spike branch, not for merge.** Stands in for PublicSquare's card security code rule (the "saved-card CVC step-up"
transaction rule) until it is enabled on our Test account. Remove this branch once it is.

Active only when Magento runs in developer mode **and** `dev/publicsquare/simulate_cvc_step_up_rule` is `1`
(set it in `core_config_data`, then clean the config cache).

- **When it rejects:** a payment on a card with an earlier successful payment, shipping to an address none of
  them shipped to, when the CVV wasn't refreshed in the last 30 minutes after the card's last successful payment
  and last rejection. No request reaches PublicSquare for a rejected payment.
- **Rejection shape:** the same as PublicSquare's: `status: rejected` with
  `fraud_details.rules[].requires_action: card_security_code`.
- **How it learns about refreshes:** after `cards.updateCvc` succeeds, checkout reports it to
  `POST /V1/publicsquare/carts/mine/dev/cvc-updated`. PublicSquare reads the update time from Basis Theory
  instead.

This proves the plugin flow, not the rule itself.
