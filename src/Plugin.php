<?php

declare(strict_types=1);

namespace SiteAssetRouter;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\models\Site;
use craft\events\RegisterElementSourcesEvent;
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

    private function _routeAssetUpload(Asset $asset, bool $isNew, ?Site $cpSite = null): void
    {
        // ROUT-04: Only route new uploads
        if (!$isNew) {
            return;
        }

        // Console guard — no site context in CLI/queue
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        $request = Craft::$app->getRequest();
        $assetsService = Craft::$app->getAssets();

        // Resolve the asset's target folder.
        // Asset::beforeSave() consumes newFolderId into newLocation and nulls
        // both newFolderId and (for new assets) leaves folderId unset. The most
        // reliable source is therefore newLocation, which is always set by the
        // time EVENT_BEFORE_SAVE fires.
        $resolvedFolderId = $asset->folderId ?? $asset->newFolderId;

        if (!$resolvedFolderId) {
            $bodyFolderId = $request->getBodyParam('folderId');
            if ($bodyFolderId) {
                $resolvedFolderId = (int)$bodyFolderId;
            }
        }

        // Fallback: parse folder ID from newLocation (set by Asset::beforeSave())
        if (!$resolvedFolderId && $asset->newLocation) {
            if (preg_match('/^\{folder:(\d+)\}/', $asset->newLocation, $m)) {
                $resolvedFolderId = (int)$m[1];
            }
        }

        $folder = $resolvedFolderId
            ? $assetsService->getFolderById($resolvedFolderId)
            : null;

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

        // If the upload already targets a site-specific subfolder (e.g. the
        // asset browser source was rewritten by FilterService), skip re-routing.
        $folderPath = trim($folder->path ?? '', '/');
        if ($folderPath !== '') {
            $topSegment = explode('/', $folderPath)[0];
            $allSiteHandles = array_map(
                fn($s) => $s->handle,
                Craft::$app->getSites()->getAllSites()
            );
            if (in_array($topSegment, $allSiteHandles, true)) {
                return;
            }
        }

        // ROUT-02: _resolveRequestedSite() already checked ?site= and siteId body param;
        // fall through to getCurrentSite() only as a last resort (e.g. queue jobs).
        $site = $cpSite ?? Craft::$app->getSites()->getCurrentSite();

        if (!$site) {
            return;
        }

        // AUTO-01/AUTO-02: Find or create the site/volume subfolder (physical dir + DB record)
        $siteHandle = $site->handle;
        $subPath = $siteHandle . '/' . $volume->handle;
        $targetFolder = $assetsService->ensureFolderByFullPathAndVolume(
            $subPath,
            $volume,
            false
        );

        // ROUT-01/ROUT-03: Route the upload to the site/volume subfolder
        // Set newLocation directly — Asset::beforeSave() already consumed
        // newFolderId into newLocation before EVENT_BEFORE_SAVE fires
        $asset->newLocation = "{folder:{$targetFolder->id}}{$asset->getFilename()}";

        Craft::info(
            "Routed \"{$asset->filename}\" → \"{$subPath}/\" in \"{$volume->handle}\".",
            'site-asset-router'
        );
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
