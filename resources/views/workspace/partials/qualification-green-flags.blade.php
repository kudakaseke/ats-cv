<section class="checker-green-flags" x-show="result?.green_flags?.length">
    <h3>Qualification strengths</h3>
    <ul><template x-for="flag in result?.green_flags ?? []" :key="flag"><li x-text="flag"></li></template></ul>
    <p x-show="result?.certificate_bonus > 0"><strong x-text="'+' + result?.certificate_bonus + ' certificate bonus points'"></strong> included in your overall score. Requirement gaps remain listed separately.</p>
</section>
