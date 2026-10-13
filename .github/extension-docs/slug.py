"""Heading ids as the handbook extension renders them (league/commonmark's SlugNormalizer)."""

import re
import unicodedata


def slugify(text, sep):
    """Lower case, whitespace runs to one dash, then only letters, marks, digits and dashes."""
    slug = re.sub(r"\s+", "-", text.strip().lower())
    return "".join(c for c in slug if c == "-" or unicodedata.category(c)[0] in "LM" or unicodedata.category(c) in ("Nd", "Nl"))
