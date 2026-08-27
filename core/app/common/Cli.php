<?php

namespace Avife\common;

if (!defined('ABSPATH')) exit;

class Cli
{
    /**
     * register
     * Adding the plugin's WP-CLI commands
     * @return void
     */
    public static function register()
    {
        if (!class_exists('WP_CLI')) return;

        \WP_CLI::add_command('avif-express ondemand', array('Avife\common\Cli', 'onDemand'));
        \WP_CLI::add_command('avif-express sizes purge', array('Avife\common\Cli', 'purgeSizes'));
    }

    /**
     * Manage the on-demand image sizes feature.
     *
     * ## OPTIONS
     *
     * <action>
     * : What to do: status, enable or disable.
     * ---
     * options:
     *   - status
     *   - enable
     *   - disable
     * ---
     *
     * ## EXAMPLES
     *
     *     wp avif-express ondemand status
     *     wp avif-express ondemand enable
     *
     * @param array $args positional args
     * @return void
     */
    public static function onDemand($args)
    {
        $action = isset($args[0]) ? $args[0] : '';

        switch ($action) {
            case 'status':
                \WP_CLI::line(Options::getOnDemandImages() ? 'active' : 'inactive');
                break;
            case 'enable':
            case 'disable':
                Options::setOnDemandImages($action === 'enable');
                \WP_CLI::success("on-demand image sizes {$action}d.");
                break;
            default:
                \WP_CLI::error("Invalid action '{$action}'. Use status, enable or disable.");
        }
    }

    /**
     * Delete already generated intermediate image size files.
     *
     * With on-demand image sizes active, size files are only needed once a
     * visitor requests them, so existing files are redundant disk usage.
     * By default every image attachment is inspected: size files recorded in
     * its metadata plus files matching the attachment's own size filename
     * pattern (this catches on-demand generated files, which are not part of
     * metadata, and their avif siblings). Originals and "-scaled" files are
     * never touched and attachment metadata is kept (srcset keeps working;
     * deleted sizes are regenerated on the next request via the web server
     * fallback).
     *
     * ## OPTIONS
     *
     * [--attachment=<id>]
     * : Only purge size files of a single attachment ID.
     *
     * [--scan]
     * : Scan the uploads directory by filename pattern instead of per
     * attachment. Catches files of deleted attachments too, but cannot
     * distinguish originals genuinely named like "photo-800x600.jpg".
     *
     * [--dry-run]
     * : Only list what would be deleted, delete nothing.
     *
     * ## EXAMPLES
     *
     *     wp avif-express sizes purge --dry-run
     *     wp avif-express sizes purge --attachment=1849
     *     wp avif-express sizes purge --scan
     *
     * @param array $args positional args
     * @param array $assoc_args flags
     * @return void
     */
    public static function purgeSizes($args, $assoc_args)
    {
        $dryRun = \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);
        $scan = \WP_CLI\Utils\get_flag_value($assoc_args, 'scan', false);
        $attachmentId = \WP_CLI\Utils\get_flag_value($assoc_args, 'attachment', null);

        global $wpdb;

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            \WP_CLI::error($uploads['error']);
        }
        $baseDir = $uploads['basedir'];

        $deleted = 0;
        $bytes = 0;
        $attachments = 0;
        $seen = array();

        $deleteFile = function ($file) use ($dryRun, &$deleted, &$bytes, &$seen) {
            /**
             * several size entries can point to the same file (e.g. medium and
             * woocommerce_thumbnail both 300x300), only count it once
             */
            if (isset($seen[$file])) return;
            $seen[$file] = true;

            if (!file_exists($file)) return;
            $size = filesize($file);
            if ($dryRun) {
                \WP_CLI::line('would delete: ' . $file);
            } else {
                /**
                 * wp_delete_file() is filterable and third-party plugins can
                 * veto the deletion (e.g. WPML blocks files it resolves to a
                 * managed attachment), verify and fall back to a direct unlink
                 */
                wp_delete_file($file);
                clearstatcache(false, $file);
                if (file_exists($file)) {
                    @unlink($file);
                    clearstatcache(false, $file);
                    if (file_exists($file)) {
                        \WP_CLI::warning('could not delete: ' . $file);
                        return;
                    }
                }
            }
            $deleted++;
            $bytes += $size;
        };

        if ($scan) {
            if ($attachmentId !== null) {
                \WP_CLI::error('--scan cannot be combined with --attachment.');
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) continue;
                if (!preg_match('/-\d+x\d+\.(jpe?g|png|avif)$/i', $fileInfo->getFilename())) continue;

                $deleteFile($fileInfo->getPathname());
            }

            \WP_CLI::log('note: --scan deletes by filename pattern, originals named like "photo-800x600.jpg" cannot be distinguished.');
        } else {
            if ($attachmentId !== null) {
                $attachmentId = absint($attachmentId);
                if ($attachmentId <= 0) {
                    \WP_CLI::error('Invalid --attachment value.');
                }
                $ids = array($attachmentId);
            } else {
                $ids = $wpdb->get_col(
                    "SELECT ID FROM {$wpdb->posts}
                    WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'
                    ORDER BY ID"
                );
            }

            /**
             * main files of every attachment, never eligible for deletion
             */
            $mainFiles = array();
            $targets = array();
            foreach ($ids as $id) {
                $meta = wp_get_attachment_metadata($id);
                if (empty($meta['file'])) continue;

                $attachments++;
                $dir = dirname($baseDir . '/' . $meta['file']);
                $base = pathinfo(basename($meta['file']), PATHINFO_FILENAME);
                $mainFiles[basename($meta['file'])] = true;

                /**
                 * size files recorded in metadata and their avif siblings
                 */
                if (!empty($meta['sizes'])) {
                    foreach ($meta['sizes'] as $size) {
                        $file = $dir . '/' . basename($size['file']);
                        $targets[$file] = true;
                        $avifSibling = preg_replace('/\.(jpe?g|png)$/i', '.avif', $file);
                        if ($avifSibling !== null) $targets[$avifSibling] = true;
                    }
                }

                /**
                 * on-demand generated size files are not part of metadata,
                 * sweep the attachment's own size filename pattern
                 */
                foreach (glob($dir . '/' . $base . '-*') ?: array() as $file) {
                    if (!preg_match('/^' . preg_quote($base, '/') . '-\d+x\d+\.(jpe?g|png|avif)$/i', basename($file))) continue;
                    $targets[$file] = true;
                }
            }

            foreach (array_keys($targets) as $file) {
                if (isset($mainFiles[basename($file)])) continue;
                $deleteFile($file);
            }
        }

        \WP_CLI::success(trim(sprintf(
            '%s %d size file(s) (%s)%s.',
            $dryRun ? 'Would delete' : 'Deleted',
            $deleted,
            size_format($bytes),
            $scan ? '' : sprintf(' from %d attachment(s)', $attachments)
        )));
    }
}
