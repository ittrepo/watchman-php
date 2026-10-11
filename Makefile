# watchman-php: everything runs in Docker, nothing to install locally.
#   make install   composer install (resolved for PHP 7.4, see composer.json platform)
#   make test      phpunit on PHP 7.4, 8.1 and 8.3
#   make lint74    php -l with a real PHP 7.4
#   make guard     AST check for PHP 8-only syntax

VERSIONS := 7.4 8.1 8.3
RUN = docker run --rm -v "$(CURDIR)":/app -w /app

.PHONY: install test lint74 guard $(addprefix test-,$(VERSIONS))

install:
	$(RUN) composer:2 install --no-interaction

test: $(addprefix test-,$(VERSIONS))

$(addprefix test-,$(VERSIONS)): test-%:
	@echo "== PHP $*"
	$(RUN) php:$*-cli vendor/bin/phpunit

lint74:
	$(RUN) php:7.4-cli sh -c 'find src bin config tests -name "*.php" -print0 | xargs -0 -n1 php -l | grep -v "^No syntax errors"; true'

guard:
	$(RUN) php:7.4-cli php tools/php74-guard.php
