<?php

namespace App\Services;

class CvKeywordMatcher
{
    private const STOP_WORDS = 'a an and are as at be been being but by can could did do does doing for from had has have having he her here hers herself him himself his how i if in into is it its itself may me might more most must my myself no nor not of on once only or other our ours ourselves out over own same shall she should so some such than that the their theirs them themselves then there these they this those through to too under until up us very was we were what when where which while who whom why will with would you your yours yourself yourselves about across after all also any applicants apply application applications based candidate candidates company currently description duties employer employment etc excellent experience experienced following good including include includes job jobs looking need needs new opportunity position preferred preferable qualification qualifications required require requires requirements responsibilities responsibility role seek seeking successful team teams work working works year years ability able strong using use used knowledge understanding please relevant skills skill minimum essential desirable welcome ideal join ensure demonstrate proven supporting support develop developing development responsible various within well least plus seeking hiring vacancy title location salary benefits offer offers receive equal';

    private const LABELS = ['php' => 'PHP', 'html' => 'HTML', 'css' => 'CSS', 'javascript' => 'JavaScript', 'typescript' => 'TypeScript', 'c#' => 'C#', 'c++' => 'C++', '.net' => '.NET', 'sql' => 'SQL', 'mysql' => 'MySQL', 'python' => 'Python', 'node.js' => 'Node.js', 'api' => 'API', 'rest' => 'REST', 'aws' => 'AWS', 'git' => 'Git'];

    public function terms(string $text): array
    {
        $aliases = ['js' => 'javascript', 'html5' => 'html', 'css3' => 'css', 'ts' => 'typescript', 'csharp' => 'c#', 'cplusplus' => 'c++', 'dotnet' => '.net', 'apis' => 'api', 'nodejs' => 'node.js'];
        preg_match_all('/\.[\p{L}]+|[\p{L}][\p{L}\p{N}]*(?:[.+][\p{L}\p{N}]+)*(?:\+\+|#)?/u', mb_strtolower($text), $matches);
        $terms = [];
        $stopWords = array_fill_keys(explode(' ', self::STOP_WORDS), true);
        foreach ($matches[0] as $word) {
            $word = $aliases[$word] ?? $word;
            if (isset($stopWords[$word]) || (mb_strlen($word) < 2 && ! in_array($word, ['c', 'r'], true))) {
                continue;
            }
            $terms[] = $word;
        }

        return $terms;
    }

    public function tokens(string $text): array
    {
        $words = [];
        foreach ($this->terms($text) as $word) {
            $words[$word] = self::LABELS[$word] ?? $word;
        }

        return $words;
    }

    private function requirements(string $description): ?string
    {
        $collect = false;
        $lines = [];
        foreach (preg_split('/\R/u', $description) as $line) {
            $heading = trim($line, " \t#*");
            if (preg_match('/^\s*(?:minimum requirements|requirements|required qualifications|qualifications|required skills|essential skills|technical skills|what you bring|what we are looking for)\s*(?::\s*(.*))?$/iu', $heading, $match)) {
                $collect = true;
                if (trim($match[1] ?? '') !== '') {
                    $lines[] = $match[1];
                }
            } elseif ($collect && preg_match('/^\s*(?:benefits|about us|about the company|how to apply|responsibilities|duties|salary|what we offer)\s*(?::.*)?$/iu', $heading)) {
                $collect = false;
            } elseif ($collect) {
                $lines[] = $line;
            }
        }
        $text = trim(implode("\n", $lines));

        return $text !== '' ? $text : null;
    }

    private function similarity(array $jobTerms, array $resumeTerms): float
    {
        $jobCounts = array_count_values($jobTerms);
        $resumeCounts = array_count_values($resumeTerms);
        $dot = 0.0;
        $jobNorm = 0.0;
        $resumeNorm = 0.0;
        foreach (array_unique([...array_keys($jobCounts), ...array_keys($resumeCounts)]) as $term) {
            $df = (int) isset($jobCounts[$term]) + (int) isset($resumeCounts[$term]);
            // Smoothed IDF and raw term frequency, equivalent to sklearn's default TF-IDF weighting.
            $idf = log(3 / (1 + $df)) + 1;
            $job = ($jobCounts[$term] ?? 0) * $idf;
            $resume = ($resumeCounts[$term] ?? 0) * $idf;
            $dot += $job * $resume;
            $jobNorm += $job * $job;
            $resumeNorm += $resume * $resume;
        }

        return $jobNorm > 0 && $resumeNorm > 0 ? max(0.0, min(1.0, $dot / sqrt($jobNorm * $resumeNorm))) : 0.0;
    }

    public function compare(string $description, string $resume): array
    {
        $requirements = $this->requirements($description);
        $keywords = $this->tokens($requirements ?? $description);
        $resumeWords = $this->tokens($resume);
        $matched = array_intersect_key($keywords, $resumeWords);
        $missing = array_diff_key($keywords, $resumeWords);
        $similarity = $this->similarity($this->terms($description), $this->terms($resume));
        $counts = array_count_values($this->terms($requirements ?? $description));
        uksort($missing, fn ($a, $b) => ($counts[$b] ?? 0) <=> ($counts[$a] ?? 0));
        $suggestions = [];
        foreach ($missing as $word) {
            $suggestions[] = ['keyword' => $word, 'suggestion' => 'Use '.$word.' in Skills, Summary or a responsibility only where your experience supports it; otherwise identify it as a skill gap.'];
        }

        return [
            'score' => (int) round($similarity * 100), 'similarity' => round($similarity, 8),
            'similarity_percent' => round($similarity * 100, 2), 'method' => 'tfidf-cosine-v1',
            'keyword_score' => count($keywords) ? (int) round(count($matched) / count($keywords) * 100) : 0,
            'keyword_source' => $requirements !== null ? 'requirements' : 'job_description',
            'total' => count($keywords), 'matched' => array_values($matched), 'missing' => array_values($missing),
            'suggestions' => $suggestions,
        ];
    }
}
