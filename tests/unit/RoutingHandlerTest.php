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

    /** @var array<int,VolumeFolder> id => folder, returned by getFolderById() */
    private array $folders = [];

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

        // getFolderById() resolves from the per-test $this->folders map.
        $this->mockAssets->method('getFolderById')
            ->willReturnCallback(fn(int $id) => $this->folders[$id] ?? null);

        // Default site roster for _siteFromFolderPath() handle matching.
        $this->mockSites->method('getAllSites')->willReturn([
            $this->site('default'),
            $this->site('siteA'),
            $this->site('siteB'),
            $this->site('tenor'),
        ]);

        $this->settings = new Settings();

        // _relationSiteIds is the DB-touching seam for the stranded-root guard;
        // mock it so tests can drive site resolution without a database.
        $this->plugin = $this->createPartialMock(Plugin::class, ['getSettings', '_relationSiteIds']);
        $this->plugin->method('getSettings')->willReturn($this->settings);

        $this->routeMethod = new \ReflectionMethod(Plugin::class, '_routeAssetUpload');
        $this->routeMethod->setAccessible(true);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function invokeRoute(MockObject $asset, bool $isNew, ?Site $cpSite = null): void
    {
        $this->routeMethod->invoke($this->plugin, $asset, $isNew, $cpSite);
    }

    private function site(string $handle, ?int $id = null): Site&MockObject
    {
        $s = $this->createMock(Site::class);
        $s->id = $id;
        $s->handle = $handle;
        return $s;
    }

    /**
     * Stub Sites::getSiteById() from an id => Site map, for relation-driven
     * site resolution in the stranded-root guard.
     *
     * @param array<int,Site> $byId
     */
    private function sitesById(array $byId): void
    {
        $this->mockSites->method('getSiteById')
            ->willReturnCallback(fn(int $id) => $byId[$id] ?? null);
    }

    private function volume(string $handle): Volume&MockObject
    {
        $v = $this->createMock(Volume::class);
        $v->handle = $handle;
        return $v;
    }

    private function folder(int $id, string $path, Volume $volume): VolumeFolder&MockObject
    {
        $f = $this->createMock(VolumeFolder::class);
        $f->id = $id;
        $f->path = $path;
        $f->method('getVolume')->willReturn($volume);
        $this->folders[$id] = $f;
        return $f;
    }

    private function asset(?int $folderId, ?string $newLocation, ?int $newFolderId = null, ?string $tempFilePath = null): Asset&MockObject
    {
        $a = $this->createMock(Asset::class);
        $a->id = 8956;
        $a->folderId = $folderId;
        $a->newLocation = $newLocation;
        $a->newFolderId = $newFolderId;
        $a->tempFilePath = $tempFilePath;
        $a->method('getFilename')->willReturn('wine.jpg');
        return $a;
    }

    // ── new-upload routing (original feature, new control flow) ───────────────

    /**
     * A new upload (target = volume root via newLocation, no site-prefixed source)
     * is routed to the CP-requested site's subfolder.
     */
    public function testNewUploadRoutedToCpSite(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $vol = $this->volume('bottleShots');
        $this->folder(1, '', $vol);                       // target = volume root
        $asset = $this->asset(null, '{folder:1}wine.jpg'); // no source folder

        $dest = $this->folder(42, 'siteB/bottleShots/', $vol);
        $this->mockAssets->expects($this->once())
            ->method('ensureFolderByFullPathAndVolume')
            ->with('siteB/bottleShots', $vol, false)
            ->willReturn($dest);

        $this->invokeRoute($asset, true, $this->site('siteB'));

        $this->assertEquals('{folder:42}wine.jpg', $asset->newLocation);
    }

    /**
     * cpSite is preferred over getCurrentSite() when there's no source-site to preserve.
     */
    public function testCpSiteTakesPriorityOverCurrentSite(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $vol = $this->volume('split');
        $this->folder(1, '', $vol);
        $asset = $this->asset(null, '{folder:1}wine.jpg');

        $this->mockSites->expects($this->never())->method('getCurrentSite');
        $this->mockAssets->method('ensureFolderByFullPathAndVolume')
            ->with('siteB/split', $vol, false)
            ->willReturn($this->folder(77, 'siteB/split/', $vol));

        $this->invokeRoute($asset, true, $this->site('siteB'));

        $this->assertEquals('{folder:77}wine.jpg', $asset->newLocation);
    }

    /**
     * Web last resort: no source-site and no cpSite → getCurrentSite().
     */
    public function testGetCurrentSiteFallbackWebOnly(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $vol = $this->volume('hero');
        $this->folder(1, '', $vol);
        $asset = $this->asset(null, '{folder:1}wine.jpg');

        $this->mockSites->expects($this->once())->method('getCurrentSite')->willReturn($this->site('siteA'));
        $this->mockAssets->method('ensureFolderByFullPathAndVolume')
            ->with('siteA/hero', $vol, false)
            ->willReturn($this->folder(99, 'siteA/hero/', $vol));

        $this->invokeRoute($asset, true, null);

        $this->assertEquals('{folder:99}wine.jpg', $asset->newLocation);
    }

    // ── relocation re-anchoring (the bug this enhancement fixes) ──────────────

    /**
     * Core regression: an existing asset being moved to the volume root (e.g. by
     * AssetsField restrictLocation) is re-anchored back into its source site's
     * subfolder, even though isNew=false.
     */
    public function testRelocationToRootReAnchoredToSourceSite(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $vol = $this->volume('bottleShots');
        $this->folder(10, 'tenor/bottleShots/', $vol);   // SOURCE (site-prefixed)
        $this->folder(1, '', $vol);                        // TARGET (volume root)
        $asset = $this->asset(10, '{folder:1}wine.jpg');

        $dest = $this->folder(200, 'tenor/bottleShots/', $vol);
        $this->mockAssets->expects($this->once())
            ->method('ensureFolderByFullPathAndVolume')
            ->with('tenor/bottleShots', $vol, false)
            ->willReturn($dest);

        $this->invokeRoute($asset, false, null);

        $this->assertEquals('{folder:200}wine.jpg', $asset->newLocation);
    }

    /**
     * The same relocation in console/queue context (e.g. a migration resave)
     * still re-anchors via the source site, and never consults getCurrentSite().
     */
    public function testConsoleRelocationPreservesSourceSite(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(true);
        $vol = $this->volume('bottleShots');
        $this->folder(10, 'tenor/bottleShots/', $vol);
        $this->folder(1, '', $vol);
        $asset = $this->asset(10, '{folder:1}wine.jpg');

        $this->mockSites->expects($this->never())->method('getCurrentSite');
        $this->mockAssets->expects($this->once())
            ->method('ensureFolderByFullPathAndVolume')
            ->with('tenor/bottleShots', $vol, false)
            ->willReturn($this->folder(200, 'tenor/bottleShots/', $vol));

        $this->invokeRoute($asset, false, null);

        $this->assertEquals('{folder:200}wine.jpg', $asset->newLocation);
    }

    /**
     * Console move with no resolvable site (source is the volume root, no cpSite)
     * is a safe no-op — getCurrentSite() is gated to web requests.
     */
    public function testConsoleRootMoveWithNoSourceSiteNoOps(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(true);
        $vol = $this->volume('bottleShots');
        $this->folder(10, '', $vol);          // source = root (no site)
        $this->folder(1, '', $vol);           // target = root
        $asset = $this->asset(10, '{folder:1}wine.jpg');

        $this->mockSites->expects($this->never())->method('getCurrentSite');
        $this->mockAssets->expects($this->never())->method('ensureFolderByFullPathAndVolume');

        $this->invokeRoute($asset, false, null);

        $this->assertEquals('{folder:1}wine.jpg', $asset->newLocation, 'No site → leave the move untouched');
    }

    // ── no-op guards ──────────────────────────────────────────────────────────

    /**
     * A metadata-only re-save (no newLocation/newFolderId, no body folderId) has
     * no move target and must be a no-op — getFolderById is never consulted.
     */
    public function testMetadataResaveNoOp(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->willReturn(null);

        $asset = $this->asset(10, null, null);

        // getFolderById would only be called if a target were found.
        $this->mockAssets->expects($this->never())->method('ensureFolderByFullPathAndVolume');

        $this->invokeRoute($asset, false, null);

        $this->assertNull($asset->newLocation);
    }

    /**
     * When the move target is already under a known site handle, leave it
     * (prevents double-nesting and re-entrancy from our own rewrite).
     */
    public function testTargetAlreadySitePrefixedSkips(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $vol = $this->volume('split');
        $this->folder(50, 'siteB/split/', $vol);          // target already site-prefixed
        $asset = $this->asset(50, '{folder:50}wine.jpg');

        $this->mockAssets->expects($this->never())->method('ensureFolderByFullPathAndVolume');

        $this->invokeRoute($asset, true, $this->site('siteB'));

        $this->assertEquals('{folder:50}wine.jpg', $asset->newLocation, 'Already site-prefixed → unchanged');
    }

    /**
     * If the resolved destination equals the current target, don't rewrite
     * newLocation (avoids a redundant move/rename).
     */
    public function testAlreadyHeadingToDestNoRewrite(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $vol = $this->volume('bottleShots');
        $this->folder(10, 'tenor/bottleShots/', $vol);    // source site = tenor
        $this->folder(1, '', $vol);                        // target = root
        $asset = $this->asset(10, '{folder:1}wine.jpg');

        // ensureFolder resolves to folder id 1 — i.e. equal to the target.
        $this->mockAssets->method('ensureFolderByFullPathAndVolume')
            ->willReturn($this->folders[1]);

        $this->invokeRoute($asset, false, null);

        $this->assertEquals('{folder:1}wine.jpg', $asset->newLocation, 'dest == target → no rewrite');
    }

    /**
     * CONF-01: volumes in excludedVolumes are skipped entirely.
     */
    public function testExcludedVolumeSkipsRouting(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $vol = $this->volume('hero');
        $this->folder(1, '', $vol);
        $asset = $this->asset(null, '{folder:1}wine.jpg');

        $this->settings->excludedVolumes = ['hero'];
        $this->mockAssets->expects($this->never())->method('ensureFolderByFullPathAndVolume');

        $this->invokeRoute($asset, true, $this->site('siteB'));

        $this->assertEquals('{folder:1}wine.jpg', $asset->newLocation, 'Excluded volume → unchanged');
    }

    /**
     * No resolvable move target (null folderId/newFolderId/newLocation, no body
     * param) → routing never reaches folder resolution.
     */
    public function testNullTargetSkipsRouting(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->willReturn(null);

        $asset = $this->asset(null, null, null);

        $this->mockAssets->expects($this->never())->method('ensureFolderByFullPathAndVolume');

        $this->invokeRoute($asset, true, $this->site('siteA'));

        $this->assertNull($asset->newLocation);
    }

    /**
     * Target resolved from the request body folderId param (web upload path)
     * when newLocation/newFolderId are absent.
     */
    public function testTargetFromBodyFolderId(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')
            ->willReturnCallback(fn(string $n) => $n === 'folderId' ? '1' : null);

        $vol = $this->volume('hero');
        $this->folder(1, '', $vol);
        $asset = $this->asset(null, null, null);

        $this->mockAssets->method('ensureFolderByFullPathAndVolume')
            ->with('siteA/hero', $vol, false)
            ->willReturn($this->folder(42, 'siteA/hero/', $vol));

        $this->invokeRoute($asset, true, $this->site('siteA'));

        $this->assertEquals('{folder:42}wine.jpg', $asset->newLocation);
    }

    // ── stranded-root guard (CP "replace" lands a file in the volume root) ────

    /**
     * A replace (tempFilePath set, no move target) of an asset stranded in the
     * volume root is re-homed to its owning site's subfolder, with the site
     * resolved from the asset's relations — not the (absent) request context.
     */
    public function testReplaceInRootReHomedViaRelations(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->willReturn(null);

        $vol = $this->volume('heroHome');
        $this->folder(42, '', $vol);                              // asset sits in the bare root
        $asset = $this->asset(42, null, null, '/tmp/upload.jpg'); // replace: tempFilePath set, no target

        $this->plugin->method('_relationSiteIds')->willReturn([1]);
        $this->sitesById([1 => $this->site('matthews', 1)]);

        $this->mockAssets->expects($this->once())
            ->method('ensureFolderByFullPathAndVolume')
            ->with('matthews/heroHome', $vol, false)
            ->willReturn($this->folder(44, 'matthews/heroHome/', $vol));

        $this->invokeRoute($asset, false, null);

        $this->assertEquals('{folder:44}wine.jpg', $asset->newLocation);
    }

    /**
     * Relations spanning more than one site are ambiguous → leave the asset put
     * rather than risk filing it under the wrong site.
     */
    public function testReplaceInRootAmbiguousRelationsNoOp(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->willReturn(null);

        $vol = $this->volume('heroHome');
        $this->folder(42, '', $vol);
        $asset = $this->asset(42, null, null, '/tmp/upload.jpg');

        $this->plugin->method('_relationSiteIds')->willReturn([1, 4]);
        $this->sitesById([1 => $this->site('matthews', 1), 4 => $this->site('tenor', 4)]);

        $this->mockAssets->expects($this->never())->method('ensureFolderByFullPathAndVolume');

        $this->invokeRoute($asset, false, null);

        $this->assertNull($asset->newLocation, 'Ambiguous owning site → unchanged');
    }

    /**
     * No relations at all → unresolvable site → safe no-op.
     */
    public function testReplaceInRootNoRelationsNoOp(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->willReturn(null);

        $vol = $this->volume('heroHome');
        $this->folder(42, '', $vol);
        $asset = $this->asset(42, null, null, '/tmp/upload.jpg');

        $this->plugin->method('_relationSiteIds')->willReturn([]);

        $this->mockAssets->expects($this->never())->method('ensureFolderByFullPathAndVolume');

        $this->invokeRoute($asset, false, null);

        $this->assertNull($asset->newLocation, 'No relations → unchanged');
    }

    /**
     * A replace of a correctly-located asset (already in a site subfolder) is the
     * common case and must be untouched — the guard only acts on the bare root.
     */
    public function testReplaceInSubfolderNoOp(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->willReturn(null);

        $vol = $this->volume('heroHome');
        $this->folder(44, 'matthews/heroHome/', $vol);            // already correctly located
        $asset = $this->asset(44, null, null, '/tmp/upload.jpg');

        $this->mockAssets->expects($this->never())->method('ensureFolderByFullPathAndVolume');

        $this->invokeRoute($asset, false, null);

        $this->assertNull($asset->newLocation, 'Already in a subfolder → unchanged');
    }

    /**
     * A replace in an excluded volume's root is left alone (CONF-01).
     */
    public function testReplaceInExcludedVolumeRootNoOp(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->willReturn(null);

        $vol = $this->volume('icons');
        $this->folder(42, '', $vol);
        $asset = $this->asset(42, null, null, '/tmp/upload.jpg');

        $this->settings->excludedVolumes = ['icons'];
        $this->mockAssets->expects($this->never())->method('ensureFolderByFullPathAndVolume');

        $this->invokeRoute($asset, false, null);

        $this->assertNull($asset->newLocation, 'Excluded volume → unchanged');
    }

    /**
     * A metadata-only re-save of a root asset (no tempFilePath) must NOT trigger
     * the guard — relations are never consulted and nothing is rewritten.
     */
    public function testMetadataResaveInRootDoesNotConsultRelations(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);
        $this->mockRequest->method('getBodyParam')->willReturn(null);

        $vol = $this->volume('heroHome');
        $this->folder(42, '', $vol);
        $asset = $this->asset(42, null, null, null);   // no tempFilePath → not a file-bearing save

        $this->plugin->expects($this->never())->method('_relationSiteIds');
        $this->mockAssets->expects($this->never())->method('ensureFolderByFullPathAndVolume');

        $this->invokeRoute($asset, false, null);

        $this->assertNull($asset->newLocation);
    }
}
