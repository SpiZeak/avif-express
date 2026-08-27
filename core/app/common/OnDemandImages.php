<?php

namespace Avife\common;

if (!defined('ABSPATH')) exit;

class OnDemandImages
{
    /**
     * maximum pixel width/height the handler is allowed to generate.
     * anything above this is treated as an abusive request.
     */
    const MAX_DIMENSION = 4000;

    /**
     * extensions the handler is allowed to generate.
     * mirrors Html::isSupportedExtension().
     */
    const ALLOWED_EXTENSIONS = array('jpg', 'jpeg', 'png');

    /**
     * activate
     * Adding our methods to WordPress hooks
     * @return void
     */
    public static function activate()
    {
        if (!Options::getOnDemandImages()) return;

        /**
         * 1. prevent WordPress from creating intermediate image sizes on upload
         */
        add_filter('intermediate_image_sizes_advanced', array('Avife\common\OnDemandImages', 'disableIntermediateSizes'));

        /**
         * 2. provide on-the-fly url and dimensions for requested image sizes
         */
        add_filter('image_downsize', array('Avife\common\OnDemandImages', 'provideDynamicImageSize'), 10, 3);

        /**
         * 3. catch the request WordPress receives when the web server
         * falls back to it for a missing intermediate size file
         * (e.g. Nginx try_files fallback), generate the file and deliver it
         */
        add_action('init', array('Avife\common\OnDemandImages', 'handleRequest'), 1);
    }

    /**
     * disableIntermediateSizes
     * stops WordPress from generating thumbnail files on upload
     * @param array $new_sizes registered intermediate sizes
     * @return array empty array
     */
    public static function disableIntermediateSizes($new_sizes)
    {
        return array();
    }

    /**
     * provideDynamicImageSize
     * calculates url and dimensions on the fly for the frontend
     * @param bool|array $out whether the image is already down-sized
     * @param int $id attachment id
     * @param string|array $size requested image size
     * @return bool|array false to let WordPress continue,
     * array(string url, int width, int height, bool is_intermediate) on success
     */
    public static function provideDynamicImageSize($out, $id, $size)
    {
        /**
         * let WordPress handle full-size images
         */
        if ($size === 'full' || $size === 'original') return false;

        $imgUrl = wp_get_attachment_url($id);
        $meta = wp_get_attachment_metadata($id);

        if (!$imgUrl || !$meta || !isset($meta['width'], $meta['height'])) return false;

        $targetWidth = 0;
        $targetHeight = 0;
        $crop = false;

        /**
         * calculating dimension based on requested size
         */
        if (is_array($size)) {
            $targetWidth = $size[0];
            $targetHeight = $size[1];
        } else {
            global $_wp_additional_image_sizes;
            if (in_array($size, array('thumbnail', 'medium', 'medium_large', 'large'))) {
                $targetWidth = get_option("{$size}_size_w");
                $targetHeight = get_option("{$size}_size_h");
                $crop = (bool)get_option("{$size}_crop");
            } elseif (isset($_wp_additional_image_sizes[$size])) {
                $targetWidth = $_wp_additional_image_sizes[$size]['width'];
                $targetHeight = $_wp_additional_image_sizes[$size]['height'];
                $crop = $_wp_additional_image_sizes[$size]['crop'];
            } else {
                return false;
            }
        }

        /**
         * image_resize_dimensions() lives in wp-includes/media.php on recent
         * WordPress versions. On older ones it is only loaded in admin
         * requests, so it is loaded here before use.
         */
        if (!function_exists('image_resize_dimensions')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        /**
         * calculating actual dimensions maintaining the aspect ratio
         */
        $dims = image_resize_dimensions($meta['width'], $meta['height'], $targetWidth, $targetHeight, $crop);

        if (!$dims) {
            return array($imgUrl, $meta['width'], $meta['height'], false);
        }

        $destW = $dims[4];
        $destH = $dims[5];

        /**
         * building the url the web server will fall back for
         * (ex: https://example.com/app/uploads/2019/07/107882-1-768x1097.jpg)
         */
        $info = pathinfo($imgUrl);
        if (empty($info['extension'])) return false;

        $newUrl = $info['dirname'] . '/' . $info['filename'] . '-' . $destW . 'x' . $destH . '.' . $info['extension'];

        return array($newUrl, $destW, $destH, true);
    }

    /**
     * handleRequest
     * generates and delivers the requested intermediate image file when the
     * web server falls back to WordPress for a missing file
     * @return void
     */
    public static function handleRequest()
    {
        if (!isset($_GET['od_image'], $_GET['od_w'], $_GET['od_h'], $_GET['od_ext'])) return;

        $relPath = sanitize_text_field(wp_unslash($_GET['od_image']));
        $width = absint($_GET['od_w']);
        $height = absint($_GET['od_h']);
        $ext = strtolower(sanitize_text_field(wp_unslash($_GET['od_ext'])));

        /**
         * $relPath expected in "2019/07/107882-1" format
         * rejecting path traversal and not allowed extensions
         */
        $relPath = str_replace('\\', '/', $relPath);
        if (strpos($relPath, '..') !== false) {
            self::terminate(400, 'Bad Request: Invalid image path.');
        }
        $relPath = ltrim(preg_replace('#/{2,}#', '/', $relPath), '/');

        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            self::terminate(400, 'Bad Request: Unsupported image type.');
        }

        if ($width <= 0 || $height <= 0 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            self::terminate(400, 'Bad Request: Invalid dimensions.');
        }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            self::terminate(500, 'Upload directory error.');
        }
        $baseDir = $uploads['basedir'];

