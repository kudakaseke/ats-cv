            <section class="checker-experience-warning" x-show="result?.assessment?.professional_alignment" aria-label="Industry relevance">
                <strong x-text="(result?.assessment?.professional_alignment ?? '').replaceAll('_', ' ')"></strong>
                <p x-text="result?.assessment?.score_validation_explanation ?? ''"></p>
                <template x-for="warning in result?.assessment?.cross_industry_warnings ?? []" :key="warning"><p x-text="warning"></p></template>
                <p x-show="result?.assessment?.missing_core_requirements?.length"><strong>Core requirements not evidenced: </strong><span x-text="(result?.assessment?.missing_core_requirements ?? []).join('; ')"></span></p>
            </section>
