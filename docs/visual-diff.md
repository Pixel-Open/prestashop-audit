# Visual differences

Journal details, product history and restoration previews share a read-only visual diff.
The Before/After columns preserve complete values and highlight only changed text:

- Red, struck-through fragments were removed.
- Green, underlined fragments were added.
- Unchanged context stays neutral. The legend also uses ASCII minus/plus markers.

For example, changing `White paint, 5 L` to `White paint, 10 L` highlights only `5`
and `10`. Text uses word boundaries, with line matching for multiline content.
Unicode characters, whitespace and original line endings are preserved. HTML is
compared as source text and escaped; historical markup is never executed.

Numbers, booleans and native ISO date strings are highlighted as complete values.
Lists match complete elements, retaining order, duplicates and JSON value types.
Associative JSON uses its formatted text representation. Empty text is labelled
`Empty`, NULL remains `∅` with a null-value label, and zero/false/empty lists retain
their own representation. Placeholder styling distinguishes these labels from
literal text stored in a field.

In a restoration preview, the left side is the current value and the right side is
the value to restore. Red therefore shows what confirmation would remove and green
what confirmation would add. A preview alone never applies these changes. Existing
conflict warnings and restoration permissions still apply.

Detailed matching is bounded. Combined display values over 64 KiB use whole-value
highlighting; unmatched token regions also have a bounded comparison budget. The
interface labels this fallback and still displays the complete values. It does not
truncate, normalise or rewrite stored data.

The engine produces plain text fragments, escaped by a shared Smarty partial.
Presentation metadata is added after decoding authorized journal events, and after
the restoration service has produced its signed preview. It is excluded from stored
historical JSON, conflict comparisons and restoration fingerprints. No database
migration or provider API change is required for version 1.0.0.
