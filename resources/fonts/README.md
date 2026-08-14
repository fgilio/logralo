# Why these are committed

`ShareCardComposer` draws the WhatsApp share cards with GD, and GD draws text through FreeType, which reads TTF and OTF and nothing else.

The app's own web fonts come from `@fontsource/anton` and `@fontsource/archivo` in `node_modules`, pinned by `package-lock.json`. Those packages ship `woff` and `woff2` only — FreeType cannot read either — so the same two typefaces are committed here in TrueType.

Downloading them during the build instead is exactly the mistake `docs/architecture/laravel-cloud-production.md` is written about: a versioned font URL went 404 mid-deploy and failed a deploy over a change to `.env.example`. `tests/Arch/BuildTest.php` guards the rule.

| File                  | Source                                                        |
| --------------------- | ------------------------------------------------------------- |
| `Anton-Regular.ttf`   | google/fonts, `ofl/anton`                                     |
| `Archivo-Regular.ttf` | Omnibus-Type/Archivo, `fonts/ttf`                             |
| `Archivo-Bold.ttf`    | Omnibus-Type/Archivo, `fonts/ttf`                             |

Both families are licensed under the SIL Open Font License 1.1. `OFL.txt` is the licence text.
