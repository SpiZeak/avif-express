<?php

namespace Avife\common;

if (!defined('ABSPATH')) exit;

use Avife\common\CronManager;
use Avife\common\Options;

/**
 * MissingSizes
 *
 * Self-healing for intermediate image size files that are recorded in
 * attachment metadata but missing on disk (visitor facing 404s). This
 * drift happens when size files are deleted without updating metadata,
 * e.g. by `wp avif-express sizes purge` without a working web server
 * fallback, or by external cleanup tools.
 *
 * For images scaled by WordPress on upload (the "-scaled" copies) the
 * unscaled original is often missing too, which also breaks
 * `wp media regenerate` (it insists on the original file). The repair
 * therefore restores the original from its "-scaled" copy before
 * regenerating sizes, the same way `wp media regenerate` does after
 * the original is put back.
 */
class MissingSizes
{
    /**
     * cron hook draining the repair queue
     */
    const HOOK = 'avife_missing_sizes_repair';

    /**
     * option holding the pending repairs as attachment file => attachment id
     */
    const QUEUE_OPTION = 'avife_missing_sizes_queue';

    /**
     * option holding the id the incremental scan continues from
     */
    const CURSOR_OPTION = 'avife_missing_sizes_cursor';

    /**
     * attachments actually repaired per cron run
     */
    const CRON_REPAIR_BATCH = 25;

    /**
     * attachments inspected per cron run
     */
    const CRON_SCAN_BATCH = 500;

    /**
     * activate
     * Scheduling the hourly repair cron, or clearing it when disabled
     * @return void
     */
    public static function activate()
    {
        $cron = new CronManager(self::HOOK, array('Avife\common\MissingSizes', 'handleCron'));

        if (!Options::getRepairMissingSizes()) {
            if (wp_next_scheduled(self::HOOK)) $cron->clear();
            return;
        }

        if (!wp_next_scheduled(self::HOOK)) $cron->schedule('hourly');
    }

    /**
     * handleCron
     * Repairs a batch of queued attachments and advances the incremental
     * scan so newly drifted attachments enter the queue
     * @return void
     */
    public static function handleCron()
    {
        if (!Options::getRepairMissingSizes()) return;

        if (function_exists('set_time_limit')) @set_time_limit(300);

        $queue = self::getQueue();
        $batch = max(1, (int)apply_filters('avife_missing_sizes_cron_batch', self::CRON_REPAIR_BATCH));
        $repaired = 0;

        /**
         * every entry leaves the queue on its first attempt, repaired or
         * not, so unrepairable attachments cannot clog the cron
         */
        foreach ($queue as $file => $attachmentId) {
            unset($queue[$file]);
            $result = self::repairAttachment($attachmentId);
            if ($result['status'] === 'repaired') {
                $repaired++;
                if (WP_DEBUG) error_log('avife missing sizes: repaired ' . $file);
            } elseif ($result['status'] !== 'intact' && WP_DEBUG) {
                error_log('avife missing sizes: ' . $result['status'] . ' ' . $file . ' (' . $result['message'] . ')');
            }
            if ($repaired >= $batch) break;
        }

        $cursor = (int)get_option(self::CURSOR_OPTION, 0);
        $next = $cursor;
        foreach (self::findAffected($cursor, self::CRON_SCAN_BATCH, $next) as $file => $attachmentId) {
            if (!isset($queue[$file])) $queue[$file] = $attachmentId;
        }
        if ($next !== $cursor) update_option(self::CURSOR_OPTION, $next, 'no');

        self::updateQueue($queue);
    }

