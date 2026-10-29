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

If your subscription module places renewal orders with a saved PublicSquare card, mark each renewal before you place
it. PublicSquare then charges it as a recurring payment linked to the subscription's first payment. Checks meant for a
shopper at checkout, such as re-entering the card security code, don't apply to it.

Inject `PublicSquare\Payments\Model\SubscriptionRenewal`, and call `markAsRenewal()` after you set the quote's
payment and before you place the order:

```php
// $initialOrder is the order that started the subscription, paid through PublicSquare.
$quote->getPayment()->importData(['method' => 'publicsquare_payments_cc_vault', 'additional_data' => [
    'public_hash' => $token->getPublicHash(),
]]);
try {
    $this->subscriptionRenewal->markAsRenewal($quote, $initialOrder);
} catch (\Magento\Framework\Exception\LocalizedException $e) {
    // It can't continue $initialOrder's recurring payments, so place it as a one-off payment instead.
    $this->logger->warning($e->getMessage());
}
$order = $this->cartManagement->submit($quote);
```

`markAsRenewal()` saves the mark on the quote, so you can place the order in another process, such as a queue
consumer. Setting the quote's payment again removes the mark, so call `markAsRenewal()` after the last time you set
it.

Call `markAsRenewal()` once for each attempt to charge the renewal, before you queue it. Each call gives the payment a
new idempotency key. If the same attempt is placed again, for example when a queue message is redelivered, the card
isn't charged twice:

* If the first try charged the card but no order was saved, PublicSquare returns that payment for up to 24 hours, and
  the order is placed with it.
* If the first try placed an order, `submit()` fails with "This renewal was already placed as order …".
* If the order failed to save after the charge, the plugin cancels or refunds the payment and gives the quote a new
  key, so placing it again charges the card again.

To retry a declined renewal, mark the quote again so the retry gets a new key.

If PublicSquare rejects the recurring payment, `submit()` fails as it does for a declined card. The order isn't
retried as a one-off payment.

Pass the subscription's first order that was paid with the same card as the renewal. `markAsRenewal()` throws a
`LocalizedException`, and leaves the quote unmarked, when:

* the initial order wasn't paid through PublicSquare, for example when the subscription started with another payment
  method;
* the quote's payment isn't a PublicSquare card, or is a saved card the customer deleted;
* the renewal uses a different card than the initial order, for example after the subscriber replaced their card.

In these cases, catch the exception and place the order anyway, as the example does. The renewal is then charged as a
one-off payment, like a shopper paying with a saved card. If it ships to an address the card hasn't shipped to
before, PublicSquare may reject it for a missing card security code.

When a subscriber replaces their card, the first renewal on the new card starts a new series. Pass that renewal's
order as the initial order for later renewals.

## Development

To build, run, or test this plugin locally, see [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md).

## Releasing

A release has three parts: a version change in `PublicSquare/Payments/composer.json`, a `vX.Y.Z` tag, and a GitHub
release. There is no build step, and the release has no assets.

`composer.json` is the only file that holds the plugin version. Do not change `setup_version` in `etc/module.xml` for
a release. It is the Magento schema version, and it changes only with a schema or data patch.

You need `gh` (logged in, with write access to the repo) and the mise toolchain from
[LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md).

1. Set the release version and the Shortcut story. The version must be newer than the latest tag.

   ```sh
   VERSION=0.2.7
   STORY=12345
   gh release view --json tagName --jq .tagName   # latest release
   ```

2. Change the version on a new branch. Then open a PR. `master` is protected, so the change must go through a PR.

   ```sh
   git switch master && git pull
   git switch -c chore/sc-$STORY/release-v$VERSION
   mise x -- composer config --file=PublicSquare/Payments/composer.json version $VERSION
   git diff   # only the "version" line changes
   git commit -am "chore: release v$VERSION [sc-$STORY]"
   git push -u origin HEAD
   gh pr create --fill
   ```

3. After the PR merges, create the GitHub release on the merge commit. This command also creates the tag. It writes
   the release notes from the PRs merged since the last tag.

   ```sh
   SHA=$(gh pr view chore/sc-$STORY/release-v$VERSION --json mergeCommit --jq .mergeCommit.oid)
   gh release create v$VERSION --target $SHA --title v$VERSION --generate-notes
   ```

   For a pre-release, add `--prerelease`.

4. Make sure that the tag and the version agree.

   ```sh
   git fetch --tags
   git show v$VERSION:PublicSquare/Payments/composer.json | jq -r .version   # must print $VERSION
   gh release view v$VERSION --web
   ```
