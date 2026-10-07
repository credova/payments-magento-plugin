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

## Development

To build, run, or test this plugin locally, see [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md).
