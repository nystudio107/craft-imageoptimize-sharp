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

use craft\errors\AssetLogicException;
use nystudio107\imageoptimize\ImageOptimize;
use nystudio107\imageoptimize\imagetransforms\ImageTransform;

use Craft;
use craft\elements\Asset;
use craft\models\AssetTransform;
use craft\helpers\Json;

use craft\awss3\Volume as AwsVolume;

use yii\base\InvalidConfigException;

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

    const TRANSFORM_FORMAT_ATTRIBUTES_MAP = [
        'quality' => 'quality',
        'interlace' => 'progressive',
    ];

    const TRANSFORM_RESIZE_ATTRIBUTES_MAP = [
        'width'   => 'width',
        'height'  => 'height',
    ];

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('image-optimize', 'Sharp');
    }

    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public $baseUrl;

    // Public Methods
    // =========================================================================

    /**
     * @param Asset               $asset
     * @param AssetTransform|null $transform
     *
     * @return string|null
     * @throws \yii\base\InvalidConfigException
     */
    public function getTransformUrl(Asset $asset, $transform)
    {
        $url = null;
        $config = [];
        $settings = ImageOptimize::$plugin->getSettings();

        // Get the instance settings
        $baseUrl = $this->baseUrl ?? '';
        if (ImageOptimize::$craft31) {
            $baseUrl = Craft::parseEnv($baseUrl);
        }
        // Get the bucket name if it exists
        $assetVolume = $asset->getVolume();
        if ($assetVolume instanceof AwsVolume) {
            $config['bucket'] = $assetVolume->bucket;
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
            $format = $transform->format;
            $format = self::TRANSFORM_FORMATS[$format] ?? $format;
            // Map the transform format properties
            foreach (self::TRANSFORM_FORMAT_ATTRIBUTES_MAP as $key => $value) {
                if (!empty($transform[$key])) {
                    $edits[$format][$value] = $transform[$key];
                }
            }
            // Map the transform edits properties
            foreach (self::TRANSFORM_RESIZE_ATTRIBUTES_MAP as $key => $value) {
                if (!empty($transform[$key])) {
                    $edits['resize'][$value] = $transform[$key];
                }
            }
            // Handle auto-sharpening
            if ($settings->autoSharpenScaledImages) {
                // See if the image has been scaled >= 50%
                $widthScale = $asset->getWidth() / ($transform->width ?? $asset->getWidth());
                $heightScale = $asset->getHeight() / ($transform->height ?? $asset->getHeight());
                if (($widthScale >= 2.0) || ($heightScale >= 2.0)) {
                    $edits['sharpen'] = [
                        'flat' => 1.0,
                        'jagged' => 2.0,
                    ];
                }
            }
        }
        // If there are no edits, remove the key
        if (!empty($edits)) {
            $config['edits'] = $edits;
        }
        // Encode the $config and create the $url
        $strConfig = Json::encode($config);
        $url = rtrim($baseUrl, '/').'/'.$strConfig;
        Craft::debug(
            'Sharp transform created for: '.$assetUri.' - Config: '.print_r($config, true).' - URL: '.$url,
            __METHOD__
        );

        return $url;
    }

    /**
     * @param string              $url
     * @param Asset               $asset
     * @param AssetTransform|null $transform
     *
     * @return string
     */
    public function getWebPUrl(string $url, Asset $asset, $transform): string
    {
        if ($transform === null) {
            $transform = new AssetTransform();
        }
        $transform->format='webp';
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
     * @throws \yii\base\InvalidConfigException
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
            'awsS3Installed'    => \class_exists(AwsVolume::class),
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
