"""What tools/variants.py needs to build LEB-D-300-A's control variants."""

REFERENCE = "systems/300-A/reference"
# The reference keeps the payment index in its schema.sql; a delivery may change the schema only through migrations/, so the
# reference taken as a delivery carries it as one.
REFERENCE_ADDS = {"migrations/001_payment_invoice_index.sql": "create index payment_invoice on payment (invoice_id);\n"}
SRC = "src/main/java/com/condodesk/"
SERIAL = {"PERF-012"}
EVALUATE = "harness/java/evaluate.sh"
