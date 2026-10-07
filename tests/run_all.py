#!/usr/bin/env python3
"""Run every tooling test. Standard library only, no network, no Docker.

    python3 tests/run_all.py            # all tests
    python3 tests/run_all.py -k score   # only tests whose name contains "score"
"""

import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

if __name__ == "__main__":
    pattern = "test_*.py"
    argv = [sys.argv[0], "discover", "-s", HERE, "-t", HERE, "-p", pattern, "-v"] + sys.argv[1:]
    unittest.main(module=None, argv=argv)
