# Department printing and readable pagination

The first CV page in the supplied Word document was inspected locally using its XML and a first-page PDF rendered by Microsoft Word. Certificate pages and personal details are not included in source code or template examples.

The latest reference is `template_CV.docx`, supplied on 20 October 2026. Department templates use Arial, centred identity/contact information, thin section rules, uppercase headings, aligned job titles and dates, and a single reading column. The reference uses US Letter paper with side margins of 691 twips and top/bottom margins of 490 twips. Typography now follows this latest reference: 12.5-point name, 10-point section headings, 9-point body and 8-point role/contact information. This supersedes the earlier 11-point enlargement. Section order follows experience, education, qualifications, skills and relevant additional sections; department-specific headings and user placements remain supported. Categorised skills retain labelled lines as in the reference; ordinary skill lists retain four columns. Empty sections are omitted from final output.

Review renders fixed-size pages at print width. It measures content in the browser, moves sections/entries onto following pages when necessary, and splits long experience entries at bullet boundaries. It never scales department text down to meet a page count. Short CVs stay on one page; typical longer CVs occupy two. Very long content can require more pages and is never silently truncated. The editor continues to scroll while editing.

PDF export uses those same page elements and explicit Letter page breaks. Word export retains all page elements, explicit page breaks and unscaled department typography. Its ZIP packaging avoids Phar-specific metadata rejected by Microsoft Word on Windows. The exact PDF appearance is the reliable choice for fixed printing; editable Word documents remain subject to Word's own layout engine and installed fonts.

Validation commands:

```
npm.cmd run build
node --test scripts/builder-regressions.test.mjs
node scripts/department-pagination-smoke.mjs
node scripts/review-download-parity.mjs
php scripts/department-export-smoke.php
php vendor/phpunit/phpunit/phpunit --filter=CvDownloadTest
```

All eleven departments were exercised with short and long examples. Actual PDF output and a Word download opened and printed using Microsoft Word were checked for two-page output, readable fonts and preservation of the final content marker. Synthetic verification files live under ignored local storage.
