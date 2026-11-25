#!/usr/bin/env python3
"""Archive new TAPinto Piscataway articles from the public Atom feed (text, metadata, images). Run daily.

Usage: archive_tapinto.py [outdir]   (default: data/tapinto)
Writes outdir/<date>_<slug>/{meta.json,article.html,article.txt,<images>}, same layout as archive_insidernj.py.
Skips the sports section. Articles whose <updated> changed get their text/meta rewritten.
"""
import json, os, sys, xml.etree.ElementTree as ET
from archive_insidernj import get, parse, save_images, write_post

FEED = 'https://www.tapinto.net/towns/piscataway/articles.atom'
UA = {'User-Agent': 'Mozilla/5.0 (compatible; FeedReader/1.0)'}  # CloudFront 403s curl/python/bare Mozilla UAs
SKIP_SECTIONS = {'sports'}
NS = {'a': 'http://www.w3.org/2005/Atom', 'm': 'http://search.yahoo.com/mrss/'}


def main():
    out = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(__file__), '..', 'web', 'files', 'tapinto')
    body, _ = get(FEED, UA)
    for e in ET.fromstring(body).findall('a:entry', NS):
        url = e.find('a:link', NS).get('href')
        parts = url.split('/')
        section = parts[parts.index('sections') + 1] if 'sections' in parts else None
        if section in SKIP_SECTIONS:
            continue
        updated = e.findtext('a:updated', '', NS)
        d = os.path.join(out, f"{e.findtext('a:published', '', NS)[:10]}_{parts[-1]}")
        meta_file = os.path.join(d, 'meta.json')
        if os.path.exists(meta_file) and json.load(open(meta_file)).get('modified') == updated:
            continue
        os.makedirs(d, exist_ok=True)
        content = e.findtext('a:content', '', NS)
        media = [m.get('url') for m in e.findall('m:content', NS) if m.get('url')]
        meta = {
            'id': e.findtext('a:id', '', NS), 'url': url, 'title': parse(e.findtext('a:title', '', NS)).text().strip(),
            'date': e.findtext('a:published', '', NS), 'modified': updated,
            'author': [a.findtext('a:name', '', NS) for a in e.findall('a:author', NS)],
            'byline': e.findtext('a:byline', None, NS), 'credits': e.findtext('a:credits', None, NS),
            'section': section,
            'images': save_images(d, url, media + parse(content).imgs, UA),
        }
        write_post(d, meta, content)
        print(d)


if __name__ == '__main__':
    main()
