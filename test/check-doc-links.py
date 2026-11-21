"""Validate inline relative links and heading fragments in repository Markdown."""

import re
from pathlib import Path
from urllib.parse import unquote, urlsplit

ROOT = Path(__file__).resolve().parents[1]
LINK = re.compile(r"\[[^\]\n]+\]\(([^\s)]+)\)")
HEADING = re.compile(r"^#{1,6} +(.+)$", re.MULTILINE)


def anchors(document):
    return {
        re.sub(r"[^\w -]", "", heading.lower()).replace(" ", "-")
        for heading in HEADING.findall(document.read_text(encoding="utf-8"))
    }


def main():
    documents = sorted(
        [*ROOT.glob("*.md"), *(ROOT / "docs").rglob("*.md"), *(ROOT / ".github").rglob("*.md")]
    )
    failures = []
    count = 0
    for document in documents:
        for link in LINK.findall(document.read_text(encoding="utf-8")):
            url = urlsplit(link)
            if url.scheme or url.netloc:
                continue
            count += 1
            target = (document.parent / unquote(url.path)).resolve() if url.path else document
            if not target.is_relative_to(ROOT) or not target.exists():
                failures.append(f"{document.relative_to(ROOT)} has an invalid target: {link}")
            elif url.fragment and (not target.is_file() or unquote(url.fragment) not in anchors(target)):
                failures.append(f"{document.relative_to(ROOT)} has an invalid anchor: {link}")
    if failures:
        raise SystemExit("\n".join(failures))
    print(f"Checked {count} relative links in {len(documents)} Markdown files")


if __name__ == "__main__":
    main()
