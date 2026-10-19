import json
import pathlib
import subprocess
import sys
import tempfile
import pymupdf

with tempfile.TemporaryDirectory(prefix='ats-pdf-check-') as folder:
    folder = pathlib.Path(folder)
    pdf = folder / 'resume with spaces.pdf'
    with pymupdf.open() as doc:
        page = doc.new_page()
        page.insert_text((40, 40), 'Alex Sample\nSkills\nPHP HTML CSS\nExperience\nWeb developer')
        doc.save(pdf)
    command = [sys.executable, 'scripts/extract-cv-pdf.py']
    output = json.loads(subprocess.check_output(command + [str(pdf)]))
    assert output['extractor'] == 'pymupdf' and 'PHP HTML CSS' in output['text']
    blank = folder / 'blank.pdf'
    with pymupdf.open() as doc:
        doc.new_page()
        doc.save(blank)
    assert subprocess.run(command + [str(blank)], capture_output=True).returncode == 2
    locked = folder / 'locked.pdf'
    with pymupdf.open(pdf) as doc:
        doc.save(locked, encryption=pymupdf.PDF_ENCRYPT_AES_256, user_pw='test-user', owner_pw='test-owner')
    assert subprocess.run(command + [str(locked)], capture_output=True).returncode == 2
    broken = folder / 'broken.pdf'
    broken.write_text('Not a valid PDF')
    assert subprocess.run(command + [str(broken)], capture_output=True).returncode == 2
print('PyMuPDF extraction passed: text, paths with spaces, blank/scanned-style, encrypted and malformed PDFs.')
