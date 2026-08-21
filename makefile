SHELL := /usr/bin/env bash

args = `arg="$(filter-out $(firstword $(MAKECMDGOALS)),$(MAKECMDGOALS))" && echo $${arg:-${1}}`

green  = $(shell printf "\e[32;01m$1\e[0m")
yellow = $(shell printf "\e[33;01m$1\e[0m")
red    = $(shell printf "\e[33;31m$1\e[0m")

format = $(shell printf "%-40s %s" "$(call green,bin/$1)" $2)

comma:= ,

.DEFAULT_GOAL:=help

%:
	@:

help:
	@echo ""
	@echo "$(call yellow,Use the following CLI commands:)"
	@echo "$(call red,===============================)"
	@echo "$(call format,cache-clean,'Access the cache-clean CLI.')"
	@echo "$(call format,cli,'Run any CLI command without going into the bash prompt.')"
	@echo "$(call format,clinotty,'Run any CLI command with no TTY.')"
	@echo "$(call format,cliq,'Run any CLI command but pipe all output to /dev/null.')"
	@echo "$(call format,create-user,'Create either an admin user or customer account.')"
	@echo "$(call format,deploy,'Runs the standard Magento deployment process commands. Pass extra locales besides `en_US` via an optional argument.')"
	@echo "$(call format,docker-compose,'Support V1 (`docker-compose`) and V2 (`docker compose`) docker compose command, and use custom configuration files.')"
	@echo "$(call format,download,'Download & extract specific Magento version to the src/magento-site/ directory.')"
	@echo "$(call format,fixowns,'This will fix filesystem ownerships within the container.')"
	@echo "$(call format,fixperms,'This will fix filesystem permissions within the container.')"
	@echo "$(call format,magento,'Run the Magento CLI.')"
	@echo "$(call format,magento-version,'Determine the Magento version installed in the current environment..')"
	@echo "$(call format,n98-magerun2,'Access the n98-magerun2 CLI.')"
	@echo "$(call format,removeall,'Remove all containers$(comma) networks$(comma) volumes and images.')"
	@echo "$(call format,removenetwork,'Remove a network associated with the current directory name.')"
	@echo "$(call format,removevolumes,'Remove all volumes.')"
	@echo "$(call format,rootnotty,'Run any CLI command as root with no TTY.')"
	@echo "$(call format,setup,'Run the Magento setup process$(comma) with optional domain name.')"
	@echo "$(call format,setup-composer-auth,'Setup authentication credentials for Composer.')"
	@echo "$(call format,setup-domain,'Setup Magento domain name.')"
	@echo "$(call format,setup-ssl,'Generate an SSL certificate for one or more domains.')"
	@echo "$(call format,start,'Start all containers.')"
	@echo "$(call format,stop,'Stop all project containers.')"
	@echo "$(call format,stopall,'Stop all docker running containers.')"
	@echo "$(call format,verify,'Download latest Magento$(comma) install all plugins$(comma) and setup custom website domain.')"

cache-clean:
	@./bin/cache-clean $(call args)

cli:
	@./bin/cli $(call args)
	
deploy:
	@./bin/deploy $(call args)

docker-compose:
	@./bin/docker-compose $(call args)

download:
	@./bin/download $(call args)

fixowns:
	@./bin/fixowns $(call args)

fixperms:
	@./bin/fixperms $(call args)

magento:
	@./bin/magento $(call args)
	
magento-version:
	@./bin/magento-version

n98-magerun2:
	@./bin/n98-magerun2 $(call args)

removeall:
	@./bin/removeall

removenetwork:
	@./bin/removenetwork
	
removevolumes:
	@./bin/removevolumes

setup:
	@./bin/setup $(call args)

setup-cli:
	@./bin/setup-cli $(call args)

setup-composer-auth:
	@./bin/setup-composer-auth

setup-domain:
	@./bin/setup-domain $(call args)
	
setup-install:
	@./bin/setup-install $(call args)
	
setup-ssl:
	@./bin/setup-ssl $(call args)
	
setup-sample-data:
	@./bin/setup-sample-data $(call args)

start:
	@./bin/start $(call args)

stop:
	@./bin/stop $(call args)

stopall:
	@./bin/stopall $(call args)

verify:
	@./bin/verify

install-docker:
	@./bin/install-docker

unit-test:
	@./vendor/bin/phpunit -c tests/unit/phpunit.xml

unit-test-verbose:
	@./vendor/bin/phpunit -c tests/unit/phpunit.xml --testdox

lint:
	@./vendor/bin/phpcs --standard=phpcs.xml --warning-severity=0

lint-fix:
	@./vendor/bin/phpcbf --standard=phpcs.xml

compat:
	@./vendor/bin/phpcs --standard=phpcs-compat.xml

# Integration testing helpers
it-sample-data:
	@./bin/it-sample-data

it-reset:
	@./bin/it-reset

it-down:
	@./bin/it-down

it-up:
	@./bin/it-up

it-install:
	@./bin/it-install

it-complete-build:
	@./bin/it-up
	@./bin/it-install $(call args)
	@./bin/it-sample-data

it-verify:
	@./bin/it-verify

it-test:
	@./vendor/bin/codecept run tests/Acceptance/
