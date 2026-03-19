# SRK Add-On Attribute Sync Bundle v1.1

This bundle contains two plugins:

1. **srk-wc-addon-bridge-live**
   - install on the live website
   - exposes a protected REST endpoint for product add-on and attribute meta

2. **srk-wc-addon-attribute-sync-dev**
   - install on the dev website
   - now includes:
     - live connection indicator
     - test connection button
     - AJAX live log
     - automatic batch-after-batch running
     - clear logs button
     - live progress counters

## Recommended workflow

1. Import products with your main importer
2. Install the live bridge plugin on the live site
3. Save a secret key on the live bridge page
4. Install the dev sync plugin on the dev site
5. Add the live URL and same secret key
6. Click **Test Connection**
7. Click **Run Auto Sync**
8. Let it continue automatically batch by batch until completed

## Important

- The same product add-ons plugin should be installed on both live and dev
- Best matching works by SKU
- Fallback matching uses slug
- It syncs keys containing add-on/add_on/attribute plus common WooCommerce add-on keys
