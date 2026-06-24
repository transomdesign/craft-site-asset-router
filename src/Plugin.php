<?php

declare(strict_types=1);

namespace SiteAssetRouter;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\models\Site;
use craft\events\RegisterElementSourcesEvent;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\Cp;
use craft\log\MonologTarget;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use SiteAssetRouter\models\Settings;
use SiteAssetRouter\services\FilterService;
use yii\base\Event;
use yii\log\Dispatcher;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = false;
    public bool $hasCpSection = false;

    public function init(): void
    {
        parent::init();

        $this->_registerLogTarget();
        $this->_registerFilterService();

        // Upload routing: route new asset uploads to site subfolders
        Event::on(
            Asset::class,
            Asset::EVENT_BEFORE_SAVE,
            function (ModelEvent $event) {
                /** @var Asset $asset */
                $asset = $event->sender;
                $this->_routeAssetUpload($asset, $event->isNew, $this->_resolveRequestedSite());
            }
        );

        // Source filtering: restrict CP asset browser to current site's subfolder
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            Event::on(
                Asset::class,
                Asset::EVENT_REGISTER_SOURCES,
                function (RegisterElementSourcesEvent $event) {
                    /** @var FilterService $filter */
                    $filter = $this->get('filter');
                    $filter->filterSources($event->sources, $event->context, $this->_resolveRequestedSite());
                }
            );
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    private function _resolveRequestedSite(): ?Site
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return null;
        }

        $request = Craft::$app->getRequest();

        // Prefer an explicit ?site=handle URL param (Cp::requestedSite() also reads this)
        $siteHandle = $request->getQueryParam('site');
        if ($siteHandle) {
            $site = Craft::$app->getSites()->getSiteByHandle((string)$siteHandle);
            if ($site) {
                return $site;
            }
        }

        // In CP AJAX requests (element index, asset browser, uploads) the editing
        // site is passed as siteId in the POST body but NOT in the URL, which means
        // Cp::requestedSite() silently falls back to the primary site. Read the body
        // param explicitly so the correct editing-context site is always returned.
        $siteId = $request->getBodyParam('siteId');
        if ($siteId) {
            $site = Craft::$app->getSites()->getSiteById((int)$siteId);
            if ($site) {
                return $site;
            }
        }

        return Cp::requestedSite();
    }

    /**
     * Route an asset save to its `{siteHandle}/{volumeHandle}/` subfolder.
     *
     * Fires for every Asset::EVENT_BEFORE_SAVE. It acts only when the save is a
     * real MOVE/UPLOAD whose target folder is NOT already site-prefixed — which
     * covers three cases the old `!$isNew` + console guards used to miss:
     *   - new uploads (CP / entry-field),
     *   - Craft's AssetsField restricted-location enforcement, which moves
     *     related assets to the volume root on canonical entry save
     *     (`isNew = false`), and
     *   - the same move happening in a console/queue context (migrations).
     *
     * @param bool $isNew Retained for signature stability / callers; routing is
     *                    now driven by the move target, not the new flag.
     */
    private function _routeAssetUpload(Asset $asset, bool $isNew, ?Site $cpSite = null): void
    {
        $request = Craft::$app->getRequest();
        $assetsService = Craft::$app->getAssets();

        // Determine the TARGET folder of this save. No target => not a move, but a
        // file-bearing save (a CP "replace") can still strand a file in the volume
        // root, so hand off to the stranded-root guard before bailing.
        $targetFolderId = $this->_targetFolderId($asset);
        if (!$targetFolderId) {
            $this->_rehomeStrandedRootAsset($asset);
            return;
        }

        $targetFolder = $assetsService->getFolderById($targetFolderId);
        if (!$targetFolder) {
            return;
        }

        $volume = $targetFolder->getVolume();
        if (!$volume) {
            return;
        }

        // CONF-01: Volume exclusion check
        /** @var Settings $settings */
        $settings = $this->getSettings();
        if (in_array($volume->handle, $settings->excludedVolumes, true)) {
            return;
        }

        // Loop safety: the target is already under a known site handle — leave it
        // (prevents double-nesting and re-entrancy when our own rewrite re-saves).
        if ($this->_siteFromFolderPath($targetFolder->path) !== null) {
            return;
        }

        // Site-resolution cascade:
        //   1. Preserve the asset's current site: if it already lives under
        //      {site}/..., re-anchor the move back into that site's subfolder.
        //      This catches restrictLocation-driven moves to the volume root and
        //      needs no request context, so it works in console/queue/migration.
        //   2. The CP-requested site (new uploads from an entry editor/browser).
        //   3. getCurrentSite() as a last resort — WEB requests only, so a
        //      queue/console move is never misfiled into the primary site.
        $sourceFolder = $asset->folderId ? $assetsService->getFolderById($asset->folderId) : null;
        $site = $this->_siteFromFolderPath($sourceFolder?->path);
        $site ??= $cpSite;
        if ($site === null && !$request->getIsConsoleRequest()) {
            $site = Craft::$app->getSites()->getCurrentSite();
        }
        if ($site === null) {
            return;
        }

        // AUTO-01/AUTO-02: Find or create the site/volume subfolder (physical dir + DB record)
        $subPath = $site->handle . '/' . $volume->handle;
        $destFolder = $assetsService->ensureFolderByFullPathAndVolume($subPath, $volume, false);

        // Already heading there — nothing to rewrite.
        if ($destFolder->id === $targetFolderId) {
            return;
        }

        // ROUT-01/ROUT-03: Re-anchor the save to the site/volume subfolder.
        // Setting newLocation here does not re-trigger beforeSave; it's read once
        // in Asset::_relocateFile() during afterSave.
        $asset->newLocation = "{folder:{$destFolder->id}}{$asset->getFilename()}";

        $sourcePath = trim($sourceFolder?->path ?? '', '/');
        if ($sourcePath !== '') {
            Craft::info(
                "Re-anchored \"{$asset->filename}\" from \"{$sourcePath}/\" → \"{$subPath}/\" in \"{$volume->handle}\".",
                'site-asset-router'
            );
        } else {
            Craft::info(
                "Routed \"{$asset->filename}\" → \"{$subPath}/\" in \"{$volume->handle}\".",
                'site-asset-router'
            );
        }
    }

    /**
     * Resolve the move/upload target folder id for the asset being saved.
     *
     * For a move (e.g. AssetsField enforcing a restricted location) the target
     * lives in `newLocation` while `$asset->folderId` still points at the SOURCE,
     * so `newLocation` must take precedence. New uploads also arrive with their
     * target in `newLocation`/`newFolderId`. Returns null when there is no pending
     * move (e.g. a metadata-only re-save), so such saves are a no-op.
     */
    private function _targetFolderId(Asset $asset): ?int
    {
        if ($asset->newLocation) {
            try {
                [$folderId] = AssetsHelper::parseFileLocation($asset->newLocation);
                if ($folderId) {
                    return (int)$folderId;
                }
            } catch (\Throwable) {
                // Malformed newLocation — fall through to the other sources.
            }
        }

        if ($asset->newFolderId) {
            return (int)$asset->newFolderId;
        }

        // Body param only exists on web requests (console Request has none).
        $request = Craft::$app->getRequest();
        if (!$request->getIsConsoleRequest()) {
            /** @var \craft\web\Request $request */
            $bodyFolderId = $request->getBodyParam('folderId');
            if ($bodyFolderId) {
                return (int)$bodyFolderId;
            }
        }

        return null;
    }

    /**
     * Re-home an asset that a file-bearing save would otherwise leave stranded in
     * a non-excluded volume's root folder.
     *
     * A CP "replace" fires Asset::EVENT_BEFORE_SAVE with no move target (no
     * newLocation/newFolderId), so the regular routing in _routeAssetUpload() is a
     * no-op and the new file is written to whatever folder the record already
     * points at. When that's the bare volume root the file lands at the top of the
     * filesystem instead of its `{site}/{volume}/` subfolder. The replace request
     * carries no site context (Cp::requestedSite() would fall back to the primary
     * site), so the destination site is resolved from the asset's relations; when
     * that's unresolvable or spans multiple sites the asset is left untouched and a
     * warning is logged rather than risk filing it under the wrong site.
     */
    private function _rehomeStrandedRootAsset(Asset $asset): void
    {
        // Only a save that writes a file (replace/create) can strand one in the
        // root; a metadata-only re-save must stay a no-op.
        if ($asset->tempFilePath === null || !$asset->folderId) {
            return;
        }

        $assetsService = Craft::$app->getAssets();
        $folder = $assetsService->getFolderById($asset->folderId);
        if (!$folder) {
            return;
        }

        $volume = $folder->getVolume();
        if (!$volume) {
            return;
        }

        // CONF-01: Volume exclusion check
        /** @var Settings $settings */
        $settings = $this->getSettings();
        if (in_array($volume->handle, $settings->excludedVolumes, true)) {
            return;
        }

        // Only act on the bare volume root — an asset already in any subfolder
        // (site-prefixed or otherwise) is left exactly where it is.
        if (trim((string)$folder->path, '/') !== '') {
            return;
        }

        $filename = $asset->getFilename();

        $site = $this->_siteFromRelations($asset);
        if ($site === null) {
            Craft::warning(
                "Could not re-home stranded \"{$filename}\" in \"{$volume->handle}\": no single owning " .
                'site resolvable from its relations — left in the volume root.',
                'site-asset-router'
            );
            return;
        }

        $subPath = $site->handle . '/' . $volume->handle;
        $destFolder = $assetsService->ensureFolderByFullPathAndVolume($subPath, $volume, false);

        // Already at the root we'd route into (shouldn't happen for a real site
        // subfolder, but guards against a redundant rewrite).
        if ($destFolder->id === $asset->folderId) {
            return;
        }

        $asset->newLocation = "{folder:{$destFolder->id}}{$filename}";

        Craft::info(
            "Re-homed stranded \"{$filename}\" from the \"{$volume->handle}\" volume root → \"{$subPath}/\".",
            'site-asset-router'
        );
    }

    /**
     * Resolve the single Site that owns an asset, derived from the elements that
     * relate to it. Returns null when there are no relations or they span more
     * than one site (ambiguous) — used to re-home a root-stranded asset where the
     * request itself carries no site context.
     */
    private function _siteFromRelations(Asset $asset): ?Site
    {
        $sitesService = Craft::$app->getSites();

        $sites = [];
        foreach ($this->_relationSiteIds((int)$asset->id) as $siteId) {
            $site = $sitesService->getSiteById($siteId);
            if ($site) {
                $sites[$site->id] = $site;
            }
        }

        return count($sites) === 1 ? reset($sites) : null;
    }

    /**
     * Distinct site IDs of the elements that relate to the given asset.
     *
     * Relations with a NULL sourceSiteId (a non-localized relation field applies
     * to every site) are expanded to the site IDs their source element actually
     * exists on, via elements_sites.
     *
     * @return int[]
     */
    protected function _relationSiteIds(int $assetId): array
    {
        $rows = (new Query())
            ->select(['sourceId', 'sourceSiteId'])
            ->distinct()
            ->from(Table::RELATIONS)
            ->where(['targetId' => $assetId])
            ->all();

        if (!$rows) {
            return [];
        }

        $siteIds = [];
        $unscopedSourceIds = [];
        foreach ($rows as $row) {
            if ($row['sourceSiteId'] !== null) {
                $siteIds[(int)$row['sourceSiteId']] = true;
            } else {
                $unscopedSourceIds[] = (int)$row['sourceId'];
            }
        }

        if ($unscopedSourceIds) {
            $expanded = (new Query())
                ->select(['siteId'])
                ->distinct()
                ->from(Table::ELEMENTS_SITES)
                ->where(['elementId' => array_unique($unscopedSourceIds)])
                ->column();
            foreach ($expanded as $siteId) {
                $siteIds[(int)$siteId] = true;
            }
        }

        return array_keys($siteIds);
    }

    /**
     * Return the Site whose handle matches the first segment of a volume-folder
     * path (e.g. "tenor/bottleShots/" → the `tenor` site), or null when the path
     * is the volume root or its top segment isn't a site handle.
     */
    private function _siteFromFolderPath(?string $path): ?Site
    {
        $path = trim((string)$path, '/');
        if ($path === '') {
            return null;
        }
        $top = explode('/', $path)[0];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if ($site->handle === $top) {
                return $site;
            }
        }
        return null;
    }

    private function _registerFilterService(): void
    {
        $this->set('filter', function () {
            $service = new FilterService();
            /** @var Settings $settings */
            $settings = $this->getSettings();
            $service->settings = $settings;
            return $service;
        });
    }

    private function _registerLogTarget(): void
    {
        if (Craft::getLogger()->dispatcher instanceof Dispatcher) {
            Craft::getLogger()->dispatcher->targets['site-asset-router'] = new MonologTarget([
                'name' => 'site-asset-router',
                'categories' => ['site-asset-router'],
                'level' => LogLevel::INFO,
                'logContext' => false,
                'allowLineBreaks' => false,
                'formatter' => new LineFormatter(
                    format: "[%datetime%] %message%\n",
                    dateFormat: 'Y-m-d H:i:s',
                ),
            ]);
        }
    }
}
