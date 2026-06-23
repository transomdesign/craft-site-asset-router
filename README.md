# Site Asset Router

A Craft CMS 5 plugin for multi-site installations that organizes assets into site-specific subfolders and scopes the CP asset browser to the active site.

## What it does

In a multisite Craft installation where all sites share the same asset volumes, uploads from different sites end up mixed together. Site Asset Router solves this by:

1. **Upload routing:** New uploads are placed in `{siteHandle}/{volumeHandle}/` subfolders within each volume.
2. **Asset browser filtering:** The CP asset browser shows only the active site's assets per volume, so editors never see another site's files.

### Folder structure

Given three sites (`brandA`, `brandB`, `brandC`) and a volume called `images`, uploads are organized as:

```
images/                        (volume root on filesystem)
  brandA/images/               uploads from Brand A
  brandB/images/               uploads from Brand B
  brandC/images/               uploads from Brand C
```

Subfolders are created on first upload. No manual setup required.

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

## Asset field setup (required)

For every **Asset field** that uploads into a routed volume, set these two options in the field's settings (Settings → Fields → your field):

| Field setting | Value |
|---|---|
| **Restrict assets to a single location** | **On** — pointed at the volume |
| **Allow subfolders** | **On** |

That's it. The plugin then files uploads under `{siteHandle}/{volumeHandle}/` and keeps them there on every save.

> ⚠️ **Never use "Restrict location: On" + "Allow subfolders: Off"** on a routed volume. Craft moves every related asset to the volume root on each entry save, which fights the plugin and scatters files. (The plugin will fight back and re-anchor them, but only at the cost of a move on every save — so just turn subfolders on.)

**Don't want to restrict the field to one volume?** Then leave **"Restrict assets to a single location" Off** — the plugin still routes uploads to the correct `{site}/{volume}/` folder. Either of these is fine; the broken combo above is the only one to avoid.

## Configuration

Create or edit `config/site-asset-router.php`:

```php
<?php

return [
    // Volume handles to exclude from site-based routing.
    // Excluded volumes keep their default behavior; uploads go to
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

## How it works

### Upload routing

The plugin listens to `Asset::EVENT_BEFORE_SAVE` and intercepts new uploads:

1. **Resolves the target volume** from the asset's folder, body params, or by parsing `Asset::newLocation` (which `Asset::beforeSave()` sets before the event fires).
2. **Checks for exclusion:** if the volume is in `excludedVolumes`, the upload proceeds normally.
3. **Checks for an existing site path:** if the upload already targets a site subfolder (e.g., the asset browser was filtered), it skips re-routing to avoid double-nesting.
4. **Resolves the active site** using this priority:
   - `Cp::requestedSite()`: the site shown in the CP header, also covers AJAX uploads
   - `siteId` body param: sent by entry editors when uploading from an asset field
   - `getCurrentSite()`: last resort
5. **Routes the upload** to `{siteHandle}/{volumeHandle}/` within the volume, creating the subfolder if needed.

### Asset browser filtering

The plugin listens to `Asset::EVENT_REGISTER_SOURCES` and rewrites volume sources:

1. For each volume source in the sidebar, it resolves the active CP site via `Cp::requestedSite()`.
2. It ensures a `{siteHandle}/{volumeHandle}/` subfolder record exists in the database.
3. It rewrites the source's `criteria.folderId` and `data.folder-id` to point at that subfolder.

Clicking "Images" in the asset sidebar shows only `brandA/images/` contents when Brand A is the active site. Switching sites in the CP header updates the view automatically.

### Relocation re-anchoring (existing assets)

Routing is driven by the **move target**, not by whether the asset is new. Whenever a save would land an asset at a *non* site-prefixed folder (typically the volume root), the router re-anchors it to `{siteHandle}/{volumeHandle}/`. The destination site is resolved in this order:

1. **Source-site preservation** — if the asset already lives under `{site}/…`, it is kept in that site's subfolder. This needs no request context, so it works in **console / queue / migration** runs.
2. **CP-requested site** — for new uploads from an entry editor or the asset browser.
3. **`getCurrentSite()`** — web requests only (a queue/console move is never misfiled into the primary site).

This is what makes the router survive Craft's own asset moves — most notably an Assets field with `restrictLocation: true` (see below), which calls `moveAsset()` to the restricted upload folder on every canonical entry save.

### Safety guards

| Scenario | Behavior |
|----------|----------|
| Metadata-only re-save (no move) | No-op (no target folder) |
| Move/upload to an already site-prefixed folder | No-op (left as-is; prevents double-nesting) |
| Console / queue move of a site-foldered asset | Re-anchored to the asset's **source** site |
| Console / queue move with no resolvable site | No-op (left as-is) |
| New upload (web) | Routed to the CP/current site |
| Excluded volumes | Routing and filtering both skipped |
| Settings context (volume config screens) | Filtering skipped |

## Asset field configuration

Once the plugin is active, you can **remove** any manual `{ object.site.handle }/volumeName` values from your Asset fields' "Asset Location" settings. The plugin handles path routing for all upload sources:

- Drag-and-drop into the asset browser
- The "Upload files" button in the asset browser
- Upload buttons on asset fields in entry editors

### Why "Allow subfolders" must be on

See [Asset field setup](#asset-field-setup-required) for the required settings. The reason: with `restrictLocation: true` + `allowSubfolders: false`, Craft's `Assets::afterElementSave()` calls `moveAsset()` to drag every related asset into the field's restricted upload folder (the volume root) on each canonical save — overriding this plugin's per-site placement. `allowSubfolders: true` makes Craft treat assets already under the volume's site subfolders as valid and leave them alone. The relocation re-anchoring above is the safety net if a field is misconfigured, but it costs a move on every save, so the config should be correct.

## Logging

The plugin logs routing decisions to a dedicated `site-asset-router` log channel:

```
[2026-03-06 04:18:23] Routed "hero-shot.jpg" -> "brandA/images/" in "images".
```

Check `storage/logs/site-asset-router-*.log` for routing activity.

## Running tests

```bash
cd plugins/site-asset-router
php ../../vendor/bin/phpunit
```

## Known limitations

- **Brand-new CLI/queue uploads with no site context are not routed.** An asset *created* from scratch in a console command or queue job has no source-site folder and no CP request, so it lands wherever Craft's default logic places it. (Console *relocations* of assets that already live under a site subfolder **are** re-anchored — see "Relocation re-anchoring".)
- **Installing the plugin does not retroactively move existing assets.** It routes/re-anchors assets as they are saved or moved; it does not sweep the volume on install. Use `resave/entries` (or a one-off move) to migrate a back-catalogue.
- **No per-field routing.** All non-excluded volumes use the same `{siteHandle}/{volumeHandle}/` pattern. There is no way to configure different subfolder structures per field.

## File structure

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
