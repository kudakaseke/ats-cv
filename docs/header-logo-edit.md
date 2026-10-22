# Header logo colour variant

Created with the built-in imagegen tool from `public/logo.png`.

Final edit prompt:

> Precise two-colour substitution on the attached existing ATS CV DETECTOR logo. The entire background and all negative space must become one perfectly flat solid colour #346de1 (RGB 52,109,225). The two large CV letters must be pure solid white #ffffff. ATS, DETECTOR and both surrounding bracket shapes remain solid black. Preserve the original letter shapes, exact text, geometry, bracket shapes, positions, logo size and original landscape canvas proportions and padding. No gradients, no texture, no speckles, no noise, no border, no rounded white box, no shadows. Fill the holes and interior negative space within and between the C and V with the exact same background blue #346de1. Clean sharp edges. Do not redesign or add anything. This is the website header asset; background must blend into a CSS #346de1 blue header.

The selected generated image is saved as `public/logo-header.png`. The browser uses `public/logo-header.svg`, which embeds this image and filters out its blue backdrop so the exact CSS header colour shows through. This preserves the neutral black lettering and brackets and the white CV letters. Other page logos and the favicon retain their existing assets.

Browser checks at 1366px and 390px confirmed transparent background pixels, white CV pixels, black bracket pixels and no horizontal overflow.
