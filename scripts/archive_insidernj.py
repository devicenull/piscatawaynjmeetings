#!/usr/bin/env python3
"""Archive InsiderNJ posts matching a search term (text, metadata, images) from the site's search RSS feed.

Usage: archive_insidernj.py [term] [outdir] [max_pages]   (defaults: piscataway, web/files/insidernj, 1)
Writes outdir/<date>_<slug>/{meta.json,article.html,article.txt,<images>}; posts already on disk are skipped.
Uses the search feed rather than the REST API: REST search misses press releases (post type not exposed in REST).
"""
import html, html.parser, json, os, re, sys, time, urllib.error, urllib.parse, urllib.request
import xml.etree.ElementTree as ET
from email.utils import parsedate_to_datetime
from zoneinfo import ZoneInfo

UA = {'User-Agent': 'Mozilla/5.0'}
SEARCH_FEED = 'https://www.insidernj.com/'
NS = {'content': 'http://purl.org/rss/1.0/modules/content/', 'dc': 'http://purl.org/dc/elements/1.1/'}


def get(url, headers=UA):
    with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=60) as r:
        return r.read(), r.headers


class Text(html.parser.HTMLParser):
    """Collect text (block tags -> newlines) and <img> srcs."""
    BLOCK = {'p', 'div', 'br', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'tr', 'figcaption'}

    def __init__(self):
        super().__init__()
        self.parts, self.imgs, self.skip = [], [], 0

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag in ('script', 'style'):
            self.skip += 1
        elif tag == 'img':
            src = a.get('data-src') or a.get('src')
            if src and not src.startswith('data:'):
                self.imgs.append(src)
        elif tag in self.BLOCK:
            self.parts.append('\n')

    def handle_endtag(self, tag):
        if tag in ('script', 'style'):
            self.skip -= 1
        elif tag in self.BLOCK:
            self.parts.append('\n')

    def handle_data(self, d):
        if not self.skip:
            self.parts.append(d)

    def text(self):
        return re.sub(r'\n\s*\n+', '\n\n', ''.join(self.parts)).strip() + '\n'


def parse(h):
    t = Text()
    t.feed(h)
    return t


def save_images(d, base_url, srcs, headers=UA):
    """Download each image into d (skipping existing files); return {url: filename or None}."""
    out = {}
    for src in dict.fromkeys(srcs):
        src = urllib.parse.urljoin(base_url, src)
        name = os.path.basename(urllib.parse.urlparse(src).path) or 'image'
        out[src] = name
        f = os.path.join(d, name)
        if os.path.exists(f):
            continue
        try:
            data, _ = get(src, headers)
            with open(f, 'wb') as fh:
                fh.write(data)
        except Exception as e:  # dead image links shouldn't kill the run
            print(f'  image failed {src}: {e}')
            out[src] = None
        time.sleep(0.5)
    return out


def write_post(d, meta, content):
    with open(os.path.join(d, 'meta.json'), 'w') as fh:
        json.dump(meta, fh, indent=2, ensure_ascii=False)
    with open(os.path.join(d, 'article.html'), 'w') as fh:
        fh.write(content)
    with open(os.path.join(d, 'article.txt'), 'w') as fh:
        fh.write(f"{meta['title']}\n{meta['date']} | {', '.join(meta['author'])}\n{meta['url']}\n\n{parse(content).text()}")


def main():
    term = sys.argv[1] if len(sys.argv) > 1 else 'piscataway'
    out = sys.argv[2] if len(sys.argv) > 2 else os.path.join(os.path.dirname(__file__), '..', 'web', 'files', 'insidernj')
    max_pages = int(sys.argv[3]) if len(sys.argv) > 3 else 1  # 10 items/page; 1 is plenty for a daily run
    for page in range(1, max_pages + 1):
        q = urllib.parse.urlencode({'s': term, 'feed': 'rss2', 'paged': page})
        try:
            body, _ = get(f'{SEARCH_FEED}?{q}')
        except urllib.error.HTTPError as e:
            if e.code == 404:  # past the last page
                break
            raise
        body = re.sub(rb'<script\b.*?</script>', b'', body, flags=re.S)  # site injects unescaped <script> junk that breaks the XML
        items = ET.fromstring(body).findall('channel/item')
        if not items:
            break
        for i in items:
            save_item(i, out)
        time.sleep(1)


def save_item(i, out):
    url = i.findtext('link')
    date = parsedate_to_datetime(i.findtext('pubDate')).astimezone(ZoneInfo('America/New_York'))
    d = os.path.join(out, f"{date:%Y-%m-%d}_{url.rstrip('/').split('/')[-1]}")
    if os.path.exists(os.path.join(d, 'meta.json')):
        return
    os.makedirs(d, exist_ok=True)
    guid = urllib.parse.parse_qs(urllib.parse.urlparse(i.findtext('guid')).query)
    content = i.findtext(f'{{{NS["content"]}}}encoded', '')
    page, _ = get(url)  # feed has no featured image; take it from og:image
    og = re.search(rb'<meta property="og:image" content="([^"]+)"', page)
    featured = html.unescape(og.group(1).decode()) if og else None
    meta = {
        'id': int(guid['p'][0]) if 'p' in guid else None, 'post_type': guid.get('post_type', ['post'])[0],
        'url': url, 'title': parse(i.findtext('title', '')).text().strip(),
        'date': date.isoformat(), 'author': [i.findtext(f'{{{NS["dc"]}}}creator', '')],
        'categories': [c.text for c in i.findall('category')],
        'excerpt': parse(i.findtext('description', '')).text().strip(),
        'featured_image': {'url': featured} if featured else None,
    }
    meta['images'] = save_images(d, url, ([featured] if featured else []) + parse(content).imgs)
    write_post(d, meta, content)
    print(d)


if __name__ == '__main__':
    main()
