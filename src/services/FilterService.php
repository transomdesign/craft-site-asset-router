<?php

declare(strict_types=1);

namespace SiteAssetRouter\services;

use Craft;
use craft\base\Component;
use craft\models\Site;
use SiteAssetRouter\models\Settings;

class FilterService extends Component
{
    public Settings $settings;

    /**
     * Rewrites volume source criteria to point at the current site's subfolder
     * plus the volume root (for backwards-compat with pre-router assets).
     *
     * Called from Asset::EVENT_REGISTER_SOURCES. The event handler resolves
     * the site via Plugin::_resolveRequestedSite() and passes it in; null
     * means no site context is available (skip filtering).
     */
    public function filterSources(array &$sources, string $context, ?Site $site): void
    {
        // Guard: skip settings context (volume configuration screens)
        if ($context === 'settings') {
            return;
        }

        // Guard: skip console requests (no CP context)
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        if ($site === null) {
            return;
        }

        $siteHandle = $site->handle;
        $assetsService = Craft::$app->getAssets();
        $volumesService = Craft::$app->getVolumes();

        foreach ($sources as &$source) {
            // Skip non-volume sources (headings, temp sources, etc.)
            if (!isset($source['key']) || !str_starts_with($source['key'], 'volume:')) {
                continue;
            }

            // Guard: must have volume-handle in data
            $volumeHandle = $source['data']['volume-handle'] ?? null;
            if ($volumeHandle === null) {
                continue;
            }

            // Skip excluded volumes
            if (in_array($volumeHandle, $this->settings->excludedVolumes, true)) {
                continue;
            }

            $volume = $volumesService->getVolumeByHandle($volumeHandle);
            if ($volume === null) {
                continue;
            }

            // Ensure the site/volume subfolder DB record exists (justRecord=true: no filesystem write)
            $subPath = $siteHandle . '/' . $volumeHandle;
            $siteFolder = $assetsService->ensureFolderByFullPathAndVolume(
                $subPath,
                $volume,
                true
            );

            // Include the volume root folder alongside the site subfolder so
            // assets uploaded before the router was set up remain visible.
            $rootFolder = $assetsService->getRootFolderByVolumeId($volume->id);
            $folderIds = array_values(array_filter(array_unique([
                $rootFolder?->id,
                $siteFolder->id,
            ])));

            $source['criteria']['folderId'] = count($folderIds) === 1 ? $folderIds[0] : $folderIds;
            $source['data']['folder-id'] = $siteFolder->id;
        }
        unset($source);
    }
}
