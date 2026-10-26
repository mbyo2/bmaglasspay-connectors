#!/usr/bin/env bash
# The vendored BMaGlass API client must stay behaviour-identical
# across every Odoo version variant — the strongest form of that
# rule is byte-identical. If a future version genuinely needs a
# client change, it must land in ALL variants (and this check is
# how we notice when one was missed).
set -u
cd "$(dirname "$0")"
fail=0
ref="17.0/payment_bmaglass/models/bmaglass_api.py"
for v in 16.0 17.0 18.0 19.0 20.0; do
    f="$v/payment_bmaglass/models/bmaglass_api.py"
    if diff -q "$ref" "$f" >/dev/null; then
        echo "OK   odoo $v client identical"
    else
        echo "FAIL odoo $v client differs from $ref"; fail=1
    fi
done
exit $fail
