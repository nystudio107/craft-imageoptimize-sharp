# ImageOptimize Sharp Image Transform Changelog

## 4.0.0-beta.1 - 2022.03.20

### Added

* Initial Craft CMS 4 compatibility

## 1.0.9 - 2022.02.24

### Changed

* Loosen the `composer.json` `require` constraints

## 1.0.8 - 2021.11.12
### Fixed
* Fixed an issue where transforms could fail if the file extension was not lowercase ([#4](https://github.com/nystudio107/craft-imageoptimize-sharp/issues/4))

## 1.0.7 - 2021.04.23
### Added
* Added a setting to control the amount an image needs to be scaled down for automatic sharpening to be applied (https://github.com/nystudio107/craft-imageoptimize/issues/263)

## 1.0.6 - 2021.02.03
### Fixed
* Map the `fit` Craft transform method to `inside` to avoid letterboxing

## 1.0.5 - 2020.06.08
### Fixed
* If the quality is empty, don't pass the param down to Serverless Sharp

## 1.0.4 - 2020.03.12
### Fixed
* Fix the preg_match for the x- and y positions

## 1.0.3 - 2020.03.10
### Changed
* Fixed the mapping of focal points to textual positions

## 1.0.2 - 2019.08.13
### Changed
* Ensure that focal points take precedence over transform positions

## 1.0.1 - 2019.07.06
### Changed
* Fixed an issue where an AWS bucket name could be an unparsed Environment Variable

## 1.0.0 - 2019.07.05
### Added
- Initial release
