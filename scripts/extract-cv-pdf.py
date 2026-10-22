import argparse
import json
import sys
import os
import pymupdf

if os.environ.get('DOCUMENT_SERVICE_LIMITS') == '1' and os.name == 'posix':
    import resource
    resource.setrlimit(resource.RLIMIT_AS, (768 * 1024 * 1024, 768 * 1024 * 1024))
    resource.setrlimit(resource.RLIMIT_CPU, (20, 20))

sys.stdout.reconfigure(encoding='utf-8')
pymupdf.TOOLS.mupdf_display_errors(False)
pymupdf.TOOLS.mupdf_display_warnings(False)
parser = argparse.ArgumentParser()
parser.add_argument('path')
args = parser.parse_args()
try:
    with pymupdf.open(args.path) as document:
        if document.needs_pass:
            raise ValueError('password_protected')
        if document.page_count > 100:
            raise ValueError('too_many_pages')
        parts = []
        size = 0
        for page in document:
            # Reading order is more useful than PDF drawing order for CV columns.
            text = page.get_text('text', sort=True)
            size += len(text)
            if size > 20000:
                raise ValueError('too_much_text')
            parts.append(text)
        text = '\n'.join(parts).strip()
        if not text:
            raise ValueError('no_text')
        print(json.dumps({'text': text, 'pages': document.page_count, 'extractor': 'pymupdf'}))
except Exception:
    print(json.dumps({'error': 'unreadable_pdf'}))
    sys.exit(2)
