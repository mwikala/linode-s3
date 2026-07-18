# Changelog

## 2.1.0 - 2026-07-18
- Added Craft CMS 5 compatibility updates while retaining Craft CMS 4 support.
- Updated dependency constraints to stable Craft CMS 4/5 and Craft Flysystem releases.
- Updated the filesystem implementation to use strict types, typed properties, and the current AWS SDK Guzzle handler when available.
- Added a Linode-aware S3 adapter that omits unsupported object ACL operations while preserving multipart uploads and MIME type detection.
- Added the missing Content-Disposition setting field to the filesystem settings UI.
- Improved Linode-specific setup guidance and endpoint examples in the control panel and README.
- Fixed the Craft 3 volume-to-filesystem migration schema-version lookup to use the plugin handle.

## 2.0.1 - 2022-08-10
- Fixed bug where a call was made to Fs::getSubFolder (Method was removed in 2.0.0)

## 2.0.0 - 2022-07-25
- Added support for Craft 4.

## 1.0.3 - 2021-01-15
- Fixed a bug where you received a 500 when trying to download assets from the Bucket

## 1.0.2 - 2020-12-03
- Minor changes to repository. Fixed typos and removed version from `composer.json`

## 1.0.1 - 2020-08-29
- Fixed a bug that prevented `craft project-config/apply` because of a rouge comma at the end of an array in `Volume.php`

## 1.0.0 - 2020-07-01
- Initial Release
