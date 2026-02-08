# Really Simple Cache

A blazing-fast, lightweight, file-based cache plugin for WordPress designed to dramatically reduce page load times without complicated settings or bloated features.

## 🚀 Overview

**Really Simple Cache** creates static HTML files of your WordPress pages and serves them directly to visitors. It also includes optional CSS/JS optimization, local Gravatar caching, and local Google/Bunny font storage.

No setup, no confusing options, no ads. Just enable and go.

## ✨ Features

- ⚡ **Ultra-lightweight** — minimal code, no fluff  
- 📁 **File-based page caching** (HTML output) for maximum compatibility  
- 🧹 **Automatic cache invalidation** on post/comment/menu/theme/customizer updates  
- 🎯 **Host+scheme aware cache keys** to prevent cross-domain cache collisions  
- 🧱 **Atomic cache writes** to reduce partial-file race conditions under load  
- 🎛️ **Settings UI** under `Settings > RS Cache` with modern toggle controls  
- 🔗 **Combine CSS Files** toggle  
- 🔗 **Combine JS Files** toggle  
- 👤 **Store Gravatar Avatars Locally** toggle  
- 🔤 **Store Bunny/Google Fonts Locally** toggle  
- 🚫 **Logged-in bypass** — page cache and output optimizations are skipped for logged-in users  
- 🧠 **Zero-configuration defaults** — works immediately, with optional advanced controls  
- 📝 **MIT License** — use it commercially or modify freely

## 📦 Installation

1. Download or clone this repository:

   ```bash
   git clone https://github.com/UnicornPanel/Really-Simple-Cache.git
   ```

2. Upload the folder into:

   ```text
   /wp-content/plugins/
   ```

3. Log in to WordPress Admin → **Plugins**  
4. Activate **Really Simple Cache**

That’s it! Caching begins immediately.

## 🔧 How It Works

- Guest users receive page caching and output optimization (based on toggles).
- Logged-in users bypass page caching and output optimization.
- On first guest page load, the plugin generates and stores a static HTML version.
- Subsequent visits deliver that file directly from disk until TTL expiry (default: 1 hour).
- The plugin also purges cache files after common content/config changes.
- Same-domain CSS/JS can be minified or combined (configurable).
- Gravatar avatars can be cached locally (configurable).
- Google/Bunny font localization is non-blocking:
  - first request keeps remote font URLs
  - a background task downloads/localizes font CSS and font files
  - page cache is purged so future cached pages use local font URLs

Font localization background processing uses WP-Cron.

## ⚙️ Settings (`Settings > RS Cache`)

- `Enable Page Cache`
- `Page Cache TTL (seconds)`
- `Minify HTML`
- `Minify CSS`
- `Minify JS`
- `Defer Scripts`
- `Show Debug Footer`
- `Combine CSS Files`
- `Combine JS Files`
- `Store Gravatar Avatars Locally`
- `Store Bunny and Google Fonts Locally`
- `Remote Asset TTL (seconds)` for avatar/font local cache refresh

## 📂 File Locations

Cache files are stored under WordPress uploads:

```text
/wp-content/uploads/really-simple-cache/
```

Subfolders:

- `pages/` page HTML cache
- `css/` minified CSS assets
- `js/` minified JS assets
- `avatars/` locally cached Gravatar images
- `fonts/css/` locally cached Google/Bunny font stylesheets
- `fonts/files/` locally cached font binaries

Removing files in this folder clears cache immediately.

## 🧪 When You Should Use It

Use this plugin if you want:

✔ A drop-in speed boost for small-to-medium WordPress sites  
✔ A low-maintenance caching layer  
✔ To reduce PHP processing and database queries

**Not recommended for:**

✘ Complex dynamic guest experiences without additional bypass rules  
✘ Sites requiring per-user or highly personalized cached responses

## ⚠️ Known Limitations

- HTML minification is intentionally conservative, but theme/plugin-specific markup can still be sensitive.
- Aggressive script deferring can break scripts that rely on immediate execution order.
- Default cookie bypass rules cover common cases (WordPress, WooCommerce, EDD), but custom apps may need extra bypasses using the `rsc_bypass_cookies` filter.
- JS combining intentionally skips scripts with `async`, `defer`, `type=\"module\"`, `integrity`, `crossorigin`, and `nomodule`.
- Font localization depends on WP-Cron. If `DISABLE_WP_CRON` is enabled, configure a server cron for `wp-cron.php`.

## 🛠 Development

Pull requests are welcome!

Guidelines:

- Follow WordPress coding standards  
- Keep it lightweight — this plugin’s philosophy is *simplicity*  
- One feature = one PR if possible

## 📜 License

This project is licensed under the **MIT License**.  
See the `LICENSE` file for full details.

## ⭐ Support the Project

If this plugin helped speed up your site, please give it a ⭐ on GitHub — it helps others discover it and motivates further development.

---

Happy caching! 🦄
