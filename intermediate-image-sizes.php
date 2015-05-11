<?php
/*
Plugin Name: Intermediate Image Sizes
Description: Create thumbnails on the fly instead of storing them on disk
Version: 0.3.8
Author: Headspin <vegard@headspin.no>
Author URI: http://www.headspin.no
Licence: GPL2
*/
class IntermediateImageSizes {

	private $htaccessPath = '';

	public function __construct() {
		if ($this->isInsideWordpress()) {
			$this->htaccessPath = ABSPATH . '.htaccess';
			register_activation_hook(__FILE__, array($this, 'activate'));
			register_deactivation_hook(__FILE__, array($this, 'deactivate'));

			// Stop WordPress from storing thumbnails
			add_filter('intermediate_image_sizes_advanced', '__return_empty_array');

			/* Override image_downsize since the previous filter prevents
			 * wp_get_attachment_metadata to know about the thumbnail sizes */
			add_filter('image_downsize', array($this, 'imageDownsizeFilter'), 10, 3);
		}
		else {

			// Handle request if wordpress is not loaded
			$this->handleRequest();
		}
	}

	private function isInsideWordpress() {
		return defined('ABSPATH');
	}

	/**
	 * Function that is run on activation of plugin. If plugin is
	 * reactivated, this function is run again
	 */
	public function activate() {

		// Make sure we don't already have an entry in the htaccess
		$this->deactivate();

		$filePath = __FILE__;

		// Use a relative path
		$parts = explode('wp-content', $filePath);
		$filePath = 'wp-content' . $parts[1];

		$rewriteRules = <<< HTACCESS
# BEGIN Wordpress plugin IntermediateImageSizes
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-s
RewriteCond %{REQUEST_URI} (.+)-([0-9]+)x([0-9]+)\.(jpg|jpeg|png|gif)$ [NC]
RewriteRule (.+)-([0-9]+)x([0-9]+)\.(.+)$ ${filePath}?path=$1&width=$2&height=$3&ext=$4 [L]
</IfModule>
# END Wordpress plugin IntermediateImageSizes

HTACCESS;

		$htaccess = @file_get_contents($this->htaccessPath);
		if ($htaccess !== FALSE) {
			if (strpos($htaccess, '# BEGIN Wordpress plugin IntermediateImageSizes') === FALSE) {
				$htaccess = $rewriteRules . $htaccess;
				file_put_contents($this->htaccessPath, $htaccess);
			}
		}
		else {
			// Create a brand new htaccess file
			file_put_contents($this->htaccessPath, $rewriteRules);
		}

		// Delete all previously generated thumbnails
		$this->deleteThumbnails();
	}

	/**
	 * Function that is run on deactivation of plugin
	 */
	public function deactivate() {
		$htaccess = @file_get_contents($this->htaccessPath);

		if ($htaccess !== FALSE) {
			$newHtaccess = preg_replace('/# BEGIN Wordpress plugin IntermediateImageSizes.*# END Wordpress plugin IntermediateImageSizes\n/s',
				'', $htaccess);
			if ($newHtaccess !== $htaccess) {
				file_put_contents($this->htaccessPath, $newHtaccess);
			}
		}

		// Regenerate thumbnails
		/* DISABLED: This is too slow with a huge media library
		 * Consider using a background thread
		 * ini_set('max_execution_time', 600); // This may take some time
		 * $this->regenerateThumbnails();
		*/
	}

