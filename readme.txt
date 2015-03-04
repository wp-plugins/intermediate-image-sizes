=== Intermediate Image Sizes ===
Contributors: veloek
Tags: image, thumbnail, media, library
Requires at least: 3.0
Tested up to: 4.1.1
Stable tag: 0.3.1
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Create thumbnails on the fly instead of storing them on disk

== Description ==

This plugin makes Wordpress create thumbnails on the fly rather than storing multiple copies of the same image on upload.

You should use this plugin if you prefer to save disk space rather than CPU. You can save A LOT of space with this plugin. You also get a cleaner uploads folder.

Reads size from URL. You need only add -[width]x[height] to the image path (like regular WordPress thumbnails). Ex: image-100x100.jpg. Size 0 means auto. With both sizes set, it crops to the center.

WARNING: The plugin deletes all current thumbnails and stops Wordpress from producing new thumbnails to keep it clean. Remember this before deactivating. You will need a plugin to regenerate thumbnails.

== Installation ==

* Upload
* Activate
* Go test your custom thumbnail sizes

== Changelog ==

= 0.3.1 =
* Initial release
