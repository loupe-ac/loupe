// The docs build leaves two exact marker strings in every built page. The
// stamp step replaces each one with the rendered site bar or footer HTML, so
// the Astro build needs no host name. Both sit inside a declarative shadow
// root, which keeps the app CSS and the Starlight CSS apart.
export const NAV_MARKER = '<!--LOUPE_SITE_NAV-->';
export const FOOTER_MARKER = '<!--LOUPE_SITE_FOOTER-->';

// The Open Graph image of the home page has a content-hashed address that
// only the app knows. The build writes this literal address, and the stamp
// step may replace it with the hashed one.
export const OG_IMAGE_URL = 'https://loupe.ac/assets/images/og/hero.png';
