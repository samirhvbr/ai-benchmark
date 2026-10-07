"""A tiny JSON Schema validator (stdlib only) for the subset the repository's schemas use: type, enum, const, pattern, minimum, maximum,
required, properties, additionalProperties (false or a schema), items, minItems, uniqueItems and local $ref. It exists so the tooling
can check what it writes without a third-party package; it is not a general implementation."""

import json
import re


def validate(value, schema, root=None, path="$"):
    """Return a list of error strings. Covers the subset the repository's schemas use."""
    root = root if root is not None else schema
    if "$ref" in schema:
        node = root
        for part in schema["$ref"].lstrip("#/").split("/"):
            node = node[part]
        return validate(value, node, root, path)
    errs = []
    t = schema.get("type")
    if t:
        names = t if isinstance(t, list) else [t]
        py = {"object": dict, "array": list, "string": str, "integer": int, "number": (int, float),
              "boolean": bool, "null": type(None)}
        if not any(isinstance(value, py[n]) and not (n in ("integer", "number") and isinstance(value, bool)) for n in names):
            return ["%s: expected %s" % (path, t)]
    if "const" in schema and value != schema["const"]:
        errs.append("%s: %r is not %r" % (path, value, schema["const"]))
    if "enum" in schema and value not in schema["enum"]:
        errs.append("%s: %r not in %s" % (path, value, schema["enum"]))
    if "pattern" in schema and isinstance(value, str) and not re.search(schema["pattern"], value):
        errs.append("%s: %r does not match %s" % (path, value, schema["pattern"]))
    for key, op in (("minimum", lambda a, b: a < b), ("maximum", lambda a, b: a > b)):
        if key in schema and isinstance(value, (int, float)) and not isinstance(value, bool) and op(value, schema[key]):
            errs.append("%s: violates %s %s" % (path, key, schema[key]))
    if isinstance(value, dict):
        for req in schema.get("required", []):
            if req not in value:
                errs.append("%s: missing %s" % (path, req))
        props = schema.get("properties", {})
        for k, v in value.items():
            if k in props:
                errs += validate(v, props[k], root, path + "." + k)
            elif isinstance(schema.get("additionalProperties"), dict):
                errs += validate(v, schema["additionalProperties"], root, path + "." + k)
            elif schema.get("additionalProperties") is False:
                errs.append("%s: property not allowed: %s" % (path, k))
    if isinstance(value, list):
        if "minItems" in schema and len(value) < schema["minItems"]:
            errs.append("%s: too few items" % path)
        if schema.get("uniqueItems") and len({json.dumps(i, sort_keys=True) for i in value}) != len(value):
            errs.append("%s: duplicate items" % path)
        if "items" in schema:
            for i, v in enumerate(value):
                errs += validate(v, schema["items"], root, "%s[%d]" % (path, i))
    return errs
