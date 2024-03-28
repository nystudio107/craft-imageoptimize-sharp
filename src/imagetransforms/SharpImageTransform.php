<?php
/**
 * ImageOptimize plugin for Craft CMS 3.x
 *
 * Automatically optimize images after they've been transformed
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\imageoptimizesharp\imagetransforms;

use Craft;
use craft\awss3\Volume as AwsVolume;
use craft\elements\Asset;
use craft\errors\AssetLogicException;
use craft\helpers\Json;
use craft\models\AssetTransform;
use nystudio107\imageoptimize\ImageOptimize;
use nystudio107\imageoptimize\imagetransforms\ImageTransform;
use nystudio107\imageoptimize\models\Settings;
use yii\base\InvalidConfigException;
use function class_exists;

/**
 * @author    nystudio107
 * @package   ImageOptimize
 * @since     1.1.0
 */
class SharpImageTransform extends ImageTransform
{
    // Constants
    // =========================================================================

    const TRANSFORM_FORMATS = [
        'jpg' => 'jpeg',
    ];

    const TRANSFORM_MODES = [
        'fit' => 'inside',
        'crop' => 'cover',
        'stretch' => 'fill',
    ];

    const TRANSFORM_RESIZE_ATTRIBUTES_MAP = [
        'width' => 'width',
        'height' => 'height',
        'mode' => 'fit',
    ];

