# Site Asset Router

A Craft CMS 5 plugin for multi-site installations that automatically organizes assets into site-specific subfolders and scopes the CP asset browser to the active site.

## What It Does

In a multisite Craft installation where all sites share the same asset volumes, uploads from different sites end up mixed together. Site Asset Router solves this by:

1. **Upload routing** — New uploads are automatically placed in `{siteHandle}/{volumeHandle}/` subfolders within each volume.
2. **Asset browser filtering** — The CP asset browser shows only the active site's assets per volume, so editors never see another site's files.

### Folder Structure

Given three sites (`brandA`, `brandB`, `brandC`) and a volume called `images`, uploads are organized as:

```
images/                        (volume root on filesystem)
  brandA/images/               uploads from Brand A
  brandB/images/               uploads from Brand B
  brandC/images/               uploads from Brand C
```

Subfolders are created automatically on first upload — no manual setup required.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Installation

```bash
composer require transomdesign/craft-site-asset-router
```

> If you're running DDEV, prefix with `ddev exec`.

Then install the plugin:

```bash
php craft plugin/install site-asset-router
```

The plugin appears as "Site Asset Router" in the Craft CP under Settings > Plugins.

## Configuration

Create or edit `config/site-asset-router.php`:

```php
<?php

return [
    // Volume handles to exclude from site-based routing.
    // Excluded volumes keep their default behavior — uploads go to
    // whatever location the field or asset browser specifies, and the
    // asset browser shows all folders without filtering.
    'excludedVolumes' => ['fonts', 'favicons', 'icons'],
];
```

### Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `excludedVolumes` | `string[]` | `[]` | Volume handles to exclude from routing and filtering |

Volumes that contain shared/global assets (fonts, favicons, icons) should typically be excluded since they don't need per-site separation.

## How It Works

### Upload Routing

The plugin listens to `Asset::EVENT_BEFORE_SAVE` and intercepts new uploads:

1. **Resolves the target volume** from the asset's folder, body params, or by parsing `Asset::newLocation` (which `Asset::beforeSave()` sets before the event fires).
2. **Checks for exclusion** — if the volume is in `excludedVolumes`, the upload proceeds normally.
3. **Checks for existing site path** — if the upload already targets a site subfolder (e.g., the asset browser was filtered), it skips re-routing to avoid double-nesting.
4. **Resolves the active site** using this priority:
   - `Cp::requestedSite()` — the site shown in the CP header (works for AJAX uploads via session)
   - `siteId` body param — sent by entry editors when uploading from asset fields
   - `getCurrentSite()` — last resort fallback
5. **Routes the upload** to `{siteHandle}/{volumeHandle}/` within the volume, creating the subfolder if needed.

### Asset Browser Filtering

The plugin listens to `Asset::EVENT_REGISTER_SOURCES` and rewrites volume sources:

1. For each volume source in the sidebar, it resolves the active CP site via `Cp::requestedSite()`.
2. It ensures a `{siteHandle}/{volumeHandle}/` subfolder record exists in the database.
3. It rewrites the source's `criteria.folderId` and `data.folder-id` to point at that subfolder.

The result: clicking "Images" in the asset sidebar shows only `brandA/images/` contents when Brand A is the active site. Switching sites in the CP header updates the view automatically.

### Safety Guards

| Scenario | Behavior |
|----------|----------|
| Console / CLI commands | Routing skipped (no site context) |
| Queue jobs | Routing skipped (console request) |
| Re-saving existing assets | Routing skipped (`isNew = false`) |
| Excluded volumes | Routing and filtering both skipped |
| Settings context (volume config screens) | Filtering skipped |
| Null site (no CP context) | Routing and filtering both skipped |

## Asset Field Configuration

Once the plugin is active, you can **remove** any manual `{ object.site.handle }/volumeName` values from your Asset fields' "Asset Location" settings. The plugin handles path routing automatically for all upload sources:

- Drag-and-drop into the asset browser
- The "Upload files" button in the asset browser
- Upload buttons on asset fields in entry editors

## Logging

The plugin logs routing decisions to a dedicated `site-asset-router` log channel:

```
[2026-03-06 04:18:23] Routed "hero-shot.jpg" -> "brandA/images/" in "images".
```

Check `storage/logs/site-asset-router-*.log` for routing activity.

## Running Tests

```bash
cd plugins/site-asset-router
php ../../vendor/bin/phpunit
```

## Known Limitations

- **CLI uploads are not routed.** Assets created via console commands or queue jobs bypass routing because there is no CP site context. These assets land wherever Craft's default logic places them.
- **Existing assets are not migrated.** Installing the plugin does not move previously uploaded assets into site subfolders. Only new uploads are routed.
- **No per-field routing.** All non-excluded volumes use the same `{siteHandle}/{volumeHandle}/` pattern. There is no way to configure different subfolder structures per field.

## File Structure

```
src/
  Plugin.php              Main plugin class, event registration, upload routing
  models/
    Settings.php          Settings model (excludedVolumes)
  services/
    FilterService.php     Asset browser source filtering
tests/
  bootstrap.php
  unit/
    RoutingHandlerTest.php
    FilterServiceTest.php
```
