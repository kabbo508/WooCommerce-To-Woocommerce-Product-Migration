# SRK WooCommerce Live to Dev Importer

A WordPress plugin for importing WooCommerce catalog data from a live store into a dev site.

## Features

- One-click auto import for all remote product pages
- Import products, categories, tags, images, galleries, variations, and exposed meta data
- Variable product support
- Product add-on data support when available in WooCommerce REST API `meta_data`
- Delete dev products, categories, and tags before import
- AJAX live progress log
- Connection status light with green/red dot
- Clear logs button
- Category filtering by remote category IDs
- Gallery re-sync mode
- Smarter duplicate handling

## Recommended workflow

1. Install the plugin on the dev site.
2. Add the live site URL and WooCommerce REST API keys.
3. Click **Test Connection**.
4. Optionally enable **Reset dev data before import**.
5. Keep **Auto import all pages** enabled.
6. Click **Run Auto Import** once.
7. Let it continue automatically until the log shows completion.

## Live site connection setup

Go to:

`WooCommerce → Settings → Advanced → REST API`

Then:

1. Add a new key
2. Choose an admin user
3. Set permissions to **Read**
4. Copy the **Consumer Key** and **Consumer Secret**
5. Paste them into the plugin settings on the dev site

## Notes

- For Product Add-Ons or similar plugins, install the same plugin on the dev site too.
- Custom plugin data can only be imported if the live site exposes it through the WooCommerce REST API.
- Auto import all pages ignores the Max Pages limit and stops only when the remote API returns no more products.

## Author

**Sumon Rahman Kabbo**  
https://sumonrahmankabbo.com/

## New in v3.5

- Resume stopped import from the last saved batch/page
- Added logging for Product Add-Ons related meta keys when they are returned by the live WooCommerce API

## Important Product Add-Ons note

This plugin can only import Product Add-Ons data that is actually returned by the live WooCommerce REST API. If the add-on plugin stores data in protected post meta that is not exposed by the API, that part cannot be copied through this importer alone.

## New in v3.5

- Durable resume checkpoint saved after every finished page
- Better recovery after AJAX/browser interruption
- Automatic client-side retry for temporary AJAX failures
