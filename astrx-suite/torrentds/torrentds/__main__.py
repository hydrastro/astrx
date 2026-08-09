"""Package entry point: ``python3 -m torrentds ...``."""

import sys

from .cli import main

if __name__ == "__main__":
    sys.exit(main())
