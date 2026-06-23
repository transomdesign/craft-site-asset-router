<?php

declare(strict_types=1);

namespace SiteAssetRouter\tests\unit;

use Craft;
use craft\models\Site;
use craft\models\Volume;
use craft\models\VolumeFolder;
use craft\services\Assets as AssetsService;
use craft\services\Volumes as VolumesService;
use craft\web\Application;
use craft\web\Request;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SiteAssetRouter\models\Settings;
use SiteAssetRouter\services\FilterService;

class FilterServiceTest extends TestCase
{
    private FilterService $service;
    private Settings $settings;
    private Request&MockObject $mockRequest;
    private AssetsService&MockObject $mockAssets;
    private VolumesService&MockObject $mockVolumes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRequest = $this->createMock(Request::class);
        $this->mockAssets = $this->createMock(AssetsService::class);
        $this->mockVolumes = $this->createMock(VolumesService::class);

        $mockApp = $this->createMock(Application::class);
        $mockApp->method('getRequest')->willReturn($this->mockRequest);
        $mockApp->method('getAssets')->willReturn($this->mockAssets);
        $mockApp->method('getVolumes')->willReturn($this->mockVolumes);

        Craft::$app = $mockApp;

        $this->settings = new Settings();

        $this->service = new FilterService();
        $this->service->settings = $this->settings;
    }

    /**
     * Build a sources array with volume entries for the given handles.
     */
    private function makeSources(array $volumeHandles = ['hero']): array
    {
        $sources = [];
        foreach ($volumeHandles as $i => $handle) {
            $sources[] = [
                'key' => "volume:uid-$handle",
                'label' => ucfirst($handle),
                'criteria' => ['folderId' => $i + 1],
                'data' => ['volume-handle' => $handle, 'folder-id' => $i + 1],
            ];
        }
        return $sources;
    }

    private function makeSite(string $handle): Site&MockObject
    {
        $site = $this->createMock(Site::class);
        $site->handle = $handle;
        return $site;
    }

    private function makeVolumeAndFolder(string $handle, int $folderId): array
    {
        $volume = $this->createMock(Volume::class);
        $volume->handle = $handle;
        $volume->id = 900 + $folderId; // non-null int — FilterService calls getRootFolderByVolumeId($volume->id)
        $folder = $this->createMock(VolumeFolder::class);
        $folder->id = $folderId;
        return [$volume, $folder];
    }

    /**
     * Settings context must skip filtering entirely.
     */
    public function testSettingsContextSkipsFiltering(): void
    {
        $sources = $this->makeSources();
        $original = $sources;

        $this->service->filterSources($sources, 'settings', $this->makeSite('siteA'));

        $this->assertEquals($original, $sources, 'Settings context should not modify sources');
    }

    /**
     * Console requests must skip filtering (no CP context).
     */
    public function testConsoleRequestSkipsFiltering(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(true);

        $sources = $this->makeSources();
        $original = $sources;

        $this->service->filterSources($sources, 'index', $this->makeSite('siteA'));

        $this->assertEquals($original, $sources, 'Console request should not modify sources');
    }

    /**
     * Null site (no CP site context) must skip filtering.
     */
    public function testNullSiteSkipsFiltering(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);

        $sources = $this->makeSources();
        $original = $sources;

        $this->service->filterSources($sources, 'index', null);

        $this->assertEquals($original, $sources, 'Null site should not modify sources');
    }

    /**
     * Index context rewrites volume source criteria to site subfolder.
     */
    public function testIndexContextIsFiltered(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);

        [$volume, $folder] = $this->makeVolumeAndFolder('hero', 42);
        $this->mockVolumes->method('getVolumeByHandle')->with('hero')->willReturn($volume);
        $this->mockAssets->method('ensureFolderByFullPathAndVolume')
            ->with('siteA/hero', $volume, true)
            ->willReturn($folder);

        $sources = $this->makeSources();
        $this->service->filterSources($sources, 'index', $this->makeSite('siteA'));

        $this->assertEquals(42, $sources[0]['criteria']['folderId']);
    }

    /**
     * Modal context (asset-selection fields) is also filtered.
     */
    public function testModalContextIsFiltered(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);

        [$volume, $folder] = $this->makeVolumeAndFolder('hero', 55);
        $this->mockVolumes->method('getVolumeByHandle')->with('hero')->willReturn($volume);
        $this->mockAssets->method('ensureFolderByFullPathAndVolume')
            ->with('siteB/hero', $volume, true)
            ->willReturn($folder);

        $sources = $this->makeSources();
        $this->service->filterSources($sources, 'modal', $this->makeSite('siteB'));

        $this->assertEquals(55, $sources[0]['criteria']['folderId']);
    }

    /**
     * Both criteria.folderId and data.folder-id must be updated together.
     */
    public function testCriteriaAndDataFolderIdBothUpdated(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);

        [$volume, $folder] = $this->makeVolumeAndFolder('hero', 42);
        $this->mockVolumes->method('getVolumeByHandle')->with('hero')->willReturn($volume);
        $this->mockAssets->method('ensureFolderByFullPathAndVolume')->willReturn($folder);

        $sources = $this->makeSources();
        $this->service->filterSources($sources, 'index', $this->makeSite('siteA'));

        $this->assertEquals(42, $sources[0]['criteria']['folderId'], 'criteria.folderId should be updated');
        $this->assertEquals(42, $sources[0]['data']['folder-id'], 'data.folder-id should be updated');
    }

    /**
     * CONF-01: Volumes in excludedVolumes config pass through unmodified.
     */
    public function testExcludedVolumePassesThrough(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);

        $this->settings->excludedVolumes = ['hero'];
        $this->mockAssets->expects($this->never())->method('ensureFolderByFullPathAndVolume');

        $sources = $this->makeSources();
        $originalFolderId = $sources[0]['criteria']['folderId'];

        $this->service->filterSources($sources, 'index', $this->makeSite('siteA'));

        $this->assertEquals($originalFolderId, $sources[0]['criteria']['folderId'], 'Excluded volume should not be modified');
    }

    /**
     * Sources without a volume-handle in data are skipped.
     */
    public function testMissingVolumeHandleSkipsSource(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);

        $sources = [
            [
                'key' => 'volume:uid-foo',
                'label' => 'Foo',
                'criteria' => ['folderId' => 1],
                'data' => ['folder-id' => 1],
            ],
        ];
        $original = $sources;

        $this->service->filterSources($sources, 'index', $this->makeSite('siteA'));

        $this->assertEquals($original, $sources, 'Source without volume-handle should be skipped');
    }

    /**
     * Non-volume sources (headings, temp) pass through untouched.
     */
    public function testNonVolumeSourcesUntouched(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);

        $sources = [
            ['heading' => 'Assets'],
            ['key' => 'temp:123', 'label' => 'Temp', 'criteria' => [], 'data' => []],
        ];
        $original = $sources;

        $this->service->filterSources($sources, 'index', $this->makeSite('siteA'));

        $this->assertEquals($original, $sources, 'Non-volume sources should not be modified');
    }

    /**
     * Multiple volumes in a single sources array all get filtered.
     */
    public function testMultipleVolumesAllFiltered(): void
    {
        $this->mockRequest->method('getIsConsoleRequest')->willReturn(false);

        $heroVolume = $this->createMock(Volume::class);
        $heroVolume->handle = 'hero';
        $heroVolume->id = 942;
        $heroFolder = $this->createMock(VolumeFolder::class);
        $heroFolder->id = 42;

        $splitVolume = $this->createMock(Volume::class);
        $splitVolume->handle = 'split';
        $splitVolume->id = 943;
        $splitFolder = $this->createMock(VolumeFolder::class);
        $splitFolder->id = 43;

        $this->mockVolumes->method('getVolumeByHandle')->willReturnMap([
            ['hero', $heroVolume],
            ['split', $splitVolume],
        ]);
        $this->mockAssets->method('ensureFolderByFullPathAndVolume')
            ->willReturnCallback(function ($handle, $vol) use ($heroVolume, $heroFolder, $splitFolder) {
                return $vol === $heroVolume ? $heroFolder : $splitFolder;
            });

        $sources = $this->makeSources(['hero', 'split']);
        $this->service->filterSources($sources, 'index', $this->makeSite('siteA'));

        $this->assertEquals(42, $sources[0]['criteria']['folderId']);
        $this->assertEquals(43, $sources[1]['criteria']['folderId']);
    }
}
