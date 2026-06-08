<?php

declare(strict_types=1);

namespace SiteAssetRouter\tests\unit;

use Craft;
use craft\elements\Asset;
use craft\models\Site;
use craft\models\Volume;
use craft\models\VolumeFolder;
use craft\services\Assets as AssetsService;
use craft\services\Sites as SitesService;
use craft\web\Application;
use craft\web\Request;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SiteAssetRouter\models\Settings;
use SiteAssetRouter\Plugin;

class RoutingHandlerTest extends TestCase
{
    private Plugin&MockObject $plugin;
    private \ReflectionMethod $routeMethod;
    private Request&MockObject $mockRequest;
    private SitesService&MockObject $mockSites;
    private AssetsService&MockObject $mockAssets;
    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRequest = $this->createMock(Request::class);
        $this->mockSites = $this->createMock(SitesService::class);
        $this->mockAssets = $this->createMock(AssetsService::class);

        $mockApp = $this->createMock(Application::class);
        $mockApp->method('getRequest')->willReturn($this->mockRequest);
        $mockApp->method('getSites')->willReturn($this->mockSites);
        $mockApp->method('getAssets')->willReturn($this->mockAssets);

        Craft::$app = $mockApp;

        $this->settings = new Settings();

        $this->plugin = $this->createPartialMock(Plugin::class, ['getSettings']);
        $this->plugin->method('getSettings')->willReturn($this->settings);

