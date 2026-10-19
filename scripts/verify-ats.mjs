import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const interpreter = process.env.CV_PYTHON_BINARY || fileURLToPath(new URL(process.platform === 'win32' ? '../.venv-ats/Scripts/python.exe' : '../.venv-ats/bin/python', import.meta.url));
for (const script of ['scripts/verify-tfidf.py', 'scripts/verify-pdf-extraction.py']) {
    execFileSync(interpreter, [script], {stdio:'inherit'});
}