        $originalFile = $baseDir . '/' . $relPath . '.' . $ext;

        /**
         * keeping the generated file inside the uploads directory
         */
        $realDir = wp_normalize_path(realpath(dirname($originalFile)));
        $realBaseDir = wp_normalize_path(realpath($baseDir));
        if ($realDir === '' || $realBaseDir === '' || strpos($realDir . '/', trailingslashit($realBaseDir)) !== 0) {
            self::terminate(400, 'Bad Request: Invalid image path.');
        }

        /**
         * when the original does not exist, looking for a "-scaled" version instead
         */
        if (!file_exists($originalFile)) {
            $scaledFile = $baseDir . '/' . $relPath . '-scaled.' . $ext;

            if (file_exists($scaledFile)) {
                $originalFile = $scaledFile;
            } else {
                self::terminate(404, 'Original image not found.');
            }
        }

        /**
         * starting WordPress image editor (handles GD/Imagick)
         */
        $editor = wp_get_image_editor($originalFile);
        if (is_wp_error($editor)) {
            self::terminate(500, 'Image engine error: ' . $editor->get_error_message());
        }

        /**
         * clamping the requested dimensions to what the source allows,
         * upscaling is refused by the image editor (e.g. requesting 300x300
         * from a 200x200 original). when no resize is possible the original
         * file is delivered as-is, mirroring WordPress core behavior
         */
        if (!function_exists('image_resize_dimensions')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $origSize = $editor->get_size();
        $dims = image_resize_dimensions($origSize['width'], $origSize['height'], $width, $height, true);

        if (!is_array($dims)) {
            self::serveFile($originalFile);
        }

        /**
         * resizing (true = hard crop, matches the HTML output)
         */
        $resized = $editor->resize($dims[4], $dims[5], true);
        if (is_wp_error($resized)) {
            self::terminate(500, 'Image resize error: ' . $resized->get_error_message());
        }

        /**
         * creating exactly the filename the web server asked for and saving
         * the file next to the original
         */
        $resizedFilename = $baseDir . '/' . $relPath . '-' . $width . 'x' . $height . '.' . $ext;
        $saved = $editor->save($resizedFilename);

        if (is_wp_error($saved)) {
            self::terminate(500, 'Could not save image: ' . $saved->get_error_message());
        }

        /**
         * delivering the image to the browser
         */
        self::serveFile($saved['path'], $saved['mime-type']);
    }

    /**
     * serveFile
     * streams an image file to the browser with long-lived cache headers
     * @param string $file absolute path of the file to deliver
     * @param string $mime mime type, falls back to the file's own type
     * @return void
     */
    private static function serveFile($file, $mime = '')
    {
        if ($mime === '') {
            $mime = wp_check_filetype($file);
            $mime = $mime['type'] !== '' ? $mime['type'] : 'application/octet-stream';
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=31536000');
        readfile($file);
        exit;
    }

    /**
     * terminate
     * sends an error response and stops the request
     * @param int $statusCode HTTP status code
     * @param string $message error message
     * @return void
     */
    private static function terminate($statusCode, $message)
    {
        nocache_headers();
        status_header($statusCode);
        exit($message);
    }
}
