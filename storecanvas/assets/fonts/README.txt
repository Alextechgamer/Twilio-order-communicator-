StoreCanvas bundled fonts for GD text composites (1.1.0).

- sc-sans.ttf      — default sans (UI Arial / Helvetica / Verdana / system)
- sc-sans-bold.ttf  — bold sans (Impact-ish weight mapping + FreeSansBold)
- sc-serif.ttf      — serif (Georgia / Times New Roman)

Web fontFamily strings are mapped in SC_Print_Ready::resolve_font_path().
If FreeType is unavailable, GD imagestring is used (no stroke/rotation).