        $this->routeMethod = new \ReflectionMethod(Plugin::class, '_routeAssetUpload');
        $this->routeMethod->setAccessible(true);
    }

    private function invokeRoute(MockObject $asset, bool $isNew, ?Site $cpSite = null): void
    {
        $this->routeMethod->invoke($this->plugin, $asset, $isNew, $cpSite);
    }

    /**
     * ROUT-04: Re-saves (isNew=false) must not reroute the asset.
     */
    public function testExistingAssetNotRerouted(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->newLocation = null;

        $this->invokeRoute($asset, false);

        $this->assertNull($asset->newLocation, 'Re-save should not set newLocation');
    }

    /**
     * Console requests (queue jobs, CLI) must skip routing entirely.
     */
    public function testConsoleRequestSkipsRouting(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->newLocation = null;

        $this->mockRequest->method('getIsConsoleRequest')->willReturn(true);
        $this->mockRequest->expects($this->never())->method('getBodyParam');

        $this->invokeRoute($asset, true);

        $this->assertNull($asset->newLocation);
    }

    /**
     * ROUT-02: Site resolved from siteId body param via getSiteById().
     */
    public function testSiteResolutionFromBodyParam(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->folderId = 10;
        $asset->method('getFilename')->willReturn('test.jpg');

        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->with('siteId')->willReturn('5');

        $site = $this->createMock(Site::class);
        $site->handle = 'siteA';

        $this->mockSites->expects($this->once())
            ->method('getSiteById')
            ->with(5)
            ->willReturn($site);

        $volume = $this->createMock(Volume::class);
        $volume->handle = 'hero';
        $folder = $this->createMock(VolumeFolder::class);
        $folder->method('getVolume')->willReturn($volume);
        $this->mockAssets->method('getFolderById')->willReturn($folder);

        $targetFolder = $this->createMock(VolumeFolder::class);
        $targetFolder->id = 42;
        $this->mockAssets->method('ensureFolderByFullPathAndVolume')
            ->with('siteA/hero', $volume, false)
            ->willReturn($targetFolder);

        $this->invokeRoute($asset, true);
    }

    /**
     * ROUT-02 fallback: When siteId body param is absent, use getCurrentSite().
     */
    public function testSiteResolutionFallback(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->folderId = 10;
        $asset->method('getFilename')->willReturn('banner.png');

        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->with('siteId')->willReturn(null);

        $site = $this->createMock(Site::class);
        $site->handle = 'siteB';

        $this->mockSites->expects($this->once())
            ->method('getCurrentSite')
            ->willReturn($site);

        $volume = $this->createMock(Volume::class);
        $volume->handle = 'hero';
        $folder = $this->createMock(VolumeFolder::class);
        $folder->method('getVolume')->willReturn($volume);
        $this->mockAssets->method('getFolderById')->willReturn($folder);

        $targetFolder = $this->createMock(VolumeFolder::class);
        $targetFolder->id = 99;
        $this->mockAssets->method('ensureFolderByFullPathAndVolume')
            ->willReturn($targetFolder);

        $this->invokeRoute($asset, true);

        $this->assertEquals('{folder:99}banner.png', $asset->newLocation);
    }

    /**
     * CONF-01: Volumes in excludedVolumes config are skipped.
     */
    public function testExcludedVolumeSkipsRouting(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->folderId = 10;
        $asset->newLocation = null;

        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->with('siteId')->willReturn('1');

        $site = $this->createMock(Site::class);
        $site->handle = 'siteA';
        $this->mockSites->method('getSiteById')->willReturn($site);

        $volume = $this->createMock(Volume::class);
        $volume->handle = 'hero';
        $folder = $this->createMock(VolumeFolder::class);
        $folder->method('getVolume')->willReturn($volume);
        $this->mockAssets->method('getFolderById')->willReturn($folder);

        $this->settings->excludedVolumes = ['hero'];

        $this->mockAssets->expects($this->never())->method('ensureFolderByFullPathAndVolume');

        $this->invokeRoute($asset, true);

        $this->assertNull($asset->newLocation);
    }

    /**
     * ROUT-01/ROUT-03: For a new upload, newLocation is set to route into the site subfolder.
     */
    public function testNewLocationSetForNewUpload(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->folderId = 10;
        $asset->method('getFilename')->willReturn('hero-shot.jpg');

        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->with('siteId')->willReturn('1');

        $site = $this->createMock(Site::class);
        $site->handle = 'siteA';
        $this->mockSites->method('getSiteById')->willReturn($site);

        $volume = $this->createMock(Volume::class);
        $volume->handle = 'hero';
        $folder = $this->createMock(VolumeFolder::class);
        $folder->method('getVolume')->willReturn($volume);
        $this->mockAssets->method('getFolderById')->willReturn($folder);

        $targetFolder = $this->createMock(VolumeFolder::class);
        $targetFolder->id = 42;
        $this->mockAssets->method('ensureFolderByFullPathAndVolume')
            ->with('siteA/hero', $volume, false)
            ->willReturn($targetFolder);

        $this->invokeRoute($asset, true);

        $this->assertEquals('{folder:42}hero-shot.jpg', $asset->newLocation, 'newLocation should route to site/volume subfolder');
    }

    /**
     * AUTO-01/AUTO-02: ensureFolderByFullPathAndVolume called with site handle and justRecord=false.
     */
    public function testEnsureFolderCalledWithSiteHandle(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->folderId = 10;
        $asset->method('getFilename')->willReturn('test.jpg');

        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->with('siteId')->willReturn('2');

        $site = $this->createMock(Site::class);
        $site->handle = 'siteB';
        $this->mockSites->method('getSiteById')->willReturn($site);

        $volume = $this->createMock(Volume::class);
        $volume->handle = 'hero';
        $folder = $this->createMock(VolumeFolder::class);
        $folder->method('getVolume')->willReturn($volume);
        $this->mockAssets->method('getFolderById')->willReturn($folder);

        $targetFolder = $this->createMock(VolumeFolder::class);
        $targetFolder->id = 55;

        $this->mockAssets->expects($this->once())
            ->method('ensureFolderByFullPathAndVolume')
            ->with('siteB/hero', $volume, false)
            ->willReturn($targetFolder);

        $this->invokeRoute($asset, true);
    }

    /**
     * When asset has null folderId, null newFolderId, AND no folderId body param, routing returns early.
     */
    public function testNullFolderIdSkipsRouting(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->folderId = null;
        $asset->newFolderId = null;
        $asset->newLocation = null;

        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->willReturnCallback(
            fn(string $name) => null
        );

        $site = $this->createMock(Site::class);
        $site->handle = 'siteA';
        $this->mockSites->method('getCurrentSite')->willReturn($site);

        $this->mockAssets->expects($this->never())->method('getFolderById');

        $this->invokeRoute($asset, true);

        $this->assertNull($asset->newLocation);
    }

    /**
     * ROUT-02: cpSite (from Cp::requestedSite()) takes priority over body param and getCurrentSite().
     */
    public function testCpSiteTakesPriority(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->folderId = 10;
        $asset->method('getFilename')->willReturn('photo.jpg');

        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);

        $volume = $this->createMock(Volume::class);
        $volume->handle = 'split';
        $folder = $this->createMock(VolumeFolder::class);
        $folder->method('getVolume')->willReturn($volume);
        $this->mockAssets->method('getFolderById')->willReturn($folder);

        $cpSite = $this->createMock(Site::class);
        $cpSite->handle = 'siteB';

        $targetFolder = $this->createMock(VolumeFolder::class);
        $targetFolder->id = 77;
        $this->mockAssets->method('ensureFolderByFullPathAndVolume')
            ->with('siteB/split', $volume, false)
            ->willReturn($targetFolder);

        // Should NOT call getSiteById or getCurrentSite when cpSite is provided
        $this->mockSites->expects($this->never())->method('getSiteById');
        $this->mockSites->expects($this->never())->method('getCurrentSite');

        $this->invokeRoute($asset, true, $cpSite);

        $this->assertEquals('{folder:77}photo.jpg', $asset->newLocation);
    }

    /**
     * When the upload already targets a site subfolder (via FilterService
     * rewriting the asset browser source), routing must not override the location.
     */
    public function testUploadToSiteSubfolderSkipsRerouting(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->folderId = 50;
        $asset->newLocation = null;

        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);

        $volume = $this->createMock(Volume::class);
        $volume->handle = 'split';
        $folder = $this->createMock(VolumeFolder::class);
        $folder->path = 'siteB/';
        $folder->method('getVolume')->willReturn($volume);
        $this->mockAssets->method('getFolderById')->with(50)->willReturn($folder);

        $defaultSite = $this->createMock(Site::class);
        $defaultSite->handle = 'default';
        $hfSite = $this->createMock(Site::class);
        $hfSite->handle = 'siteA';
        $hfsSite = $this->createMock(Site::class);
        $hfsSite->handle = 'siteB';
        $this->mockSites->method('getAllSites')->willReturn([$defaultSite, $hfSite, $hfsSite]);

        $this->mockAssets->expects($this->never())->method('ensureFolderByFullPathAndVolume');

        $this->invokeRoute($asset, true);

        $this->assertNull($asset->newLocation, 'Upload already in site subfolder should not be re-routed');
    }

    /**
     * When folderId, newFolderId, and body folderId are all null (e.g. asset field
     * uploads), resolve folder ID by parsing Asset::newLocation set by beforeSave().
     */
    public function testFolderIdResolvedFromNewLocation(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->folderId = null;
        $asset->newFolderId = null;
        $asset->newLocation = '{folder:25}photo.jpg';
        $asset->method('getFilename')->willReturn('photo.jpg');

        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->willReturnCallback(
            fn(string $name) => null
        );

        $volume = $this->createMock(Volume::class);
        $volume->handle = 'cta';
        $folder = $this->createMock(VolumeFolder::class);
        $folder->method('getVolume')->willReturn($volume);
        $this->mockAssets->method('getFolderById')->with(25)->willReturn($folder);

        $cpSite = $this->createMock(Site::class);
        $cpSite->handle = 'siteB';

        $targetFolder = $this->createMock(VolumeFolder::class);
        $targetFolder->id = 88;
        $this->mockAssets->method('ensureFolderByFullPathAndVolume')
            ->with('siteB/cta', $volume, false)
            ->willReturn($targetFolder);

        $this->invokeRoute($asset, true, $cpSite);

        $this->assertEquals('{folder:88}photo.jpg', $asset->newLocation, 'Should route via newLocation parsing fallback');
    }

    /**
     * When folderId and newFolderId are null (new upload), resolve volume from request body folderId param.
     */
    public function testFolderIdResolvedFromBodyParam(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->folderId = null;
        $asset->newFolderId = null;
        $asset->method('getFilename')->willReturn('test.jpg');

        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->willReturnCallback(
            fn(string $name) => match ($name) {
                'siteId' => '1',
                'folderId' => '10',
                default => null,
            }
        );

        $site = $this->createMock(Site::class);
        $site->handle = 'siteA';
        $this->mockSites->method('getSiteById')->willReturn($site);

        $volume = $this->createMock(Volume::class);
        $volume->handle = 'hero';
        $folder = $this->createMock(VolumeFolder::class);
        $folder->method('getVolume')->willReturn($volume);
        $this->mockAssets->method('getFolderById')->with(10)->willReturn($folder);

        $targetFolder = $this->createMock(VolumeFolder::class);
        $targetFolder->id = 42;
        $this->mockAssets->method('ensureFolderByFullPathAndVolume')
            ->with('siteA/hero', $volume, false)
            ->willReturn($targetFolder);

        $this->invokeRoute($asset, true);

        $this->assertEquals('{folder:42}test.jpg', $asset->newLocation, 'Should route via body param folderId fallback');
    }
}
