# The entry point for working on civikitchen locally, and the implementation
# .github/workflows/lint.yml calls — one implementation, reachable from both
# sides. Before it, the fast test suites could be run in exactly one place:
# GitHub Actions.
#
# Every target is .PHONY: nothing here is built from file timestamps, so Make
# contributes no dependency graph — it contributes being installed everywhere
# and `make help`.

include toolbelt/versions.env

# Strict bash for every recipe: `set -eu -o pipefail` is what the shell in this
# repo already runs under, and a recipe that swallows a failing stage in a pipe
# is exactly the kind of green-but-untested that this file exists to end.
#
# A make without .SHELLFLAGS (< 3.82, e.g. Apple's 3.81) IGNORES that line and
# runs every recipe unguarded — a failing shellcheck then still prints "clean"
# and exits 0. Refuse loudly instead; `oneshell` arrived in the same release,
# so its presence in .FEATURES is the exact capability probe.
ifeq ($(filter oneshell,$(.FEATURES)),)
$(error GNU Make >= 3.82 required: this make ($(MAKE_VERSION)) ignores .SHELLFLAGS, so recipes would run without 'set -eu -o pipefail'. Run `bash scripts/doctor.sh` for this and every other host prerequisite at once)
endif

# `bash` resolved via PATH, not /bin/bash: macOS ships bash 3.2 frozen at that
# path, and a modern one is expected to be first in PATH there.
SHELL := bash
.SHELLFLAGS := -eu -o pipefail -c

# Downloads land here rather than in /tmp so a second run reuses them, and so
# `make clean` has one thing to remove. CI overrides nothing: same paths.
CACHE := .cache
PHPUNIT := $(CACHE)/phpunit-$(CK_PHPUNIT_VERSION).phar

# The pinned release IN the path: a catalog bump must invalidate the cached
# tree, or the drift gates keep testing the old core until a `make clean`.
# The four-pin agreement check stays in the recipe, which still fails loudly
# when the catalogs disagree among themselves.
CORE_VERSION := $(shell php -r 'require "toolbelt/ckconform/src/HookCatalog.php"; echo CiviKitchen\Ckconform\HookCatalog::CORE_VERSION;')
CORE := $(CACHE)/civicrm-core-$(CORE_VERSION)

# Pinned here, not in versions.env: this is their only consumer. actionlint's
# installer is a shell script piped into bash, so it gets the same treatment as
# the phars — pinned tag AND pinned content. Re-derive after a bump with
# `curl -fsSL <url> | sha256sum`.
CK_ACTIONLINT_VERSION := 1.7.12
CK_ACTIONLINT_INSTALLER_SHA256 := 72fa3e45ac20f3c3a512d6747b4fcf719e21f890e8c43e78d48a41fdfb900c4e
CK_ZIZMOR_VERSION := 1.29.0
CK_JSCPD_VERSION := 5.0.14

# zizmor and check-jsonschema are Python CLIs fetched on demand rather than
# installed. uv and pipx both do that, so demanding one specific runner would
# invent a prerequisite this repo does not have — GitHub's runners ship pipx,
# a laptop is as likely to have uv. Same pinned versions either way; only the
# pin syntax differs.
ifeq ($(shell command -v uvx >/dev/null 2>&1 && echo yes),yes)
pyrun_pinned = uvx $(1)@$(2)
pyrun = uvx $(1)
else
pyrun_pinned = pipx run $(1)==$(2)
pyrun = pipx run $(1)
endif

# shellcheck is fetched and pinned like the phars, because the sweep's verdict
# depends on the version: 0.10 grew checks 0.9 never ran, so a laptop's distro
# shellcheck and CI's disagreeing turns "one implementation for both" into two.
# One content hash per supported platform; a new platform adds its pair here.
CK_SHELLCHECK_VERSION := 0.11.0
CK_SHELLCHECK_SHA256_linux_x86_64 := 8c3be12b05d5c177a04c29e3c78ce89ac86f1595681cab149b65b97c4e227198
CK_SHELLCHECK_SHA256_darwin_aarch64 := 56affdd8de5527894dca6dc3d7e0a99a873b0f004d7aabc30ae407d3f48b0a79
# `uname -m` says arm64 on macOS but the release assets say aarch64.
SHELLCHECK_PLATFORM := $(shell uname -s | tr '[:upper:]' '[:lower:]').$(subst arm64,aarch64,$(shell uname -m))
SHELLCHECK_SHA256 := $(CK_SHELLCHECK_SHA256_$(subst .,_,$(SHELLCHECK_PLATFORM)))
SHELLCHECK := $(CACHE)/shellcheck-$(CK_SHELLCHECK_VERSION)

