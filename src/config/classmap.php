<?php

/**
 * Module Class map
 *
 * Returns an associative array of class names to their corresponding
 * file paths. It is used by `Autoloader` to resolve class files manually,
 * typically for libraries, abstract classes, or legacy code not using PSR-4/composer.
 *
 * @package     CiSkeleton\Plugins
 * @subpackage  OEmbed\Config
 * @category    Autoloading
 * @author      Kader Bouyakoub <bkader[at]mail[dot]com>
 * @copyright   2018-present, Kader Bouyakoub
 * @license     MIT
 * @link        https://opensource.org/license/mit
 *
 * SPDX-License-Identifier: MIT
 */

return [
	'CI_Oembed_service' => __DIR__.'/../services/Oembed_service.php',
];
