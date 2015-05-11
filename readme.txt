=== Intermediate Image Sizes ===
Contributors: veloek
Tags: image, thumbnail, media, library
Requires at least: 3.0
Tested up to: 4.2.1
Stable tag: 0.3.8
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Create thumbnails on the fly instead of storing them on disk

== Description ==

This plugin makes Wordpress create thumbnails on the fly rather than storing multiple copies of the same image on upload.

You should use this plugin if you prefer to save disk space rather than CPU. You can save A LOT of space with this plugin. You also get a cleaner uploads folder.

Reads size from URL. You need only add -[width]x[height] to the image path (like regular WordPress thumbnails). Ex: image-100x100.jpg. Size 0 means auto. With both sizes set, it crops to the center.

WARNING: This plugin deletes all current thumbnails and stops Wordpress from generating new thumbnails to keep the uploads folder clean of unnecessary files. If you ever choose to disable this plugin, use a plugin like [Regenerate Thumbnails](https://wordpress.org/plugins/regenerate-thumbnails/) to, well, regenerate the thumbnails.

== Changelog ==

= 0.3.8 =
* Add temporary workaround for animated GIFs by redirecting to original file without cropping

= 0.3.7 =
* Add support for gif images. Still no animated gif support.

= 0.3.6 =
* Fix bug with case sensitivity in apache rewrite

= 0.3.5 =
* Bugfix in image_downsize filter

= 0.3.4 =
* Add a workaround to missing image sizes

= 0.3.3 =
* Safer removal of thumbnail on activation
* Rename class name for consistency

= 0.3.2 =
* Readme changes

= 0.3.1 =
* Initial release

== Upgrade Notice ==

= 0.3.3 =
IMPORTANT! Deactivate old version before upgrading to 0.3.3 and then activate new version
