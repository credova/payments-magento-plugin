# Dev-only: simulated `require_fresh_cvc`

**Spike branch, not for merge.** Stands in for the payments-api check proposed in
<https://claude.ai/artifact/WxWiK6JAJjjrDKwMTVLfyw> (R1/R2) until payments-api supports it.

Active only when Magento runs in developer mode **and**
`dev/publicsquare/simulate_require_fresh_cvc` is `1`.

- After `cards.updateCvc` succeeds, checkout reports the card's new Basis Theory `modified_at`
  (returned by the SDK) to `POST /V1/publicsquare/carts/mine/dev/cvc-updated`.
- When a payment request carries `require_fresh_cvc`, the simulator applies the R2 rule:
  the update must be at or after `max(updated_after - 60s, now - max_age_seconds)`.
  Otherwise it answers like payments-api would, with a `cvv_recollection_required` error,
  and no request is sent to PublicSquare.

The real check would read `modified_at` from Basis Theory on the server. Here the browser reports
it, so this proves the plugin flow, not the security of the check.
