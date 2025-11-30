# Really Simple Cache

A blazing-fast, lightweight, file-based cache plugin for WordPress designed to dramatically reduce page load times without complicated settings or bloated features.

## 🚀 Overview

**Really Simple Cache** creates static HTML files of your WordPress pages and serves them directly to visitors. This bypasses PHP and database queries — significantly improving performance, lowering CPU usage, and reducing TTFB.

No setup, no confusing options, no ads. Just enable and go.

## ✨ Features

- ⚡ **Ultra-lightweight** — minimal code, no fluff  
- 📁 **File-based caching** (HTML output) for maximum compatibility  
- 🔄 **Automatic cache invalidation** when content updates  
- 🧠 **Zero-configuration** — activate and forget  
- 📝 **MIT License** — use it commercially or modify freely  
- 🏁 Designed for **WordPress hosting environments that value speed**

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

- Works for users who are not logged in:
- On first page load, the plugin generates a static HTML version of the page  
- Subsequent visits deliver that file directly from disk  
- Cache is automatically cleared when you update posts/pages

No cron jobs, no external services, no configuration required.

## 📂 File Locations

Cached pages are stored inside:

```text
/wp-content/cache/really-simple-cache/
```

Manually deleting this folder clears the cache instantly.

## 🧪 When You Should Use It

Use this plugin if you want:

✔ A drop-in speed boost for small-to-medium WordPress sites  
✔ A no-maintenance caching layer  
✔ To reduce PHP processing and database queries

**Not recommended for:**

✘ Complex logged-in experiences (memberships, WooCommerce carts, etc.)  
✘ Sites requiring per-user or dynamic cached responses

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