    // Static Methods
    // =========================================================================
    /**
     * @var string
     */
    public $baseUrl = '';

    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('image-optimize', 'Sharp');
    }

    // Public Methods
    // =========================================================================

    /**
     * @param Asset $asset
     * @param AssetTransform|null $transform
     *
     * @return string|null
     * @throws InvalidConfigException
     */
    public function getTransformUrl(Asset $asset, $transform)
    {
        $config = [];
        /** @var Settings $settings */
        $settings = ImageOptimize::$plugin->getSettings();

        // Get the instance settings
        $baseUrl = $this->baseUrl;
        if (ImageOptimize::$craft31) {
            $baseUrl = Craft::parseEnv($baseUrl);
        }
        // Get the bucket name if it exists
        $assetVolume = $asset->getVolume();
        if ($assetVolume instanceof AwsVolume) {
            $bucket = $assetVolume->bucket;
            if (ImageOptimize::$craft31) {
                $bucket = Craft::parseEnv($bucket);
            }
            $config['bucket'] = $bucket;
        }
        // Set the key
        $assetUri = $this->getAssetUri($asset);
        $config['key'] = ltrim($assetUri, '/');

        $assetTransformss = Craft::$app->getAssetTransforms();
        // Apply any settings from the transform
        $edits = [];
        if ($transform !== null) {
            // Figure out the format of the transform
            if (empty($transform->format)) {
                try {
                    $transform->format = $assetTransformss->detectAutoTransformFormat($asset);
                } catch (AssetLogicException $e) {
                    $transform->format = 'jpeg';
                }
            }
            $format = strtolower($transform->format);
            $format = self::TRANSFORM_FORMATS[$format] ?? $format;
            // param: quality
            $edits[$format]['quality'] = (int)($transform->quality ?? 100);
            // If the quality is empty, don't pass the param down to Serverless Sharp
            if (empty($edits[$format]['quality'])) {
                unset($edits[$format]['quality']);
            }
            // Format-specific settings
            switch ($format) {
                case 'jpeg':
                    // param: progressive
                    if (!empty($transform->interlace)) {
                        $edits[$format]['progressive'] = $transform->interlace !== 'none';
                    }
                    $edits[$format]['trellisQuantisation'] = true;
                    $edits[$format]['overshootDeringing'] = true;
                    $edits[$format]['optimizeScans'] = true;
                    break;
                case 'png':
                    // param: progressive
                    if (!empty($transform->interlace)) {
                        $edits[$format]['progressive'] = $transform->interlace !== 'none';
                    }
                    break;
                case 'webp':
                    break;
            }
            // Map the transform resize properties
            foreach (self::TRANSFORM_RESIZE_ATTRIBUTES_MAP as $key => $value) {
                if (!empty($transform[$key])) {
                    $edits['resize'][$value] = $transform[$key];
                }
            }
            // Handle the focal point
            $position = $transform->position;
            $focalPoint = $asset->getHasFocalPoint() ? $asset->getFocalPoint() : false;
            if (!empty($focalPoint)) {
                if ($focalPoint['x'] < 0.33) {
                    $xPos = 'left';
                } elseif ($focalPoint['x'] < 0.66) {
                    $xPos = 'center';
                } else {
                    $xPos = 'right';
                }
                if ($focalPoint['y'] < 0.33) {
                    $yPos = 'top';
                } elseif ($focalPoint['y'] < 0.66) {
                    $yPos = 'center';
                } else {
                    $yPos = 'bottom';
                }
                $position = $yPos . '-' . $xPos;
            }
            if (preg_match('/(top|center|bottom)-(left|center|right)/', $position)) {
                $positions = explode('-', $position);
                $positions = array_diff($positions, ['center']);
                // Reverse the coordinates because Sharp requires them in the "X Y" format
                $positions = array_reverse($positions);
                if (!empty($positions) && $position !== 'center-center') {
                    $edits['resize']['position'] = implode(' ', $positions);
                }
            }
            // Map the mode param
            $mode = $edits['resize']['fit'];
            $edits['resize']['fit'] = self::TRANSFORM_MODES[$mode] ?? $mode ?? 'cover';
            // Handle auto-sharpening
            if ($settings->autoSharpenScaledImages && $asset->getWidth() && $asset->getHeight()) {
                // See if the image has been scaled >= 50%
                $widthScale = (int)((($transform->width ?? $asset->getWidth()) / $asset->getWidth()) * 100);
                $heightScale = (int)((($transform->height ?? $asset->getHeight()) / $asset->getHeight()) * 100);
                if (($widthScale >= (int)$settings->sharpenScaledImagePercentage) || ($heightScale >= (int)$settings->sharpenScaledImagePercentage)) {
                    $edits['sharpen'] = true;
                }
            }
        }
        // If there are no edits, remove the key
        if (!empty($edits)) {
            $config['edits'] = $edits;
        }
        // Encode the $config and create the $url
        $strConfig = Json::encode(
            $config,
            JSON_FORCE_OBJECT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_NUMERIC_CHECK
        );
        $url = rtrim($baseUrl, '/') . '/' . base64_encode($strConfig);
        Craft::debug(
            'Sharp transform created for: ' . $assetUri . ' - Config: ' . print_r($strConfig, true) . ' - URL: ' . $url,
            __METHOD__
        );

        return $url;
    }

    /**
     * @param string $url
     * @param Asset $asset
     * @param AssetTransform|null $transform
     *
     * @return string
     */
    public function getWebPUrl(string $url, Asset $asset, $transform): string
    {
        if ($transform === null) {
            $transform = new AssetTransform();
        }
        $transform->format = 'webp';
        try {
            $webpUrl = $this->getTransformUrl($asset, $transform);
        } catch (InvalidConfigException $e) {
            $webpUrl = null;
        }

        return $webpUrl ?? $url;
    }

    /**
     * @param Asset $asset
     *
     * @return null|string
     */
    public function getPurgeUrl(Asset $asset)
    {
        return null;
    }

    /**
     * @param string $url
     *
     * @return bool
     */
    public function purgeUrl(string $url): bool
    {
        return false;
    }

    /**
     * @param Asset $asset
     *
     * @return mixed
     * @throws InvalidConfigException
     */
    public function getAssetUri(Asset $asset)
    {
        return parent::getAssetUri($asset);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('sharp-image-transform/settings/image-transforms/sharp.twig', [
            'imageTransform' => $this,
            'awsS3Installed' => class_exists(AwsVolume::class),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        $rules = parent::rules();
        $rules = array_merge($rules, [
            [['baseUrl'], 'default', 'value' => ''],
            [['baseUrl'], 'string'],
        ]);

        return $rules;
    }
}
