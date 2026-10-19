import json
import random
import subprocess
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics.pairwise import cosine_similarity

pairs = [
    ['PHP HTML CSS', 'PHP HTML CSS'],
    ['PHP HTML CSS', 'Python C#'],
    ['PHP HTML CSS', 'PHP HTML Python C#'],
    ['Required skills: PHP PHP HTML5 CSS3 JS', 'php html css JavaScript'],
    ['.NET C# C++', 'dotnet csharp cplusplus'],
    ['Java SQL', 'JavaScript MySQL'],
    ['', ''],
    ['PHP', ''],
]
random.seed(17)
words = ['PHP', 'HTML', 'CSS', 'Python', 'C#', 'C++', '.NET', 'JavaScript', 'SQL', 'testing']
for _ in range(100):
    pairs.append([' '.join(random.choices(words, k=random.randint(1, 35))), ' '.join(random.choices(words, k=random.randint(1, 35)))])
rows = json.loads(subprocess.check_output(['php', 'scripts/verify-tfidf.php'], input=json.dumps(pairs).encode()))
for row in rows:
    terms = row['terms']
    if not any(terms):
        expected = 0
    else:
        matrix = TfidfVectorizer(analyzer=lambda terms: terms, lowercase=False).fit_transform(terms)
        expected = float(cosine_similarity(matrix[0:1], matrix[1:2])[0, 0])
    assert abs(expected - row['result']['similarity']) < 1e-8, (terms, expected, row['result'])
print(f'{len(rows)} TF-IDF cosine scores match scikit-learn to 1e-8 with the same technical-term tokenizer.')
