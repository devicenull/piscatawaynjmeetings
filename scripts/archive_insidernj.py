#!/usr/bin/env python3
"""Archive InsiderNJ posts matching a search term (text, metadata, images) to disk.

Usage: archive_insidernj.py [term] [outdir]   (defaults: piscataway, data/insidernj)
Writes outdir/<date>_<slug>/{meta.json,article.html,article.txt,<images>}. Re-runs skip existing files.
"""
import html.parser, json, os, re, sys, time, urllib.parse, urllib.request

UA = {'User-Agent': 'Mozilla/5.0'}
API = 'https://www.insidernj.com/wp-json/wp/v2/posts'


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
    out = sys.argv[2] if len(sys.argv) > 2 else os.path.join(os.path.dirname(__file__), '..', 'data', 'insidernj')
    posts, page = [], 1
    while True:
        q = urllib.parse.urlencode({'search': term, 'per_page': 100, 'page': page, '_embed': 1})
        body, hdr = get(f'{API}?{q}')
        s = body.decode('utf-8')
        posts += json.loads(s[s.index('[{"id"'):])  # site injects <script> junk before the JSON
        if page >= int(hdr.get('X-WP-TotalPages', 1)):
            break
        page += 1
    print(f'{len(posts)} posts')

    for p in posts:
        d = os.path.join(out, f"{p['date'][:10]}_{p['slug']}")
        os.makedirs(d, exist_ok=True)
        emb = p.get('_embedded', {})
        content = p['content']['rendered']
        fm = (emb.get('wp:featuredmedia') or [{}])[0]
        terms = {}
        for g in emb.get('wp:term', []):
            for t in g:
                terms.setdefault(t['taxonomy'], []).append(t['name'])
        meta = {
            'id': p['id'], 'url': p['link'], 'title': parse(p['title']['rendered']).text().strip(),
            'date': p['date'], 'date_gmt': p['date_gmt'], 'modified': p['modified'],
            'author': [a.get('name') for a in emb.get('author', [])],
            'terms': terms,
            'excerpt': parse(p['excerpt']['rendered']).text().strip(),
            'featured_image': {'url': fm.get('source_url'), 'caption': parse(fm.get('caption', {}).get('rendered', '')).text().strip(),
                               'alt': fm.get('alt_text')} if fm.get('source_url') else None,
        }
        meta['images'] = save_images(d, p['link'], ([fm['source_url']] if fm.get('source_url') else []) + parse(content).imgs)
        write_post(d, meta, content)
        print(d)


if __name__ == '__main__':
    main()
