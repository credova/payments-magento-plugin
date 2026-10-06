# Local Development Guide

This document explains how to run Magento with this plugin on your machine.
The `dev:*` mise tasks do all of the setup. Do not use the older `bin/setup*` scripts.

## Toolchain

[mise](https://mise.jdx.dev) installs PHP and Composer and runs every project task. Run `mise tasks` to list them.

```bash
mise install            # PHP 8.3.30 and Composer 2.9.7
mise run lint           # phpcs (Magento2 standard) and PHP compatibility
mise run test           # unit tests
```

- PHP and Composer are pinned to exact versions. Do not change them until the production versions are confirmed.
- The PHP pin must match `config.platform.php` in `composer.json`.
- mise compiles PHP from source. Install the build dependencies first, as listed in [vfox-php](https://github.com/jdx/vfox-php#requirements).
- CI does not compile PHP. When `CI` is set, mise uses PHP 8.3 and Composer from `setup-php`, so CI does not pin the exact patch versions.

## Prerequisites

- A Docker-compatible engine (Docker Desktop, Podman, or similar). Run `docker info` to make sure it is running.
- If `DOCKER_HOST` is set, it must point to a running engine. Unset it if it points to an engine you removed.
- Adobe Commerce Marketplace access keys (see below).
- PublicSquare public and secret API keys for the sandbox.
- No other process on the host ports that the stack uses: 80, 443, 3306, 5672, 6379, 8081 (phpMyAdmin; set `PHPMYADMIN_PORT` to change it), 9200, 15672.

### Docker engine

The stack publishes ports 80 and 443, so the engine must be able to bind ports below 1024.

Podman: a rootless machine cannot bind these ports. Set the machine to rootful once:

```bash
podman machine stop
podman machine set --rootful
podman machine start
```

Rootful and rootless machines use separate storage. Containers and volumes that you made before the change are not visible after it.

OrbStack: it binds these ports with no extra setup. Start it and select its Docker context:

```bash
orb start
docker context use orbstack
```

### Adobe Commerce Marketplace access keys

Composer downloads Magento from `repo.magento.com`, which requires access keys.
Get them from [Adobe Commerce Marketplace](https://commercemarketplace.adobe.com/) under **My Profile > Access Keys**.
Supply them in one of these ways before the first install:

```bash
# Environment variables, read by bin/it-up
export ADOBE_ACCESS_PUBLIC_KEY=<public key>
export ADOBE_ACCESS_PRIVATE_KEY=<private key>

# Or store them in ~/.composer, which the stack mounts into the container
COMPOSER_HOME=~/.composer composer config --global http-basic.repo.magento.com <public key> <private key>
```

You can also pass them inline:
`ADOBE_ACCESS_PUBLIC_KEY=<public key> ADOBE_ACCESS_PRIVATE_KEY=<private key> mise run dev:build`.

If neither is present, `bin/it-up` stops before the download with `Adobe Commerce Marketplace access keys not found`.

## First install

```bash
PUBLICSQUARE_PUBLIC_KEY=<public key> PUBLICSQUARE_SECRET_KEY=<secret key> mise run dev:build
```

This task runs three scripts in sequence:

1. `bin/it-up` installs Magento into `magento-install/` with [docker-magento](https://github.com/markshust/docker-magento) and starts the containers.
2. `bin/it-install` installs the plugin, sets the API keys, and sets the store for local tests.
3. `bin/it-sample-data` deploys the Magento sample data.

If you do not set the key variables, `bin/it-install` asks for the keys.
Set `MAGENTO_VERSION` to install a version other than the default (`2.4.8-p3`).

Open https://magento.test when the install completes.

### HTTPS certificate

The first install creates a local certificate authority (CA) with mkcert and asks for your system password to trust it.
`bin/it-up` keeps that CA in `~/.local/share/psq-magento/mkcert/` and reuses it, so later installs do not ask again.
To start again with a new CA, remove that directory and the old "mkcert" certificate in Keychain Access.

## Daily use

| Task | Command |
|------|---------|
| Start the stack | `mise run dev:up` |
| Stop the stack | `mise run dev:down` |
| Deploy plugin changes | `mise run magento:deploy` |
| Run a Magento command | `mise run magento -- <command>` |
| Open a shell in the container | `mise run magento:cli -- bash` |
| Check that the store and plugin work | `mise run dev:verify` |
| Run the acceptance tests (needs Selenium on port 4444; the dev stack does not include it yet) | `mise run test:acceptance` |
| Remove the stack and all its data | `mise run dev:reset` |

The plugin source in `PublicSquare/` is mounted into the container.
After you change DI configuration, layout, or static assets, run `mise run magento:deploy`.

## Start again from zero

```bash
mise run dev:reset
PUBLICSQUARE_PUBLIC_KEY=<public key> PUBLICSQUARE_SECRET_KEY=<secret key> mise run dev:build
```

`mise run dev:reset` removes the containers, the volumes, and the `magento-install/` directory.

## Recover from a partial install

If `magento-install/src/bin/magento` exists, run `mise run dev:build` again **without** `mise run dev:reset`.
`bin/it-up` detects the install and skips the download. Then `bin/it-install` and `bin/it-sample-data` run.

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `failed to connect to the docker API at unix://...` | `DOCKER_HOST` points to an engine that is not running. | Unset `DOCKER_HOST`, or start that engine. |
| `proxy already running` (Podman) or `port is already allocated` (Docker) | A different container uses a port of the stack. | Find it with `docker ps --filter publish=<port>`, then stop it. |
| `rootlessport cannot expose privileged port 80` | The Podman machine is rootless. | Set it to rootful. See [Docker engine](#docker-engine). |
| `Magento download failed: bin/magento is missing in the phpfpm container` | Usually the access keys for `repo.magento.com` are wrong (HTTP 401), or a service did not start. Read the output above this error. | Supply the keys (see [Adobe Commerce Marketplace access keys](#adobe-commerce-marketplace-access-keys)), run `mise run dev:reset`, then install again. |
| `exists but does not look initialized` | A previous install did not complete. | `mise run dev:up` removes the directory and installs again. To also remove old volumes, run `mise run dev:reset` first. |
| `cannot bind tcp port :8081: address already in use` | Another process uses the phpMyAdmin port. | Set `PHPMYADMIN_PORT` to a free port, run `mise run dev:reset`, then install again. |
| RabbitMQ `.erlang.cookie: eacces`, and OpenSearch fails as a dependency | A race on the first start with new volumes. `bin/it-up` retries the start once. | If it still fails, run `mise run dev:build` again **without** `mise run dev:reset`. |
| `mise run dev:reset` hangs | An old `.envrc` put a relative `./bin` on `PATH`. | Pull the current `.envrc`, then run `direnv allow`. |