    /**
     * findAffected
     * Inspects a batch of image attachments and returns those with size
     * files recorded in metadata but missing on disk, or with no sizes
     * recorded at all (metadata wiped by a previous failed regeneration)
     * @param int $fromId inspect attachments with an id greater than this
     * @param int $limit number of attachments to inspect
     * @param int $nextId updated to continue scanning from; 0 when the
     * end was reached and the scan should wrap to the beginning
     * @return array attachment relative file => attachment id, keyed by
     * file so duplicates sharing one file (e.g. WPML) only queue once
     */
    public static function findAffected($fromId, $limit, &$nextId)
    {
        global $wpdb;

        $uploads = wp_get_upload_dir();
        $baseDir = !empty($uploads['error']) ? '' : $uploads['basedir'];
        $nextId = (int)$fromId;
        $found = array();

        if ($baseDir === '') return $found;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_mime_type FROM {$wpdb->posts}
                WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND ID > %d
                ORDER BY ID ASC LIMIT %d",
                $nextId,
                $limit
            )
        );

        foreach ($rows as $row) {
            $nextId = (int)$row->ID;
            if (!self::isRegeneratableImage($row->post_mime_type)) continue;
            $meta = wp_get_attachment_metadata($nextId);
            if (empty($meta['file']) || !file_exists($baseDir . '/' . $meta['file'])) continue;
            if (!empty($meta['sizes']) && !self::missingSizes($meta, $baseDir)) continue;
            if (!isset($found[$meta['file']])) $found[$meta['file']] = $nextId;
        }

        if (count($rows) < $limit) $nextId = 0;

        return $found;
    }

    /**
     * repairAttachment
     * Restores a missing unscaled original from its "-scaled" copy and
     * regenerates all registered intermediate size files through the
     * same core API `wp media regenerate` uses. Attachments with no
     * sizes recorded in metadata (wiped by a failed regeneration) are
     * rebuilt the same way
     * @param int $attachmentId attachment id to repair
     * @return array array('status' => repaired|partial|intact|unrepairable|error|skipped, 'message' => string)
     */
    public static function repairAttachment($attachmentId)
    {
        $attachmentId = (int)$attachmentId;
        $post = get_post($attachmentId);

        if (!$post || $post->post_type !== 'attachment' || !self::isRegeneratableImage($post->post_mime_type)) {
            return array('status' => 'skipped', 'message' => 'not a regeneratable image attachment');
        }

        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error'])) {
            return array('status' => 'error', 'message' => $uploads['error']);
        }
        $baseDir = $uploads['basedir'];

        $meta = wp_get_attachment_metadata($attachmentId);
        if (empty($meta['file'])) {
            return array('status' => 'unrepairable', 'message' => 'no file metadata');
        }

        $hasSizes = !empty($meta['sizes']);
        $missing = $hasSizes ? self::missingSizes($meta, $baseDir) : array();
        if ($hasSizes && !$missing) return array('status' => 'intact', 'message' => 'all size files present');

        $mainFile = $baseDir . '/' . $meta['file'];
        if (!file_exists($mainFile)) {
            return array('status' => 'unrepairable', 'message' => 'main file missing: ' . $meta['file']);
        }

        /**
         * regeneration source preference: the recorded original, then a
         * sibling file without the "-scaled" suffix (metadata wipe drops
         * the original_image key but the restored original stays on
         * disk), then the main file. Regenerating from the unscaled
         * name keeps the classic size file names (X-768x….jpg) the
         * frontend historically referenced.
         */
        $source = $mainFile;
        if (!empty($meta['original_image'])) {
            $original = $baseDir . '/' . dirname($meta['file']) . '/' . $meta['original_image'];
            if (!file_exists($original)) {
                if (!@copy($mainFile, $original)) {
                    return array('status' => 'error', 'message' => 'could not restore original: ' . $meta['original_image']);
                }
                clearstatcache(false, $original);
            }
            $source = $original;
        } else {
            $sibling = preg_replace('/-scaled(\.[^.]+)$/', '$1', $mainFile);
            if ($sibling !== $mainFile && file_exists($sibling)) {
                $source = $sibling;
            }
        }

        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        /**
         * the on-demand feature suppresses intermediate size generation
         * (OnDemandImages::disableIntermediateSizes returns an empty
         * size list), which would make regeneration a no-op. the filter
         * is lifted for the duration of the call and restored after.
         */
        $suppressors = array(array('Avife\common\OnDemandImages', 'disableIntermediateSizes'));
        $lifted = array();
        foreach ($suppressors as $callback) {
            $priority = has_filter('intermediate_image_sizes_advanced', $callback);
            if ($priority !== false) {
                remove_filter('intermediate_image_sizes_advanced', $callback, $priority);
                $lifted[] = array($callback, $priority);
            }
        }

        $newMeta = wp_generate_attachment_metadata($attachmentId, $source);

        foreach ($lifted as $l) {
            add_filter('intermediate_image_sizes_advanced', $l[0], $l[1]);
        }

        if (!is_array($newMeta)) {
            return array('status' => 'error', 'message' => 'wp_generate_attachment_metadata failed');
        }

        /**
         * metadata is persisted inside wp_generate_attachment_metadata
         * even when no sizes could be created (image too small for the
         * smallest registered size, or an editor limitation) — nothing
         * more can be done for this attachment
         */
        if (empty($newMeta['sizes'])) {
            return array('status' => 'unrepairable', 'message' => 'no intermediate sizes could be generated');
        }

        wp_update_attachment_metadata($attachmentId, $newMeta);

        $stillMissing = self::missingSizes($newMeta, $baseDir);
        if ($stillMissing) {
            return array('status' => 'partial', 'message' => 'still missing: ' . implode(', ', array_slice($stillMissing, 0, 5)));
        }

        return array(
            'status' => 'repaired',
            'message' => $hasSizes ? count($missing) . ' size file(s) regenerated' : 'intermediate sizes rebuilt',
        );
    }

    /**
     * missingSizes
     * Lists size files recorded in metadata but missing on disk
     * @param array $meta attachment metadata
     * @param string $baseDir uploads base directory
     * @return array size file name => size name, empty when intact
     */
    public static function missingSizes($meta, $baseDir)
    {
        if (empty($meta['file']) || empty($meta['sizes'])) return array();

        $dir = $baseDir . '/' . dirname($meta['file']);
        $missing = array();

        foreach ($meta['sizes'] as $name => $size) {
            if (empty($size['file']) || isset($missing[$size['file']])) continue;
            if (!file_exists($dir . '/' . $size['file'])) $missing[$size['file']] = $name;
        }

        return $missing;
    }

    /**
     * isRegeneratableImage
     * Raster image types the WordPress image editor can resize and that
     * carry intermediate sizes (SVG and other vector/exotic types never
     * do and would only produce repair noise)
     * @param string $mimeType attachment mime type
     * @return bool
     */
    private static function isRegeneratableImage($mimeType)
    {
        return in_array(
            $mimeType,
            array('image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'),
            true
        );
    }

    /**
     * getQueue
     * @return array pending repairs as attachment file => attachment id
     */
    private static function getQueue()
    {
        $queue = get_option(self::QUEUE_OPTION, array());
        return is_array($queue) ? $queue : array();
    }

    /**
     * updateQueue
     * @param array $queue pending repairs as attachment file => attachment id
     * @return void
     */
    private static function updateQueue($queue)
    {
        if ($queue) {
            update_option(self::QUEUE_OPTION, $queue, 'no');
        } else {
            delete_option(self::QUEUE_OPTION);
        }
    }
}
