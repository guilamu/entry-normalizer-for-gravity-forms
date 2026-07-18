# Entry Normalizer for Gravity Forms

[![Latest Release](https://img.shields.io/github/v/release/guilamu/entry-normalizer-for-gravity-forms?color=blue)](https://github.com/guilamu/entry-normalizer-for-gravity-forms/releases) [![License: AGPL-3.0](https://img.shields.io/badge/license-AGPL--3.0-green.svg)](LICENSE) [![WordPress: 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org) [![PHP: 7.4+](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)

Normalize Gravity Forms field values — existing entries and future submissions — from BEFORE/AFTER examples: the plugin detects the transformation for you.

![Plugin Screenshot](https://github.com/guilamu/entry-normalizer-for-gravity-forms/blob/main/screenshot.png)

## Example-Based Detection

- Show one or more BEFORE/AFTER examples (e.g. `roger` → `ROGER`) — no regular expressions to write
- The plugin tests its whole transformation library, plus two-by-two combinations, against your examples and suggests only the transformations that reproduce them all exactly
- 16 built-in transformations: case changes, space cleanup, accents, character filtering, quote removal, HTML/code stripping, link removal, French phone numbers to `+33…`
- One extra example resolves ambiguities

## Fix Existing Entries & Future Submissions

- Preview the result on real entries first — nothing is written
- Bulk-apply the fix to existing entries in safe 50-entry batches, with before/after samples and a summary report
- Optionally enable each rule for future submissions: new values are normalized as they come in
- Rules are stored per form and run in the order they appear
- Quick casing shortcut right in the field's Advanced tab (UPPER CASE, lower case, First letter upper case, First Letters Upper Case) for future submissions, with a link to the full rule editor for anything more advanced

## Key Features

- **Example-Driven:** describe the change with examples instead of configuring transforms
- **Safe by Default:** dry-run preview, batched writes, write-error and unrecognized-value reporting
- **Multilingual:** works with content in any language (all transformations are UTF-8 safe)
- **Translation-Ready:** all strings are internationalized (English and French included)
- **Secure:** nonce and capability checks on every AJAX endpoint, sanitized input, no data written without explicit confirmation
- **GitHub Updates:** automatic updates from GitHub releases

## Requirements

- Gravity Forms 2.5 or higher
- WordPress 6.0 or higher
- PHP 7.4 or higher

## Installation

1. Upload the `entry-normalizer-for-gravity-forms` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress (Gravity Forms 2.5+ required)
3. Go to **Forms → your form → Settings → Normalization** and click **Add a modification**

## FAQ

### Which fields can be normalized?

Single line text, paragraph text, name (sub-fields), email, phone, website, address (sub-fields), number, hidden, and post fields (title, content, excerpt, tags, custom field).

### Does the preview change my data?

No. Preview only analyzes entries and reports what would change; nothing is written until you click **Apply to existing entries**. Back up your database before any bulk apply.

### "No known transformation reproduces all your examples" — what now?

Check the pairs for typos or stray spaces, or simplify the change. Only transformations that reproduce every example exactly are suggested; one more example usually resolves it.

### Can it conflict with GF Auto Formatter?

The rule editor warns when the targeted field already has GF Auto Formatter formatting configured, because both would run on new submissions. The bulk apply always matches the preview.

## Project Structure

```
.
├── entry-normalizer-for-gravity-forms.php   # Main plugin file (bootstrap, Bug Reporter, row meta)
├── uninstall.php                            # Removes normalization rules from form meta
├── README.md
├── assets
│   ├── css
│   │   └── gfen-admin.css                   # Normalization settings tab styles
│   └── js
│       └── gfen-admin.js                    # Rule editor UI and AJAX flow
├── includes
│   ├── class-gfen-addon.php                 # GF add-on: settings tab, AJAX endpoints, rule storage
│   ├── class-gfen-transforms.php            # Transformation library and detection engine
│   ├── class-github-updater.php             # GitHub auto-updates
│   └── Parsedown.php                        # Markdown parser for the View details popup
└── languages
    ├── entry-normalizer-for-gravity-forms-fr_FR.mo # French translation (binary)
    ├── entry-normalizer-for-gravity-forms-fr_FR.po # French translation (source)
    └── entry-normalizer-for-gravity-forms.pot      # Translation template
```

## Changelog

### 1.1.3 - 2026-07-18
- New "Normalize" control in each supported field's Advanced tab (form editor): UPPER CASE, lower case, First letter upper case, First Letters Upper Case, or off, with a "More options…" link to the full Normalization tab
- Fields with multiple parts (e.g. Name, Address) show a "More options…" link only, since a single casing rarely fits every part — set them up per-part (or whole-field) from the Normalization tab
- The quick control stays in sync with the Normalization tab: it creates a matching rule there, and editing or deleting that rule from the tab updates the control back to "off"
- New "whole field" target option in the rule editor, for rules that should apply across every sub-field of a multi-input field
- Applies to future submissions only; existing entries are unaffected until previewed/applied from the Normalization tab

### 1.0.0 - 2026-07-18
- Initial release
- BEFORE/AFTER example-based detection of transformations, including two-step chains
- 16 transformations: trim/collapse/remove spaces, uppercase, lowercase, sentence case, title case, remove accents, keep letters only, keep letters and digits, keep digits only, remove digits, remove double quotes, strip HTML tags and code, remove links (URLs), French phone numbers to +33
- Preview and bulk apply to existing entries (50-entry batches, samples, reports)
- Optional normalization of future submissions
- GitHub auto-updates and Guilamu Bug Reporter integration

## Security

If you discover a security vulnerability in this plugin, please report it responsibly through [GitHub Security Advisories](https://github.com/guilamu/entry-normalizer-for-gravity-forms/security/advisories/new). Do not open a public issue for security reports.

## Contributing

Contributions are welcome! Please open an issue or submit a pull request on [GitHub](https://github.com/guilamu/entry-normalizer-for-gravity-forms).

For translations, the plugin uses WordPress i18n. You can contribute translations by editing the `.po` files in the `languages/` directory and generating the corresponding `.mo` files with the `wp i18n` CLI commands.

## License

This project is licensed under the GNU Affero General Public License v3.0 (AGPL-3.0) — see the [LICENSE](LICENSE) file for details.

---

Made with love for the WordPress community
