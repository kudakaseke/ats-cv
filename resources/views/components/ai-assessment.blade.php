@props(['assessment', 'revised' => false])
<section class="checker-panel mt-6" aria-label="ATSDetector compatibility assessment">
    <h3>Job requirements and recommendations</h3>
    @if(isset($assessment['professional_alignment']))
        <div class="checker-review-warnings"><h4>Professional alignment: {{ ucfirst(strtolower(str_replace('_', ' ', $assessment['professional_alignment']))) }}</h4><p>{{ $assessment['candidate_background'] }} compared with {{ $assessment['target_profession'] }}.</p><p>False-positive risk: {{ $assessment['false_positive_risk'] }}.</p><p>{{ $assessment['score_validation_explanation'] }}</p>
            @foreach(['core_skills_matched' => 'Core job skills evidenced', 'transferable_skills_matched' => 'Transferable strengths', 'general_competencies_matched' => 'General competencies', 'missing_core_requirements' => 'Core requirements not evidenced', 'cross_industry_warnings' => 'Relevance warnings'] as $key => $label)
                @if(!empty($assessment[$key]))<h4>{{ $label }}</h4><ul>@foreach($assessment[$key] as $item)<li>{{ $item }}</li>@endforeach</ul>@endif
            @endforeach
            <p>{{ $assessment['qualification_relevance']['explanation'] ?? '' }}</p><p>{{ $assessment['relevant_experience']['explanation'] ?? '' }}</p>
        </div>
    @endif
    <p>Mandatory requirements: {{ str_replace('_', ' ', $assessment['mandatory_requirements_status']) }}.</p>
    <details><summary>Requirement details</summary>
        <ul>@foreach($assessment['requirements'] as $item)
            <li><strong>{{ ucfirst($item['importance']) }}: {{ $item['requirement'] }}</strong> — {{ str_replace('_', ' ', $item[$revised ? 'revamped_status' : 'original_status']) }}. {{ $item['evidence'] ?: 'Not evidenced in the supplied CV.' }}</li>
        @endforeach</ul>
    </details>
    @if($assessment['warnings'])<div class="checker-review-warnings"><h4>Warnings and eligibility gaps</h4><ul>@foreach($assessment['warnings'] as $warning)<li><strong>{{ ucfirst($warning['severity']) }}: {{ $warning['requirement'] }}</strong> — {{ $warning['explanation'] }} {{ $warning['recommended_action'] }}</li>@endforeach</ul></div>@endif
    @if($assessment['questions_for_candidate'])<h4>Information to confirm</h4><ul>@foreach($assessment['questions_for_candidate'] as $question)<li>{{ $question }}</li>@endforeach</ul>@endif
</section>
