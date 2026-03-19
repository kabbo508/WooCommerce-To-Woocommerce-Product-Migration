SRK WC Live to Dev Importer v3.5

New in v3
- Delete option inside the same plugin
- Delete dev products, variations, categories, and tags before import
- Better approach to avoid duplicates, best used with reset before import
- Modern admin UI
- AJAX live progress
- Category filtering
- Gallery re-sync
- Variation import
- Product meta copy for plugin data exposed by WooCommerce REST API

How to connect
1. On live site:
   WooCommerce > Settings > Advanced > REST API
2. Add a new key
3. Permission: Read
4. Copy Consumer Key and Consumer Secret

On dev site
1. Install this plugin
2. Go to WooCommerce > Live to Dev Importer
3. Enter:
   - live site URL
   - consumer key
   - consumer secret
4. Save settings
5. Test connection
6. Optional: enable reset before import
7. Start auto batch import

Delete button
- Deletes dev products and variations
- Deletes product categories except Uncategorized
- Deletes all product tags

Important
- For Product Add-Ons or similar plugins, install the same plugin on the dev site too.
- This plugin copies meta_data only if the live site exposes it through the WooCommerce REST API.

- Connection status light with green/red dot
- Clear logs button

- Auto import all pages with one click until no more remote products are found
- Added README.md file for the plugin package

- Resume stopped import from the last saved page after AJAX/browser interruption

- Durable resume checkpoint saved after each finished page
- Automatic AJAX retry before stopping the importer
