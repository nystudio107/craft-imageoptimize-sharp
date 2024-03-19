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
use craft\awss3\Fs as AwsFs;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\Image;
use craft\helpers\Json;
use craft\models\ImageTransform as CraftImageTransformModel;
use nystudio107\imageoptimize\ImageOptimize;
use nystudio107\imageoptimize\imagetransforms\ImageTransform;
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

    protected const TRANSFORM_FORMATS = [
        'jpg' => 'jpeg',
    ];

    protected const TRANSFORM_MODES = [
        'fit' => 'inside',
        'crop' => 'cover',
        'stretch' => 'fill',
    ];

    protected const TRANSFORM_RESIZE_ATTRIBUTES_MAP = [
        'width' => 'width',
        'height' => 'height',
        'mode' => 'fit',
    ];

    // Static Methods
    // =========================================================================
    /**
     * @var string
     */
    public string $baseUrl = '';

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
     * @inheritdoc
     */
    public function getTransformUrl(Asset $asset, CraftImageTransformModel|string|array|null $transform): ?string
    {
        $config = [];
        $settings = ImageOptimize::$plugin->getSettings();
        // Get the instance settings
        $baseUrl = $this->baseUrl ?? '';
        $baseUrl = App::parseEnv($baseUrl);
        // Get the bucket name if it exists
        try {
            $assetVolumeFs = $asset->getVolume()->getFs();
        } catch (InvalidConfigException $e) {
            Craft::error($e->getMessage(), __METHOD__);
            $assetVolumeFs = null;
        }
        if ($assetVolumeFs instanceof AwsFs) {
            $bucket = $assetVolumeFs->bucket;
            $bucket = App::parseEnv($bucket);
            $config['bucket'] = $bucket;
        }
        // Set the key
        $assetUri = $this->getAssetUri($asset);
        $config['key'] = ltrim($assetUri, '/');
        // Apply any settings from the transform
        $edits = [];
        if ($transform !== null) {
            // Figure out the format of the transform
            if (empty($transform->format)) {
                if (in_array(mb_strtolower($asset->getExtension()), Image::webSafeFormats(), true)) {
                    $transform->format = $asset->getExtension();
                } else {
                    $transform->format = 'jpeg';
                }
            }
            $format = strtolower($transform->format);
            $format = self::TRANSFORM_FORMATS[$format] ?? $format;
            // param: quality
            $edits[$format]['quality'] = ($transform->quality ?? 100);
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
                $position = $xPos . '-' . $yPos;
            }
            if (!empty($position) && preg_match('/(left|center|right)-(top|center|bottom)/', $position)) {
                $positions = explode('-', $position);
                $positions = array_diff($positions, ['center']);
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
                if (($widthScale >= $settings->sharpenScaledImagePercentage) || ($heightScale >= $settings->sharpenScaledImagePercentage)) {
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
     * @inheritdoc
     */
    public function getWebPUrl(string $url, Asset $asset, CraftImageTransformModel|string|array|null $transform): ?string
    {
        if ($transform === null) {
            $transform = new CraftImageTransformModel();
        }
        $transform->format = 'webp';
        $webpUrl = $this->getTransformUrl($asset, $transform);

        return $webpUrl ?? $url;
    }

    /**
     * @inheritdoc
     */
    public function getPurgeUrl(Asset $asset): ?string
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function purgeUrl(string $url): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function getAssetUri(Asset $asset): ?string
    {
        return parent::getAssetUri($asset);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('sharp-image-transform/settings/image-transforms/sharp.twig', [
            'imageTransform' => $this,
            'awsS3Installed' => class_exists(AwsFs::class),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        $rules = parent::rules();

        return array_merge($rules, [
            [['baseUrl'], 'default', 'value' => ''],
            [['baseUrl'], 'string'],
        ]);
    }
}
