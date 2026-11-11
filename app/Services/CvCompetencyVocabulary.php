<?php

namespace App\Services;

class CvCompetencyVocabulary
{
    // Related concepts remain separate: initiative is not motivation, and
    // generic communication is not evidence of written or verbal ability.
    private const ALIASES = [
        'Problem Solving' => ['problem-solving', 'solution-oriented', 'solution oriented', 'resolving complex issues', 'finding practical solutions'],
        'Analytical Skills' => ['strong analytical skills', 'analytical thinking', 'analytical ability', 'analytical mindset', 'analytical aptitude'],
        'Communication' => ['effective communication', 'excellent communicator', 'clear communicator', 'communication skills', 'communication skill'],
        'Written Communication' => ['written communication skills', 'strong written skills'],
        'Oral Communication' => ['verbal communication', 'verbal communication skills', 'public speaking', 'strong verbal skills'],
        'Attention to Detail' => ['detail-oriented', 'detail oriented', 'meticulous', 'thorough', 'high level of accuracy'],
        'Self Motivation' => ['self-motivation', 'self motivated', 'self-motivated', 'self-starter', 'self starter', 'self-driven', 'self driven', 'intrinsically motivated', 'highly motivated'],
        'Teamwork' => ['team collaboration', 'team player', 'team work', 'collaborative approach', 'ability to work within a team'],
        'Adaptability' => ['flexible', 'flexibility', 'adaptable', 'adaptable to change', 'ability to adjust to new environments'],
        'Integrity' => ['high integrity', 'ethical conduct', 'honesty', 'trustworthiness', 'professional ethics'],
        'Innovation' => ['innovative', 'creative thinking', 'innovative mindset', 'ability to generate new ideas'],
        'Troubleshooting' => ['fault diagnosis', 'issue resolution', 'problem diagnosis', 'technical fault finding'],
        'Time Management' => ['meets deadlines', 'meeting deadlines', 'effective prioritisation', 'effective prioritization', 'manages competing priorities'],
        'Independence' => ['works autonomously', 'minimal supervision', 'ability to work independently', 'independent working', 'works independently'],
        'Initiative' => ['proactive attitude', 'takes initiative', 'taking initiative'],
        'Resilience' => ['works well under pressure', 'handles challenges', 'stress tolerance'],
        'Leadership' => ['leads teams', 'motivates others', 'leadership ability', 'people leadership'],
        'Customer Service' => ['client-focused', 'customer-oriented', 'service excellence', 'customer care'],
        'Organisational Skills' => ['organizational skills', 'well-organised', 'well-organized', 'planning ability', 'effective coordination'],
        'Decision Making' => ['decision-making', 'sound judgement', 'sound judgment', 'makes informed decisions', 'decisive'],
        'Learning Ability' => ['quick learner', 'willingness to learn', 'learning agility'],
        'Accountability' => ['takes responsibility', 'takes ownership', 'ownership', 'answerable for outcomes'],
        'Relationship Building' => ['rapport building', 'interpersonal relationships', 'stakeholder relationships'],
        'Results Orientation' => ['goal-driven', 'target-focused', 'achievement-focused'],
        'Critical Thinking' => ['logical reasoning', 'objective analysis', 'evidence-based evaluation'],
        'Professionalism' => ['professional conduct', 'work ethic', 'ethical behaviour', 'ethical behavior'],
        'Conflict Resolution' => ['conflict management', 'dispute resolution', 'mediation'],
    ];

    private function phrases(): array
    {
        $phrases = [];
        foreach (self::ALIASES as $canonical => $aliases) {
            foreach ([$canonical, ...$aliases] as $alias) {
                $phrases[mb_strtolower($alias)] = $canonical;
            }
        }
        uksort($phrases, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        return $phrases;
    }

    public function guidance(): string
    {
        return 'Competency wording families (related wording, not automatic proof of demonstrated behaviour): '.json_encode(self::ALIASES, JSON_UNESCAPED_UNICODE).'. Keep technical/domain skills separate from behavioural competencies regardless of section heading. Deduplicate synonymous requirements; retain stricter importance. Self-starter may relate to initiative but does not automatically establish taking ownership. Generic communication or public speaking does not establish written communication. Generic problem solving does not establish hardware/software troubleshooting. Preserve stated_only versus demonstrated evidence and all specialist qualifiers.';
    }

    public function normalise(string $text): string
    {
        $text = str_replace(["\u{2010}", "\u{2011}", "\u{2013}"], '-', $text);
        // Both channels must be explicitly present before supplying both labels.
        $text = preg_replace('/\b(?:strong |excellent )?(?:verbal and written|written and verbal)\s+(?:communication(?: skills)?|skills)\b/iu', 'Oral Communication Written Communication', $text);
        $phrases = $this->phrases();
        $pattern = '/(?<![\p{L}\p{N}])(?:'.implode('|', array_map(fn ($phrase) => preg_quote($phrase, '/'), array_keys($phrases))).')(?![\p{L}\p{N}])/iu';
        return preg_replace_callback($pattern, fn ($match) => $phrases[mb_strtolower($match[0])], $text);
    }

    public function canonical(string $phrase): ?string
    {
        $phrase = trim(preg_replace('/^(?:(?:strong|excellent|effective|good|sound)\s+)+/iu', '', trim($phrase)));
        $normal = $this->normalise($phrase);
        foreach (array_keys(self::ALIASES) as $canonical) {
            if (mb_strtolower($normal) === mb_strtolower($canonical)) return $canonical;
        }
        return null;
    }

    public function key(string $phrase): string
    {
        return mb_strtolower($this->canonical($phrase) ?? trim($phrase));
    }
}
