# Graph Report - plugin-wordpress  (2026-07-30)

## Corpus Check
- 7 files · ~5,634 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 80 nodes · 129 edges · 12 communities (8 shown, 4 thin omitted)
- Extraction: 73% EXTRACTED · 27% INFERRED · 0% AMBIGUOUS · INFERRED: 35 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `168b82b1`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Krynox_Captcha
- bootstrap.php
- Krynox Captcha for WordPress
- WP_Error
- .options
- Changelog
- Krynox_Test_WP_Die_Exception
- .add_settings_page
- .register_settings

## God Nodes (most connected - your core abstractions)
1. `Krynox_Captcha` - 20 edges
2. `WP_Error` - 7 edges
3. `Krynox Captcha for WordPress` - 6 edges
4. `esc_url()` - 5 edges
5. `sanitize_text_field()` - 5 edges
6. `is_wp_error()` - 4 edges
7. `wp_unslash()` - 4 edges
8. `add_filter()` - 3 edges
9. `add_action()` - 3 edges
10. `wp_remote_retrieve_response_code()` - 3 edges

## Surprising Connections (you probably didn't know these)
- None detected - all connections are within the same source files.

## Import Cycles
- None detected.

## Communities (12 total, 4 thin omitted)

### Community 0 - "Krynox_Captcha"
Cohesion: 0.20
Nodes (8): Krynox_Captcha, apply_filters(), esc_url_raw(), sanitize_text_field(), wp_die(), wp_json_encode(), wp_remote_post(), wp_unslash()

### Community 1 - "bootstrap.php"
Cohesion: 0.20
Nodes (11): add_action(), add_filter(), checked(), esc_attr(), esc_html(), is_wp_error(), plugin_basename(), settings_fields() (+3 more)

### Community 2 - "Krynox Captcha for WordPress"
Cohesion: 0.25
Nodes (7): Advanced: act on the full result, Honeypot, How it works, Install, Krynox Captcha for WordPress, License, Settings

### Community 4 - ".options"
Cohesion: 0.22
Nodes (5): admin_url(), esc_url(), get_option(), wp_enqueue_script(), wp_parse_args()

### Community 6 - "Changelog"
Cohesion: 0.40
Nodes (4): [0.1.0] - 2026-07-22, Added, Changelog, [Unreleased]

## Knowledge Gaps
- **7 isolated node(s):** `[Unreleased]`, `Added`, `Install`, `Advanced: act on the full result`, `Settings` (+2 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **4 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Krynox_Captcha` connect `Krynox_Captcha` to `.add_settings_page`, `.register_settings`, `.options`, `bootstrap.php`?**
  _High betweenness centrality (0.122) - this node is a cross-community bridge._
- **Why does `WP_Error` connect `WP_Error` to `bootstrap.php`?**
  _High betweenness centrality (0.105) - this node is a cross-community bridge._
- **Why does `Krynox_Test_WP_Die_Exception` connect `Krynox_Test_WP_Die_Exception` to `bootstrap.php`?**
  _High betweenness centrality (0.037) - this node is a cross-community bridge._
- **Are the 4 inferred relationships involving `esc_url()` (e.g. with `.enqueue()` and `.field()`) actually correct?**
  _`esc_url()` has 4 INFERRED edges - model-reasoned connections that need verification._
- **Are the 4 inferred relationships involving `sanitize_text_field()` (e.g. with `.client_ip()` and `.honeypot()`) actually correct?**
  _`sanitize_text_field()` has 4 INFERRED edges - model-reasoned connections that need verification._
- **What connects `[Unreleased]`, `Added`, `Install` to the rest of the system?**
  _7 weakly-connected nodes found - possible documentation gaps or missing edges._