# What gets linted is decided by WHAT A FILE IS, never by where it lives.
#
# A directory list fails OPEN: a file it does not match is not reported as
# unchecked, it is simply absent, and the run says "clean". That happened twice
# here — a *.sh-only glob skipped every ck* tool, and the directory list that
# replaced it omitted template/, leaving three template-MANAGED files (stamped
# byte-identical into every extension repo) ungated.
#
# `if`, not `cmd && printf`: under `set -e` a non-matching grep fails the whole
# AND-list and kills the loop, silently and short. An `if` condition is exempt.
# `(*.sh)`, balanced: bash 3.2 cannot parse an unbalanced case paren inside
# `$( )` and dies on the whole substitution.
SHELL_FILES = git ls-files | while read -r f; do \
	  case "$$f" in (*.sh) printf '%s\n' "$$f"; continue ;; esac ; \
	  if head -1 "$$f" 2>/dev/null \
	      | grep -qaE '^\#!.*(\bsh\b|\bbash\b)|^\# shellcheck shell=' ; then \
	    printf '%s\n' "$$f" ; \
	  fi ; \
	done
PHP_FILES = git ls-files '*.php'

# A selector that matches nothing must be an error, not a pass. Otherwise the
# fail-open bug just moves up a level: a broken pattern would lint zero files
# and report success, which is precisely the failure mode these selectors were
# rewritten to end.
define require_nonempty
[ -n "$(1)" ] || { echo "no $(2) files matched — the selector is broken" >&2; exit 1; }
endef

.DEFAULT_GOAL := help
.PHONY: help doctor test test-ckconform test-phpstan test-ckinit test-ckcreate test-ckcivix test-parity \
	test-compose-isolation test-vendored-paths test-doctor lint lint-shell \
        lint-actions lint-php lint-schema build test-images e2e tools clean

help: ## Show this help
	@echo "civikitchen — make targets"
	@echo
	@grep -hE '^[a-z][a-z0-9-]*:.*?## ' $(MAKEFILE_LIST) \
	  | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'
	@echo
	@echo "  test and lint need no Docker. build and test-images do."

# --- the fast loop -----------------------------------------------------------

# Every host prerequisite in one pass, before a recipe fails on the first of
# them. Deliberately not a prerequisite of lint/test: those must stay usable on
# a host that is missing only what they do not touch.
doctor: ## Report every missing host prerequisite in one pass
	bash scripts/doctor.sh

test: test-ckconform test-phpstan test-ckinit test-ckcreate test-ckcivix test-parity test-compose-isolation test-vendored-paths test-doctor ## Run every fast test suite (no Docker)

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

test-ckcreate: ## ckcreate orchestration and atomic-output checks (fake Docker)
	bash tests/ckcreate/test-ckcreate.sh

test-ckcivix: ## ckcivix current/behind/missing/update checks (fake civix)
	bash tests/toolbelt/test-ckcivix.sh

# The Dockerfiles COPY the toolbelt selectively; without this gate a new tool
# lands in git and silently never reaches the images the fleet's CI runs on.
test-parity: ## Toolbelt components vs. Dockerfile COPY parity
	bash tests/parity/test-toolbelt-parity.sh

# `vendored_paths` decides what the linters never see; a regression there is
# silent by construction — the run says "clean" about files it skipped, or
# reformats third-party source that must stay byte-identical.
test-vendored-paths: ## .ckconform vendored_paths file-list exclusion
	bash tests/toolbelt/test-vendored-paths.sh

# A doctor that cannot fail is worse than none: it reports a healthy host while
# the prerequisite it was written for is absent. The fixtures shadow PATH so
# every verdict is exercised against a host that really lacks the tool.
test-doctor: ## doctor verdicts against synthesised hosts
	bash tests/toolbelt/test-doctor.sh

# Two jobs of one run sharing a compose project means the second one's
# `down -v` kills the first one's containers. Only reproducible where jobs
# share a Docker daemon, i.e. never on the runner this suite runs on.
test-compose-isolation: ## Per-job compose project names in the workflows
	bash tests/parity/test-compose-project-isolation.sh

# --- static checks -----------------------------------------------------------

lint: lint-shell lint-actions lint-php lint-schema ## Every static check CI runs

lint-shell: $(SHELLCHECK) ## shellcheck (pinned) at style level over every tracked shell file
	@files=$$($(SHELL_FILES)) ; \
	  $(call require_nonempty,$$files,shell) ; \
	  printf '%s\n' "$$files" | xargs $(SHELLCHECK) -S style ; \
	  echo "shellcheck clean ($$(printf '%s\n' "$$files" | wc -l) files)"

