#!/usr/bin/env bash
# Installs a tool the way a hook fetches a binary: into the web user's ~/.local/bin.
set -euo pipefail
mkdir -p "${HOME}/.local/bin"
printf '#!/bin/sh\necho ckinittool-ok\n' > "${HOME}/.local/bin/ckinittool"
chmod +x "${HOME}/.local/bin/ckinittool"
