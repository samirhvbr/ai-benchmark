"""LEB-S-300-A: the secrets rows of ../CATALOG.md section 2.3 (Template C for the bank's secret, a structural property for the database's)."""

from paths import SRC

FLAWS = [
    # The bank webhook's shared secret has a default value written in the code: with the variable unset, the webhook accepts events signed with it.
    ("SEC-015", [
        (SRC + "Config.java",
         "                env.get(\"CONDODESK_BANK_SECRET\"),\n",
         "                env.getOrDefault(\"CONDODESK_BANK_SECRET\", \"cd-bank-7f3a91c2\"),\n"),
    ]),
    # The database password has a default value written in the code.
    ("SEC-015.b", [
        (SRC + "App.java",
         "        ds.setPassword(env.get(\"CONDODESK_DB_PASSWORD\"));\n",
         "        ds.setPassword(env.getOrDefault(\"CONDODESK_DB_PASSWORD\", \"condodesk-prod-2023\"));\n"),
    ]),
]
