"""Build an extension's handbook/ directory into a Zensical site, one per language.

usage: build.py <extension-dir> <site-dir> <lang> [<lang> ...]

The first language is the default: page.md, served at the site root. Every
further language is built into <site-dir>/<lang>/ from page.<lang>.md where it
exists and page.md where not, the fallback the handbook extension applies in
CiviCRM. Title, order and section come from each page's frontmatter, as there.
Runs under the Python that has zensical installed.
"""

import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path

import yaml

LANG = re.compile(r"^[a-z]{2}(_[A-Z]{2})?$")
LANG_NAMES = {"en": "English", "de": "Deutsch", "fr": "Français", "es": "Español", "it": "Italiano", "nl": "Nederlands"}
# The theme's indigo accent colours the active navigation link below 4.5:1 in both schemes.
CONTRAST_CSS = """[data-md-color-scheme="default"] { --md-accent-fg-color: #3949ab; --md-accent-fg-color--transparent: #3949ab1a; }
[data-md-color-scheme="slate"] { --md-accent-fg-color: #8c9eff; --md-accent-fg-color--transparent: #8c9eff1a; }
"""


def die(message):
    print(f"extension-docs: {message}", file=sys.stderr)
    sys.exit(1)


def frontmatter(path):
    text = path.read_text(encoding="utf-8")
    match = re.match(r"^---\n(.*?)\n---\n", text, re.S)
    data = yaml.safe_load(match.group(1)) if match else {}
    if not isinstance(data, dict):
        die(f"{path}: frontmatter is not a mapping")
    if "title" not in data:
        heading = re.search(r"^# (.+)$", text, re.M)
        data["title"] = heading.group(1).strip() if heading else path.stem
    return data


def split_name(name, languages):
    """('events', 'de') for events.de.md, ('events', None) for events.md."""
    stem = name[:-3]
    base, _, suffix = stem.rpartition(".")
    if base and LANG.match(suffix):
        if suffix not in languages:
            die(f"{name}: language {suffix} is not built; add it to the languages input or rename the file")
        return base, suffix
    return stem, None


def nav(pages, lang, source):
    """Landing page first, then by order and title; a section sits where its first page sorts."""
    entries = []
    for stem, variants in pages.items():
        meta = frontmatter(source / variants.get(lang, variants[None]))
        if stem == "index":
            continue
        order = meta.get("order")
        key = (0 if isinstance(order, int) else 1, order if isinstance(order, int) else 0, str(meta["title"]).casefold())
        section = str(meta.get("section") or "").strip()
        entries.append((key, section, {str(meta["title"]): f"{stem}.md"}))
    entries.sort(key=lambda e: e[0])
    out, groups = [], {}
    if "index" in pages:
        out.append({frontmatter(source / pages["index"].get(lang, pages["index"][None]))["title"]: "index.md"})
    for _, section, item in entries:
        if not section:
            out.append(item)
        elif section in groups:
            groups[section].append(item)
        else:
            groups[section] = [item]
            out.append({section: groups[section]})
    return out


def main():
    if len(sys.argv) < 4:
        die("usage: build.py <extension-dir> <site-dir> <lang> [<lang> ...]")
    ext, site, languages = Path(sys.argv[1]).resolve(), Path(sys.argv[2]).resolve(), sys.argv[3:]
    for lang in languages:
        if not LANG.match(lang):
            die(f"{lang}: not a language code like de or de_DE")
    source = ext / "handbook"
    if not source.is_dir():
        die(f"{source} does not exist")
    info = ET.parse(ext / "info.xml").getroot()
    name = (info.findtext("name") or info.get("key") or ext.name).strip()
    description = " ".join((info.findtext("description") or "").split())

    pages = {}
    for path in sorted(source.glob("*.md")):
        stem, lang = split_name(path.name, languages[1:])
        pages.setdefault(stem, {})[lang] = path.name
    orphans = [v for p in pages.values() if None not in p for v in p.values()]
    if orphans:
        die(f"translations without a default page: {', '.join(orphans)}")
    if not pages:
        die(f"{source} holds no Markdown pages")

    repo = os.environ.get("GITHUB_REPOSITORY", "")
    owner, _, repo_name = repo.partition("/")
    site_url = f"https://{owner}.github.io/{repo_name}/" if repo else None
    if site.exists():
        shutil.rmtree(site)
    with tempfile.TemporaryDirectory() as tmp:
        for index, lang in enumerate(languages):
            docs = Path(tmp) / lang / "docs"
            # Images and other assets are shared; pages are the language's variant.
            shutil.copytree(source, docs, ignore=shutil.ignore_patterns("*.md"))
            for stem, variants in pages.items():
                shutil.copyfile(source / variants.get(lang, variants[None]), docs / f"{stem}.md")
            (docs / "stylesheets").mkdir(exist_ok=True)
            (docs / "stylesheets" / "civikitchen-contrast.css").write_text(CONTRAST_CSS)
            prefix = "" if index == 0 else f"{lang}/"
            config = {
                "site_name": name,
                "site_description": description,
                "docs_dir": "docs",
                "site_dir": "site",
                "extra_css": ["stylesheets/civikitchen-contrast.css"],
                "nav": nav(pages, lang, source),
                "theme": {
                    "language": lang.split("_")[0],
                    "features": ["content.code.copy", "navigation.footer", "navigation.top", "search.highlight", "toc.follow"],
                    "palette": [
                        {"media": "(prefers-color-scheme: light)", "scheme": "default", "primary": "indigo", "accent": "indigo",
                         "toggle": {"icon": "lucide/sun", "name": "Switch to dark mode"}},
                        {"media": "(prefers-color-scheme: dark)", "scheme": "slate", "primary": "indigo", "accent": "indigo",
                         "toggle": {"icon": "lucide/moon", "name": "Switch to light mode"}},
                    ],
                },
                "markdown_extensions": {"tables": {}, "toc": {"permalink": True}, "pymdownx.highlight": {},
                                        "pymdownx.inlinehilite": {}, "pymdownx.superfences": {}},
            }
            if site_url:
                config["site_url"] = site_url + prefix
                config["repo_url"] = f"https://github.com/{repo}"
                config["repo_name"] = repo
            if len(languages) > 1:
                config["extra"] = {"alternate": [
                    {"name": LANG_NAMES.get(other.split("_")[0], other), "link": (site_url or "/") + ("" if i == 0 else f"{other}/"), "lang": other.split("_")[0]}
                    for i, other in enumerate(languages)]}
            config_file = Path(tmp) / lang / "mkdocs.yml"
            # JSON is YAML, and Zensical reads an mkdocs.yml.
            config_file.write_text(json.dumps(config, ensure_ascii=False))
            # Zensical keeps site_dir inside the config's directory.
            subprocess.run([sys.executable, "-m", "zensical", "build", "--strict", "-f", str(config_file)], check=True)
            shutil.copytree(Path(tmp) / lang / "site", site / prefix, dirs_exist_ok=True)


if __name__ == "__main__":
    main()
