/**
 * Certificate Pro — Vecteezy collection harvester
 *
 * The Vecteezy v2 API has NO collections endpoint. But it does have
 *   GET /v2/{account_id}/resources/{id}
 * and a public collection page exposes every item's resource id in its href:
 *   /vector-art/67100551-blue-gold-abstract-white-background-suitable-for-certificate
 *                ^^^^^^^^  <- that is the API resource id
 *
 * So: curate by hand on vecteezy.com, run this once, ship the id list.
 * The manifest contains ONLY ids and metadata — no artwork — so nothing
 * licensed is ever bundled in the plugin package.
 *
 * HOW TO RUN
 *   1. Open your collection page in Chrome.
 *   2. Open DevTools (F12) -> Console.
 *   3. Paste this whole file, press Enter.
 *   4. It auto-scrolls to force lazy-load, then copies the JSON to your
 *      clipboard and prints it. Save it as curated_manifest.json.
 */
(async () => {
  const SCROLL_STEP = 1200;
  const SETTLE_MS = 450;
  const MAX_IDLE_ROUNDS = 4;

  const harvest = () => {
    const found = new Map();
    document
      .querySelectorAll('a[href*="/vector-art/"], a[href*="/photo/"], a[href*="/png/"]')
      .forEach((a) => {
        const m = a.getAttribute('href').match(/\/(vector-art|photo|png)\/(\d+)-([a-z0-9\-]+)/i);
        if (!m) return;
        const id = Number(m[2]);
        if (found.has(id)) return;
        const card = a.closest('figure, li, article, div');
        const img = card ? card.querySelector('img') : null;
        const thumb = img ? img.currentSrc || img.src || img.getAttribute('data-src') : null;
        found.set(id, {
          id,
          content_type: m[1] === 'vector-art' ? 'vector' : m[1],
          slug: m[3],
          title: (a.getAttribute('title') || a.textContent || '').trim().slice(0, 140),
          thumb,
          // Filled in by you, or by the classifier pass — see notes below.
          role: null,      // border | corner | seal | pattern | full
          orientation: null, // landscape | portrait | both
          palette: null    // e.g. ["navy","gold"]
        });
      });
    return found;
  };

  let all = new Map();
  let idle = 0;
  window.scrollTo(0, 0);

  while (idle < MAX_IDLE_ROUNDS) {
    const before = all.size;
    harvest().forEach((v, k) => all.set(k, v));
    if (all.size === before) idle += 1;
    else idle = 0;

    const atBottom =
      window.innerHeight + window.scrollY >= document.body.scrollHeight - 40;
    if (atBottom) idle += 1;

    window.scrollBy(0, SCROLL_STEP);
    await new Promise((r) => setTimeout(r, SETTLE_MS));
  }

  const manifest = {
    source: location.href,
    harvested_items: all.size,
    // NOTE: no timestamp — add one yourself if you want provenance.
    items: [...all.values()].sort((a, b) => a.id - b.id)
  };

  const json = JSON.stringify(manifest, null, 2);
  try {
    await navigator.clipboard.writeText(json);
    console.log('%c✓ Copied to clipboard', 'color:#0a0;font-weight:bold');
  } catch (e) {
    console.log('Clipboard blocked — copy the output below by hand.');
  }
  console.log(`Harvested ${all.size} resources from ${location.pathname}`);
  console.log(json);
  return manifest;
})();

/* ---------------------------------------------------------------------------
 * NOTES ON THE `role` FIELD
 *
 * The wizard places one asset in one of six ways (border, corner pair, seal,
 * tiling pattern, full-bleed panel, divider). Vecteezy does not tell us which
 * an asset suits — a title like "Blue gold abstract white background" is not
 * a placement hint. Two options:
 *
 *   a) Tag `role` by hand as you curate. ~5 seconds per asset, perfectly
 *      accurate, and it is the taste step that matters most.
 *   b) Create one collection per role on Vecteezy ("cp-border", "cp-corner",
 *      "cp-seal", ...) and run this harvester once per collection, setting
 *      role from the collection name. Recommended — the curation UI does the
 *      filing for you and re-running is trivial.
 * ------------------------------------------------------------------------- */
