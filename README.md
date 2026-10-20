# PublicSquare Financial

PublicSquare provides a software platform for retailers to access third-party providers for lease-to-own financing and
other lending products based on a consumer's credit profile.

## INSTALLATION

### Manual Installation

* extract files from an archive

* deploy files into Magento2 folder `app/code/`

### Enabled Extension

* enable extension (in command line, see manual:
  `http://devdocs.magento.com/guides/v2.0/config-guide/cli/config-cli-subcommands.html`):
>`$> bin/magento module:enable PublicSquare_Payments`

* to make sure that the enabled module is properly registered, run 'setup:upgrade':
>`$> bin/magento setup:upgrade`

* [if needed] re-deploy static view files:
>`$> bin/magento setup:static-content:deploy`

### Webhook Configuration

#### Automated Configuration

* [after v0.2.0 upgrade]

```shell bash
bin/magento psq:configure-webhook
bin/magento cache:flush
```

#### Manual Configuration

1. In the [PublicSquare Portal](https://portal.publicsquare.com) create a new webhook under **Developer > Webhook**
   with the following properties:
   * URL: `{magento.hostname}/publicsquare-payments/webhook/index`
   * Event Types:
     * refund:update
     * settlement:update
2. Enter the Webhook ID and Webhook Key in to the Magento Admin panel under:
   **Stores > Configuration > Sales > Payment Methods > PublicSquare**

## Subscription Renewals

A subscription module that places renewal orders with a saved PublicSquare card should mark each renewal. The
payment then goes to PublicSquare as a recurring charge of the subscription's first payment, with no shopper
present. Rules for shopper checkouts, such as asking for the card security code again, don't apply to it.

Inject `PublicSquare\Payments\Model\SubscriptionRenewal`. After you set the quote's payment, and before you place
the order, call `markAsRenewal()`:

```php
// $initialOrder is the order that started the subscription, paid through PublicSquare.
$quote->getPayment()->importData(['method' => 'publicsquare_payments_cc_vault', 'additional_data' => [
    'public_hash' => $token->getPublicHash(),
]]);
$this->subscriptionRenewal->markAsRenewal($quote, $initialOrder);
$order = $this->cartManagement->submit($quote);
```

`markAsRenewal()` saves the quote with the mark, so the order can be placed in another process, such as a queue
consumer. Setting the quote's payment again removes the mark.

Pass the subscription's first order paid with the card the renewal charges. `markAsRenewal()` throws a
`LocalizedException`, and leaves the quote unmarked, when:

* the initial order has no PublicSquare payment, for example a subscription started with another payment method;
* the quote's payment isn't set to a PublicSquare card;
* the renewal charges another card than the initial order, for example after the subscriber replaced their card.

Place those renewals without the mark. They go as one-off payments. A renewal of a replaced card can then start a
new series: pass its order as the initial order of later renewals.

Recurring payments are supported for merchant accounts on Nuvei. Accounts on Moov also need the first order sent as
an initial recurring payment, which the plugin doesn't do yet.

## Development

To build, run, or test this plugin locally, see [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md).