# actionlint reads workflows as YAML+shell; zizmor reads them as a threat model
# — unpinned actions, expression injection into run blocks, tokens wider than
# the job needs. Exemptions and their reasons live in zizmor.yml.
lint-actions: $(CACHE)/actionlint ## actionlint + zizmor over the workflows
	$(CACHE)/actionlint -color
	$(call pyrun_pinned,zizmor,$(CK_ZIZMOR_VERSION)) --no-online-audits --config zizmor.yml \
	  .github/workflows scaffold/template/extension/.github/workflows

# Informational, not part of `lint`: reports token-level clones so duplication
# gets noticed, without hard-failing on the existing backlog.
dupcheck: ## Copy-paste clone report (jscpd), advisory
	bunx jscpd@$(CK_JSCPD_VERSION) --config .jscpd.json

lint-php: ## php -l over every tracked PHP file
	@files=$$($(PHP_FILES)) ; \
	  $(call require_nonempty,$$files,PHP) ; \
	  rc=0 ; \
	  while IFS= read -r f; do php -l "$$f" >/dev/null || rc=1; done <<< "$$files" ; \
	  [ "$$rc" = 0 ] && echo "php syntax clean ($$(printf '%s\n' "$$files" | wc -l) files)"

# profile.schema.json is the canonical spec for the profile format, published
# as @jfilter/civicrm-profile-schema.
lint-schema: ## Validate every profile.json against the published schema
	$(call pyrun,check-jsonschema) \
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

tools: $(PHPUNIT) $(CACHE)/actionlint $(SHELLCHECK) ## Pre-fetch the pinned tools into .cache/

$(SHELLCHECK):
	@mkdir -p $(CACHE)
	@[ -n "$(SHELLCHECK_SHA256)" ] || { \
	  echo "no shellcheck hash pinned for $(SHELLCHECK_PLATFORM) — add CK_SHELLCHECK_SHA256_$(subst .,_,$(SHELLCHECK_PLATFORM)) to the Makefile" >&2; exit 1; }
	curl -fsSLo $@.tar.xz \
	  "https://github.com/koalaman/shellcheck/releases/download/v$(CK_SHELLCHECK_VERSION)/shellcheck-v$(CK_SHELLCHECK_VERSION).$(SHELLCHECK_PLATFORM).tar.xz"
	echo "$(SHELLCHECK_SHA256)  $@.tar.xz" | sha256sum -c --strict -
	tar -xJf $@.tar.xz -C $(CACHE) --strip-components=1 shellcheck-v$(CK_SHELLCHECK_VERSION)/shellcheck
	mv $(CACHE)/shellcheck $@
	rm $@.tar.xz

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
	ver="$(CORE_VERSION)"; \
	[ -n "$$ver" ] || { echo "could not read HookCatalog::CORE_VERSION (is php installed?)" >&2; exit 1; }; \
	nsver=$$(php -r 'require "toolbelt/phpstan/src/CoreNamespaceCatalog.php"; echo CiviKitchen\PHPStan\CoreNamespaceCatalog::CORE_VERSION;'); \
	apiver=$$(php -r 'require "toolbelt/phpstan/src/Api4Catalog.php"; echo CiviKitchen\PHPStan\Api4Catalog::CORE_VERSION;'); \
	schemaver=$$(php -r 'require "toolbelt/phpstan/src/SchemaCatalog.php"; echo CiviKitchen\PHPStan\SchemaCatalog::CORE_VERSION;'); \
	if [ "$$ver" != "$$nsver" ] || [ "$$ver" != "$$apiver" ] || [ "$$ver" != "$$schemaver" ]; then \
	  echo "catalog pins disagree: hooks=$$ver namespaces=$$nsver api4=$$apiver schema=$$schemaver — regenerate them from one release" >&2; \
	  exit 1; \
	fi; \
	echo "pinned CiviCRM $$ver"; \
	mkdir -p $(CACHE); \
	curl -fsSLo $(CORE).tar.gz \
	  "https://github.com/civicrm/civicrm-core/archive/refs/tags/$$ver.tar.gz"; \
	mkdir -p $(CORE).tmp; \
	tar -xzf $(CORE).tar.gz -C $(CORE).tmp --strip-components=1; \
	rm $(CORE).tar.gz; \
	mv $(CORE).tmp $(CORE)

clean: ## Remove .cache/ (fetched phars and the CiviCRM source tree)
	rm -rf $(CACHE)
