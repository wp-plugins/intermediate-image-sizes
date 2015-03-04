<?php
/*
Plugin Name: Intermediate Image Sizes
Description: Create thumbnails on the fly instead of storing them on disk
Version: 0.3.2
Author: Headspin <vegard@headspin.no>
Author URI: http://www.headspin.no
Licence: GPL2
*/
class ImageSize {

	private $htaccessPath = '';

	public function __construct() {
		if ($this->isInsideWordpress()) {
			$this->htaccessPath = ABSPATH . '.htaccess';
			register_activation_hook(__FILE__, array($this, 'activate'));
			register_deactivation_hook(__FILE__, array($this, 'deactivate'));

			// Stop WordPress from storing thumbnails
			add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
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
# BEGIN Wordpress plugin ImageSize
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-s
RewriteCond %{REQUEST_URI} (.+)-([0-9]+)x([0-9]+)\.(jpg|jpeg|png)$
RewriteRule (.+)-([0-9]+)x([0-9]+)\.(.+)$ ${filePath}?path=$1&width=$2&height=$3&ext=$4 [L]
</IfModule>
# END Wordpress plugin ImageSize

HTACCESS;

		$htaccess = @file_get_contents($this->htaccessPath);
		if ($htaccess !== FALSE) {
			if (strpos($htaccess, '# BEGIN Wordpress plugin ImageSize') === FALSE) {
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
			$newHtaccess = preg_replace('/# BEGIN Wordpress plugin ImageSize.*# END Wordpress plugin ImageSize\n/s',
				'', $htaccess);
			if ($newHtaccess !== $htaccess) {
				file_put_contents($this->htaccessPath, $newHtaccess);
			}
		}
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

		$httpType = $ext === 'png' ? 'png' : 'jpeg';
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
			imagecreatefrompng($filename) : imagecreatefromjpeg($filename);
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

	private function deleteThumbnails($folder=NULL) {

		if ($folder === NULL) {
			$uploadDir = wp_upload_dir();
			$folder = $uploadDir['basedir'];
		}

		if (is_dir($folder)) {
			$dh = opendir($folder);
			while (FALSE !== ($filename = readdir($dh))) {

				// Ignore these files/folders
				if (in_array($filename, array('..', '.', '')))
					continue;

				$path = $folder . '/' . $filename;

				// Delete recursively
				if (is_dir($path)) {
					$this->deleteThumbnails($path);
				}
				else {
					if ($this->isThumbnailFile($filename))
						@unlink($path);
				}
			}
		}
	}

	private function isThumbnailFile($filename) {
		$pattern = '/(.+)-([0-9]+)x([0-9]+)\.(jpg|jpeg|png)$/';
		preg_match($pattern, $filename, $matches);
		return $matches;
	}

}

new ImageSize();
