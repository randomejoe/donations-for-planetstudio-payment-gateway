#!/usr/bin/env python3
"""Regenerate languages/donations-for-planetstudio-payment-gateway.pot.

Run from the plugin root:

    python3 tools/make-pot.py

Scans the plugin's PHP for __(), _e(), esc_html__(), esc_html_e(),
esc_attr__(), esc_attr_e() and _n() calls carrying the donations-for-planetstudio-payment-gateway
text domain. Tests and the languages folder itself are skipped.

WP-CLI's `wp i18n make-pot` does the same job more thoroughly if it is
available. Donor-facing wording (form labels and email templates) is not in
the POT: it is translated with a locale pack in includes/locales/.
"""

import datetime
import io
import os
import re

SINGLE = re.compile(
    r"\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*"
    r"(['\"])(?P<text>(?:\\.|(?!\1).)*)\1\s*,\s*'donations-for-planetstudio-payment-gateway'\s*\)"
)
PLURAL = re.compile(
    r"\b_n\(\s*(['\"])(?P<one>(?:\\.|(?!\1).)*)\1\s*,\s*(['\"])(?P<many>(?:\\.|(?!\3).)*)\3\s*,"
)

SKIP_DIRS = {'.git', 'languages', 'tests', 'tools', 'vendor', 'node_modules'}


def unescape(text, quote):
    if quote == "'":
        return text.replace("\\'", "'").replace('\\\\', '\\')
    return text.replace('\\"', '"').replace('\\\\', '\\')


def pot_escape(text):
    return (
        text.replace('\\', '\\\\')
        .replace('"', '\\"')
        .replace('\n', '\\n')
        .replace('\t', '\\t')
    )


def collect(root='.'):
    entries = {}
    for current, dirs, files in os.walk(root):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for name in sorted(files):
            if not name.endswith('.php'):
                continue
            path = os.path.join(current, name)
            src = io.open(path, encoding='utf-8').read()
            rel = os.path.relpath(path, root)
            for match in SINGLE.finditer(src):
                line = src[: match.start()].count('\n') + 1
                text = unescape(match.group('text'), match.group(1))
                entries.setdefault((text, None), []).append('%s:%d' % (rel, line))
            for match in PLURAL.finditer(src):
                line = src[: match.start()].count('\n') + 1
                one = unescape(match.group('one'), match.group(1))
                many = unescape(match.group('many'), match.group(3))
                entries.setdefault((one, many), []).append('%s:%d' % (rel, line))
    return entries


def render(entries):
    now = datetime.datetime.utcnow().strftime('%Y-%m-%d %H:%M+0000')
    out = [
        '# Copyright (C) Joe Davtian',
        '# This file is distributed under the same licence as the Donations for PlanetStudio Payment Gateway plugin.',
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: Donations for PlanetStudio Payment Gateway\\n"',
        '"POT-Creation-Date: %s\\n"' % now,
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
        '"X-Domain: donations-for-planetstudio-payment-gateway\\n"',
        '',
    ]

    for (one, many), refs in sorted(entries.items()):
        for ref in sorted(set(refs)):
            out.append('#: %s' % ref)
        out.append('msgid "%s"' % pot_escape(one))
        if many is None:
            out.append('msgstr ""')
        else:
            out.append('msgid_plural "%s"' % pot_escape(many))
            out.append('msgstr[0] ""')
            out.append('msgstr[1] ""')
        out.append('')

    return '\n'.join(out)


if __name__ == '__main__':
    found = collect('.')
    os.makedirs('languages', exist_ok=True)
    io.open('languages/donations-for-planetstudio-payment-gateway.pot', 'w', encoding='utf-8').write(render(found))
    print('Wrote languages/donations-for-planetstudio-payment-gateway.pot with %d strings' % len(found))
