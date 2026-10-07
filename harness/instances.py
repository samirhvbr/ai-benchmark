"""Locates an instance on disk. One resolver for `leb`, `pack.py`, `leb_harness.py` and `export-results.py`.

Two layouts are understood:

    legacy   instances/<id>/{code/, manifest.md, characterization/, private/}
    split    instances/<id>/{public/{code/, manifest.md}, private/}

`public/` means "material meant for the candidate"; it is NOT permission to publish anything.

Where to look, in order: each directory listed in LEB_INSTANCES_PATH (a ':'-separated list; each entry is a root that
holds an `instances/` folder, for example a private root kept outside this repository), then this repository's own
`instances/`, then the argument itself as a path. Without LEB_INSTANCES_PATH nothing changes.
"""

import os
import sys


class Instance:
    def __init__(self, root, layout):
        self.root = os.path.abspath(root)
        self.layout = layout
        self.name = os.path.basename(self.root)
        self.public_dir = self.root if layout == "legacy" else os.path.join(self.root, "public")
        self.private_dir = os.path.join(self.root, "private")

    code_dir = property(lambda self: os.path.join(self.public_dir, "code"))
    manifest_path = property(lambda self: os.path.join(self.public_dir, "manifest.md"))
    matrix_path = property(lambda self: os.path.join(self.private_dir, "matrix.json"))
    runner_path = property(lambda self: os.path.join(self.private_dir, "runner.json"))


def layout_of(path):
    """'split', 'legacy' or None for a directory."""
    if os.path.isdir(os.path.join(path, "public", "code")):
        return "split"
    if os.path.isdir(os.path.join(path, "code")):
        return "legacy"
    return None


def search_roots(repo_root):
    roots = [os.path.join(p, "instances") for p in os.environ.get("LEB_INSTANCES_PATH", "").split(":") if p]
    roots.append(os.path.join(repo_root, "instances"))
    return roots


def find(name, repo_root):
    """The Instance for a name or a path, or None."""
    candidates = [os.path.join(r, name) for r in search_roots(repo_root)]
    candidates += [os.path.join(repo_root, name), name]
    for cand in candidates:
        layout = layout_of(cand) if os.path.isdir(cand) else None
        if layout:
            return Instance(cand, layout)
    return None


def resolve(name, repo_root):
    inst = find(name, repo_root)
    if inst is None:
        sys.exit("[leb] instância desconhecida: %s (veja ./leb instancias)" % name)
    return inst


def list_instances(repo_root):
    seen = {}
    for root in search_roots(repo_root):
        if not os.path.isdir(root):
            continue
        for name in sorted(os.listdir(root)):
            if name not in seen and layout_of(os.path.join(root, name)):
                seen[name] = Instance(os.path.join(root, name), layout_of(os.path.join(root, name)))
    return [seen[n] for n in sorted(seen)]


def runs_dir(repo_root):
    """Where run areas and packages go. LEB_RUNS_DIR moves them out of the repository tree."""
    return os.path.abspath(os.environ.get("LEB_RUNS_DIR") or os.path.join(repo_root, "runs"))
