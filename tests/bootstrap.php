<?php

/**
 * Test bootstrap for Site Asset Router plugin.
 *
 * Loads the Composer autoloader plus the global-namespace Craft and Yii classes
 * that are not covered by PSR-4 autoloading.
 */

$vendorDir = dirname(__DIR__, 3) . '/vendor';

require $vendorDir . '/autoload.php';
require $vendorDir . '/yiisoft/yii2/Yii.php';
require $vendorDir . '/craftcms/cms/src/Craft.php';
