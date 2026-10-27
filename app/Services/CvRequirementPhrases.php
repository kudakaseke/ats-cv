<?php

namespace App\Services;

class CvRequirementPhrases
{
    public function split(string $line, string $category): array
    {
        // Commas inside prose are not separate requirements. Keep alternatives
        // and short lists intact; extract named skills from longer descriptions.
        $clauses = preg_split('/;|(?<=[.!?])\s+(?=[A-Z])/u', $line);
        $result = [];
        foreach ($clauses as $clause) {
            if ($category === 'qualifications') {
                $result[] = trim($clause);

                continue;
            }
            // A long list of short skill names is still a list, regardless of
            // the total word count. Keep prose and alternative requirements intact.
            $list = preg_split('/\s*[,|]\s*(?![^()]*\))/u', $clause);
            if (count($list) >= 3 && ! str_contains($clause, ':') && ! preg_match('/\b(?:including|with|such as|or|is|are|ability)\b/iu', $clause)
                && ! array_filter($list, fn ($part) => str_word_count($part) > 7)) {
                $result = [...$result, ...$list];

                continue;
            }
            if (preg_match('/^background in\s+(.+)$/iu', $clause, $background)) {
                $value = preg_replace('/,?\s*or (?:a )?related field.*$/iu', '', $background[1]);
                $result[] = preg_replace('/,\s*/u', ' or ', $value);

                continue;
            }
            if (str_word_count($clause) <= 9 && ! preg_match('/\b(?:including|ability|with|within)\b/i', $clause)) {
                $parts = preg_split('/\s*[,|]\s*(?![^()]*\))/u', $clause);
                foreach ($parts as $part) {
                    $conjunction = preg_split('/\s+and\s+/iu', $part);
                    // Split independent skill phrases; preserve shared phrases
                    // such as "cash and bank management" and alternatives.
                    $independent = count($conjunction) > 1 && ! preg_match('/\bor\b/iu', $part)
                        && ! array_filter($conjunction, fn ($value) => str_word_count($value) < 2);
                    $result = [...$result, ...($independent ? $conjunction : [$part])];
                }

                continue;
            }
            $concepts = [
                '/\b(?:networks?|LAN\/WAN|switches)\b/iu' => 'Networks',
                '/\bservers?\b/iu' => 'Server',
                '/\bfirewalls?\b/iu' => 'Firewall',
                '/\bActive Directory\b/iu' => 'Active Directory',
                '/\bhelpdesk\b/iu' => 'Helpdesk',
                '/\bhardware\b/iu' => 'Hardware',
                '/\bERP\b/u' => 'ERP',
                '/\bcybersecurity\b/iu' => 'Cybersecurity',
                '/\bdata protection\b/iu' => 'Data protection',
                '/\boral\b.*\bcommunication/iu' => 'Oral communication',
                '/\bwritten\b.*\bcommunication/iu' => 'Written communication',
                '/\bdrafting\b/iu' => 'Document drafting', '/\breporting\b/iu' => 'Reporting',
                '/\bdocument preparation\b/iu' => 'Document preparation', '/\bediting\b/iu' => 'Document editing',
                '/\baccuracy\b/iu' => 'Accuracy', '/\bprofessionalism\b/iu' => 'Professionalism',
                '/\binterpersonal\b/iu' => 'Interpersonal skills', '/\bculturally|cultural sensitivity\b/iu' => 'Cultural sensitivity',
                '/\binclusively\b/iu' => 'Inclusive collaboration', '/\bcollaborativ|wide range of partners/iu' => 'Stakeholder collaboration',
                '/\bgrassroots communities\b/iu' => 'Community engagement',
                '/\badapt\b|\badaptability\b/iu' => 'Adaptability', '/\bchallenging environments\b/iu' => 'Working in challenging environments',
                '/\bmulticultural teams\b/iu' => 'Working in multicultural teams', '/\bcomputer literacy\b/iu' => 'Computer literacy',
                '/\bMicrosoft Office\b/iu' => 'Microsoft Office', '/\bWord\b/u' => 'Microsoft Word', '/\bExcel\b/iu' => 'Microsoft Excel',
                '/\bemail\b/iu' => 'Email', '/\binternet\b/iu' => 'Internet use', '/\bdatabase management\b/iu' => 'Database management',
                '/\boffice technology\b/iu' => 'Office technology', '/\bself.motivated\b/iu' => 'Self-motivation',
                '/\bindependently\b/iu' => 'Independent working', '/\bdeadlines\b/iu' => 'Meeting deadlines',
                '/\bsecurity awareness\b/iu' => 'Security awareness', '/\bvolunteerism\b/iu' => 'Volunteerism',
                '/\bsustainable development\b/iu' => 'Sustainable development', '/\bUN system\b/iu' => 'UN values',
            ];
            $found = [];
            foreach ($concepts as $pattern => $label) {
                if (preg_match($pattern, $clause)) {
                    $found[] = $label;
                }
            }
            $result = [...$result, ...($found ?: [trim($clause)])];
        }

        return array_values(array_unique(array_filter(array_map(fn ($value) => trim($value, " \t.:-"), $result))));
    }
}
