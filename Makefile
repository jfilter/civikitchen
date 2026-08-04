# The entry point for working on civikitchen locally — and the implementation
# CI calls, not a second copy of it.
#
# That direction matters. Before this file, the fast test suites (ckconform,
# the phpstan extension) could be run in exactly one place: GitHub Actions. To
# run them on a laptop you had to read lint.yml and replay it by hand — check
# the catalog pins agree, fetch a CiviCRM source tree, fetch a checksum-pinned
# phpunit phar, set CIVICRM_CORE_DIR. That is why .github/workflows/lint.yml
# now consists of `make` calls: one implementation, reachable from both sides.
#
# Every target is .PHONY. Nothing here is a file built from other files, so
# Make contributes no dependency graph — what it contributes is being installed
# everywhere and `make help` being the first thing anyone tries.

include toolbelt/versions.env

# Strict bash for every recipe: `set -eu -o pipefail` is what the shell in this
# repo already runs under, and a recipe that swallows a failing stage in a pipe
# is exactly the kind of green-but-untested that this file exists to end.
SHELL := /bin/bash
.SHELLFLAGS := -eu -o pipefail -c

# Downloads land here rather than in /tmp so a second run reuses them, and so
# `make clean` has one thing to remove. CI overrides nothing: same paths.
CACHE := .cache
PHPUNIT := $(CACHE)/phpunit-$(CK_PHPUNIT_VERSION).phar
CORE := $(CACHE)/civicrm-core

# Pinned here, not in versions.env: this is their only consumer. actionlint's
# installer is a shell script piped into bash, so it gets the same treatment as
# the phars — pinned tag AND pinned content. Re-derive after a bump with
# `curl -fsSL <url> | sha256sum`.
CK_ACTIONLINT_VERSION := 1.7.12
CK_ACTIONLINT_INSTALLER_SHA256 := 72fa3e45ac20f3c3a512d6747b4fcf719e21f890e8c43e78d48a41fdfb900c4e
CK_ZIZMOR_VERSION := 1.29.0

# Shell trees to check: everything with a .sh suffix, plus the ck* tools, which
# carry none (they are commands on PATH, not scripts you source) and were the
# shell in this repo running in the most places while a *.sh-only sweep skipped
# every one of them.
SHELL_DIRS := docker toolbelt examples tools tests
PHP_DIRS := docker toolbelt tools

.DEFAULT_GOAL := help
.PHONY: help test test-ckconform test-phpstan test-ckinit lint lint-shell \
        lint-actions lint-php lint-schema build test-images e2e tools clean

help: ## Show this help
	@echo "civikitchen — make targets"
	@echo
	@grep -hE '^[a-z][a-z0-9-]*:.*?## ' $(MAKEFILE_LIST) \
	  | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'
	@echo
	@echo "  test and lint need no Docker. build and test-images do."

# --- the fast loop -----------------------------------------------------------

test: test-ckconform test-phpstan test-ckinit ## Run every fast test suite (no Docker)

# The catalogs are generated from a CiviCRM release and rot on their own: core
# adds hooks and namespaces every release, and a stale catalog reports them as
# typos. The drift tests regenerate and diff — but they SKIP without a core
# checkout, so without $(CORE) the suite goes green while the gate never runs.
test-ckconform: $(PHPUNIT) $(CORE) ## ckconform's check fixtures + catalog drift gates
	CIVICRM_CORE_DIR=$(abspath $(CORE)) \
	  php $(PHPUNIT) -c toolbelt/ckconform/phpunit.xml.dist

# The rule fixtures run through phpstan's own test harness, so this package
# needs its vendor tree; the drift gates alone would not.
test-phpstan: $(PHPUNIT) $(CORE) ## The phpstan extension's rule tests + catalog drift gates
	composer install --no-interaction --no-progress --working-dir=toolbelt/phpstan
	CIVICRM_CORE_DIR=$(abspath $(CORE)) \
	  php $(PHPUNIT) -c toolbelt/phpstan/phpunit.xml.dist

# ckinit stamps the template into every extension repo and its --check mode
# gates those repos' CI, so a silent regression here either rewrites files it
# must not touch or waves drift through.
test-ckinit: ## ckinit seed/update/check integration checks
	bash tests/ckinit/test-ckinit.sh

# --- static checks -----------------------------------------------------------

lint: lint-shell lint-actions lint-php lint-schema ## Every static check CI runs

lint-shell: ## shellcheck at style level, fails on any finding
	@find $(SHELL_DIRS) -name '*.sh' -print0 | xargs -0 shellcheck -S style
	@# -not -name '*.php': a ck* tool may have a PHP payload beside it, and
	@# shellcheck handed a PHP file reports a hundred findings about a file
	@# that is not shell at all.
	@find toolbelt/bin -maxdepth 1 -type f -name 'ck*' -not -name '*.php' -print0 \
	  | xargs -0 shellcheck -S style
	@echo "shellcheck clean"

