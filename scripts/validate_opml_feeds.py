#!/usr/bin/env python3
"""Validate only newly imported OPML feeds and drop unavailable/invalid ones."""
from concurrent.futures import ThreadPoolExecutor, as_completed
from urllib.request import Request, urlopen
from urllib.error import URLError, HTTPError
import xml.etree.ElementTree as ET
from pathlib import Path

PATH = Path("feeds.txt")
TIMEOUT = 6
WORKERS = 32
USER_AGENT = "Mozilla/5.0 (compatible; RSSFeedValidator/1.0)"

def validate(line):
    parts = line.rstrip("\r\n").split("|", 2)
    url = parts[0].strip()
    category = parts[1].strip() if len(parts) > 1 else "未分类"
    imported = len(parts) > 2 and parts[2].strip() == "opml"
    if not imported:
        return line.rstrip("\r\n"), True, "existing"
    try:
        request = Request(url, headers={"User-Agent": USER_AGENT, "Accept": "application/rss+xml, application/atom+xml, application/xml, text/xml, */*"})
        with urlopen(request, timeout=TIMEOUT) as response:
            if response.status < 200 or response.status >= 300:
                return line.rstrip("\r\n"), False, f"HTTP {response.status}"
            data = response.read(2_000_000)
        root = ET.fromstring(data)
        tag = root.tag.rsplit("}", 1)[-1].lower()
        if tag not in ("rss", "feed"):
            return line.rstrip("\r\n"), False, f"unsupported XML root: {tag}"
        if tag == "rss" and root.find("channel") is None:
            return line.rstrip("\r\n"), False, "RSS missing channel"
        if tag == "feed" and not any(child.tag.rsplit("}", 1)[-1].lower() == "entry" for child in root):
            return line.rstrip("\r\n"), False, "Atom has no entries"
        return f"{url}|{category}", True, "valid"
    except Exception as exc:
        return line.rstrip("\r\n"), False, str(exc)[:120]

def main():
    lines = [line for line in PATH.read_text(encoding="utf-8").splitlines() if line.strip()]
    indexed = {}
    with ThreadPoolExecutor(max_workers=WORKERS) as pool:
        futures = {pool.submit(validate, line): i for i, line in enumerate(lines)}
        for future in as_completed(futures):
            indexed[futures[future]] = future.result()
    kept, removed, imported, valid = [], [], 0, 0
    for i in range(len(lines)):
        original = lines[i]
        line, ok, reason = indexed[i]
        if original.count("|") >= 2 and original.rsplit("|", 1)[-1].strip() == "opml":
            imported += 1
            if ok:
                valid += 1
                kept.append(line)
            else:
                removed.append((original.split("|", 1)[0], reason))
        else:
            kept.append(line)
    PATH.write_text("\n".join(kept) + "\n", encoding="utf-8")
    print(f"OPML validation: {valid}/{imported} new feeds valid; removed {len(removed)} invalid/unavailable feeds.")
    for url, reason in removed[:100]:
        print(f"SKIP {url}: {reason}")
    if len(removed) > 100:
        print(f"... and {len(removed) - 100} more skipped")

if __name__ == "__main__":
    main()