	private function handleRequest() {
		$root = $this->getWordPressRootDir();
		if ($root === NULL) die("Error while getting WordPress root folder");
		else $root .= '/';

		ob_clean();

		// Basic check to avoid misuse
		if (!(isset($_GET['path']) && isset($_GET['ext']) &&
			  isset($_GET['width']) && isset($_GET['height']))) return;

		// Get path and file ext
		$path = $_GET['path'];
		$ext = $_GET['ext'];

		// Get new size from url variables
		$newWidth = intval($_GET['width']);
		$newHeight = intval($_GET['height']);

		$filename = $root . $path . '.' . $ext;
		$fullname = $root . $path . '-';
		$fullname .= $newWidth . 'x' . $newHeight . '.' . $ext;

		// Catch wierd cases where the filename is ex. something-123x123.png
		if (!file_exists($filename) && file_exists($fullname))
			$filename = $fullname;

		$httpType = $ext === 'png' ? 'png' : ($ext === 'gif' ? 'gif' : 'jpeg');
		header('Content-Type: image/' . $httpType);

		// Caching
		header('Cache-Control: private, max-age=10800, pre-check=10800');
		header('Pragma: private');
		header('Expires: ' . date(DATE_RFC822, strtotime('2 day')));

		$lastModifiedHeader = 'Last-Modified: ';
		$lastModifiedHeader .= gmdate('D, d M Y H:i:s', filemtime($filename));
		$lastModifiedHeader .= ' GMT';

		// If browser has cached version, use that if timestamp match
		if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) &&
			strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) ===
				filemtime($filename)) {

			header($lastModifiedHeader , TRUE, 304);
			exit;
		}

		header($lastModifiedHeader);

		// Check that the base image exists
		if (!file_exists($filename)) {
			header('HTTP/1.0 404 Not Found');
			echo "404 Not found " . $filename;
			die();
		}

		/* Check for animated gif.
		 * If animated, redirect to full size image, since there's no support
		 * for cropping these files (yet).
		 * TODO: Split gif into frames and crop them separately before putting
		 *       them back together and returning. Remember frame delay. */
		if ($ext === 'gif' && $this->is_ani($filename)) {
			header('Location: /' . @array_pop(explode($root, $filename)));
			//readfile($filename);
			return;
		}

		// Get original image size
		list($width, $height) = getimagesize($filename);
		$ratio = $width / $height;

		// If either width or height is set, use the original ratio
		// to calculate the other. If both are set, make a crop of
		// the center of the original image
		$clipX = $clipY = 0;
		if (!$newWidth && !$newHeight) {
			$newWidth = $width;
			$newHeight = $height;
		}
		else if ($newWidth && !$newHeight) {
			$newHeight = $newWidth / $ratio;
		}
		else if ($newHeight && !$newWidth) {
			$newWidth = $newHeight * $ratio;
		}
		else {
			$newRatio = $newWidth / $newHeight;

			if ($newRatio > $ratio) {
				$clipHeight = $width / $newRatio;
				$margin = ($height - $clipHeight) / 2;
				$clipY = $margin;
				$height = $clipHeight;
			}
			else {
				$clipWidth = $height * $newRatio;
				$margin = ($width - $clipWidth) / 2;
				$clipX = $margin;
				$width = $clipWidth;
			}
		}

		$image = $ext === 'png' ?
			imagecreatefrompng($filename) :
			($ext === 'gif' ?
				imagecreatefromgif($filename) :
				imagecreatefromjpeg($filename));

		// Enable transparency for PNGs
		if ($ext === 'png') imagealphablending($image, TRUE);

		$newImage = imagecreatetruecolor($newWidth, $newHeight);
		if ($ext === 'png') {
			imagealphablending($newImage, FALSE);
			imagesavealpha($newImage, TRUE);
		}

		imagecopyresampled($newImage, $image, 0, 0, $clipX, $clipY, $newWidth,
						   $newHeight, $width, $height);

		if ($ext === 'png')
			imagepng($newImage, null, 0);
		elseif ($ext === 'gif')
			imagegif($newImage, null, 0);
		else
			imagejpeg($newImage, null, 100);
	}

	private function getWordPressRootDir() {
		$dir = __FILE__;

		$isWordPressRootDir = FALSE;

		do {
			if ($dir) {
				$dir = dirname($dir);
			}
			else {
				$dir = NULL;
				break;
			}

			$handle = opendir($dir);
			while (FALSE !== ($entry = readdir($handle))) {
				if ($entry === 'wp-config.php') {
					$isWordPressRootDir = TRUE;
					break;
				}
			}
		} while (!$isWordPressRootDir);

		return $dir;
	}

	// http://it2.php.net/manual/en/function.imagecreatefromgif.php#104473
	private function is_ani($filename) {
		if(!($fh = @fopen($filename, 'rb')))
			return false;
		$count = 0;
		//an animated gif contains multiple "frames", with each frame having a
		//header made up of:
		// * a static 4-byte sequence (\x00\x21\xF9\x04)
		// * 4 variable bytes
		// * a static 2-byte sequence (\x00\x2C) (some variants may use \x00\x21 ?)

		// We read through the file til we reach the end of the file, or we've found
		// at least 2 frame headers
		while(!feof($fh) && $count < 2) {
			$chunk = fread($fh, 1024 * 100); //read 100kb at a time
			$count += preg_match_all('#\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)#s', $chunk, $matches);
	   }

		fclose($fh);
		return $count > 1;
	}

	private function deleteThumbnails($folder=NULL) {
		$imageIds = $this->getImageIds();

		foreach ($imageIds as $id) {
			$image = get_post($id);
			$fullsizepath = get_attached_file($image->ID);

			if (FALSE !== $fullsizepath && file_exists($fullsizepath))
				$this->remove_old_images($image->ID);
		}
	}

	// Get IDs of all WordPress images
	private function getImageIds() {
		$ids = array();

		$query_args = array(
			'post_type' => 'attachment',
			'post_mime_type' => 'image',
			'post_status' => 'any',
			'posts_per_page' => -1,
			'fields' => 'ids'
		);
		$images = new WP_Query($query_args);

		if ($images->post_count) {
			foreach ($images->posts as $id)
				$ids[] = $id;
		}

		return $ids;
	}


	// Regenerate Thumbnails (from wp-cli)
	private function regenerateThumbnails() {

		// Remove our override
		remove_filter('intermediate_image_sizes_advanced', '__return_empty_array');

		$imageIds = $this->getImageIds();

		foreach ( $imageIds as $id ) {
			$this->process_regeneration( $id );
		}
	}

	private function process_regeneration( $id ) {
		$image = get_post( $id );
		$fullsizepath = get_attached_file( $image->ID );
		if ( FALSE === $fullsizepath || !file_exists( $fullsizepath ) ) {
			return;
		}
		$this->remove_old_images( $image->ID );
		$metadata = wp_generate_attachment_metadata( $image->ID, $fullsizepath );
		if ( is_wp_error( $metadata ) ) {
			return;
		}
		if ( empty( $metadata ) ) {
			return;
		}
		wp_update_attachment_metadata( $image->ID, $metadata );
	}

	private function remove_old_images( $att_id ) {
		$wud = wp_upload_dir();
		$metadata = wp_get_attachment_metadata( $att_id );
		if ( FALSE === $metadata || !isset( $metadata['file'] ) ) {
			return;
		}
		$dir_path = $wud['basedir'] . '/' . dirname( $metadata['file'] ) . '/';
		$original_path = $dir_path . basename( $metadata['file'] );
		if ( empty( $metadata['sizes'] ) ) {
			return;
		}
		foreach ( $metadata['sizes'] as $size_info ) {
			$intermediate_path = $dir_path . $size_info['file'];
			if ( $intermediate_path == $original_path )
				continue;
			if ( file_exists( $intermediate_path ) )
				unlink( $intermediate_path );
		}
	}

	public function imageDownsizeFilter($downsize, $id, $size) {
		$imgUrl = wp_get_attachment_url($id);

		if (is_string($size))
			$sizes = $this->getImageSizes($size);
		else if (is_array($size))
			$sizes = array('width' => $size[0], 'height' => $size[1]);
		else
			return FALSE;

		if ($sizes !== FALSE) {
			$imgUrlArr = explode('.', $imgUrl);
			$ext = array_pop($imgUrlArr);

			$imgUrl = implode('.', $imgUrlArr) . '-' . $sizes['width'] . 'x' . $sizes['height'] . '.' . $ext;

			return array($imgUrl, $sizes['width'], $sizes['height'], TRUE);
		} else {
			return array($imgUrl, 0, 0, FALSE);
		}
	}

	private function getImageSizes($size='') {

		global $_wp_additional_image_sizes;

		$sizes = array();
		$get_intermediate_image_sizes = get_intermediate_image_sizes();

		// Create the full array with sizes and crop info
		foreach ($get_intermediate_image_sizes as $_size) {

			if (in_array($_size, array('thumbnail', 'medium', 'large'))) {

				$sizes[$_size]['width'] = get_option($_size . '_size_w');
				$sizes[$_size]['height'] = get_option($_size . '_size_h');
				$sizes[$_size]['crop'] = (bool) get_option($_size . '_crop');

			} elseif (isset($_wp_additional_image_sizes[$_size])) {

					$sizes[$_size] = array(
						'width' => $_wp_additional_image_sizes[$_size]['width'],
						'height' => $_wp_additional_image_sizes[$_size]['height'],
						'crop' =>  $_wp_additional_image_sizes[$_size]['crop']
					);
			}
		}

		// Get only 1 size if found
		if ($size) {
			if (isset($sizes[$size])) {
				return $sizes[$size];
			} else {
				return FALSE;
			}
		}

		return $sizes;
	}

}

new IntermediateImageSizes();