# actionlint reads workflows as YAML+shell; zizmor reads them as a threat model
# — unpinned actions, expression injection into run blocks, tokens wider than
# the job needs. Exemptions and their reasons live in zizmor.yml.
lint-actions: $(CACHE)/actionlint ## actionlint + zizmor over the workflows
	$(CACHE)/actionlint -color
	pipx run zizmor==$(CK_ZIZMOR_VERSION) --no-online-audits --config zizmor.yml \
	  .github/workflows template/extension/.github/workflows

lint-php: ## php -l over every PHP file that ships
	@rc=0; while IFS= read -r -d '' f; do php -l "$$f" >/dev/null || rc=1; done \
	  < <(find $(PHP_DIRS) -name '*.php' -print0); \
	  [ "$$rc" = 0 ] && echo "php syntax clean"

# profile.schema.json is the canonical spec for the profile format, published
# as @jfilter/civicrm-profile-schema.
lint-schema: ## Validate every profile.json against the published schema
	pipx run check-jsonschema \
	  --schemafile packages/civicrm-profile-schema/profile.schema.json \
	  docker/profiles/*/profile.json

# --- the slow loop (needs Docker) --------------------------------------------

build: ## Build the standalone image locally as civikitchen:standalone
	docker build -f docker/standalone/Dockerfile -t civikitchen:standalone .

test-images: ## Boot tests + dev-tool tests against the published images (~1 h)
	bash tests/images/run-local.sh

e2e: ## Playwright smoke tests against a running compose stack
	npm run test:e2e

# --- fetched inputs ----------------------------------------------------------

tools: $(PHPUNIT) $(CACHE)/actionlint ## Pre-fetch the pinned tools into .cache/

$(PHPUNIT):
	@mkdir -p $(CACHE)
	curl -fsSLo $@ "https://phar.phpunit.de/phpunit-$(CK_PHPUNIT_VERSION).phar"
	echo "$(CK_PHPUNIT_SHA256)  $@" | sha256sum -c --strict -

$(CACHE)/actionlint:
	@mkdir -p $(CACHE)
	curl -fsSL -o $(CACHE)/download-actionlint.bash \
	  "https://raw.githubusercontent.com/rhysd/actionlint/v$(CK_ACTIONLINT_VERSION)/scripts/download-actionlint.bash"
	echo "$(CK_ACTIONLINT_INSTALLER_SHA256)  $(CACHE)/download-actionlint.bash" | sha256sum -c --strict -
	bash $(CACHE)/download-actionlint.bash $(CK_ACTIONLINT_VERSION) $(CACHE)

# All four catalogs must pin the SAME release, or the drift gates would each
# test a different core and none of them would say so. The namespace generator
# scans the whole tree (CRM/, Civi/, ext/), hence a source tree rather than
# single files.
$(CORE):
	@set -eu; \
	ver=$$(php -r 'require "toolbelt/ckconform/src/HookCatalog.php"; echo CiviKitchen\Ckconform\HookCatalog::CORE_VERSION;'); \
	nsver=$$(php -r 'require "toolbelt/phpstan/src/CoreNamespaceCatalog.php"; echo CiviKitchen\PHPStan\CoreNamespaceCatalog::CORE_VERSION;'); \
	apiver=$$(php -r 'require "toolbelt/phpstan/src/Api4Catalog.php"; echo CiviKitchen\PHPStan\Api4Catalog::CORE_VERSION;'); \
	schemaver=$$(php -r 'require "toolbelt/phpstan/src/SchemaCatalog.php"; echo CiviKitchen\PHPStan\SchemaCatalog::CORE_VERSION;'); \
	if [ "$$ver" != "$$nsver" ] || [ "$$ver" != "$$apiver" ] || [ "$$ver" != "$$schemaver" ]; then \
	  echo "catalog pins disagree: hooks=$$ver namespaces=$$nsver api4=$$apiver schema=$$schemaver — regenerate them from one release" >&2; \
	  exit 1; \
	fi; \
	echo "pinned CiviCRM $$ver"; \
	mkdir -p $(CACHE); \
	curl -fsSLo $(CACHE)/civicrm-core.tar.gz \
	  "https://github.com/civicrm/civicrm-core/archive/refs/tags/$$ver.tar.gz"; \
	mkdir -p $(CORE).tmp; \
	tar -xzf $(CACHE)/civicrm-core.tar.gz -C $(CORE).tmp --strip-components=1; \
	mv $(CORE).tmp $(CORE)

clean: ## Remove .cache/ (fetched phars and the CiviCRM source tree)
	rm -rf $(CACHE)
