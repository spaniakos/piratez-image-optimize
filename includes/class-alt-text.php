<?php
/** Review-first media alt text tools. No automatic upload or frontend rewriting. */
if (!defined('ABSPATH')) { exit; }

class Piratez_IO_Alt_Text {
    const ALT = '_wp_attachment_image_alt';
    const DECORATIVE = '_piratez_io_decorative';
    const LIMIT = 50;
    const HISTORY_LIMIT = 20;

    public static function init() {
        add_action('wp_ajax_piratez_io_alt', array(__CLASS__, 'ajax'));
    }
    public static function allowed() { return current_user_can('manage_options') && current_user_can('upload_files'); }
    private static function error($message) { return new WP_Error('piratez_io_alt', $message); }
    public static function clean($value) {
        if (!is_string($value) || strlen($value) > 4000) { return self::error('Alt text must be plain text of at most 1,000 characters.'); }
        $text = sanitize_text_field($value);
        if (preg_match_all('/./us', $text, $chars) > 1000) { return self::error('Alt text must be at most 1,000 characters.'); }
        return $text;
    }
    public static function state($id) {
        if (!self::allowed() || !wp_attachment_is_image($id) || !current_user_can('edit_post', $id) || get_post_status($id) === 'trash') { return self::error('Image is unavailable or you cannot edit it.'); }
        $out = array();
        foreach (array(self::ALT, self::DECORATIVE) as $key) {
            $values = get_post_meta($id, $key, false);
            if (count($values) > 1 || (isset($values[0]) && !is_string($values[0]))) { return self::error('Ambiguous attachment metadata; review it in the Media Library first.'); }
            $out[$key] = array('exists' => count($values) === 1, 'value' => isset($values[0]) ? $values[0] : '');
        }
        return $out;
    }
    public static function suggestion($id, $mode) {
        if ($mode === 'filename') {
            $file = pathinfo(wp_basename(get_attached_file($id)), PATHINFO_FILENAME);
            $file = preg_replace('/(?:-\d+x\d+|-scaled|-rotated)+$/iu', '', rawurldecode($file));
            return self::clean(preg_replace('/[-_]+/u', ' ', $file));
        }
        if ($mode === 'parent_title') {
            $parent = (int) wp_get_post_parent_id($id);
            return $parent && current_user_can('edit_post', $parent) ? self::clean(get_the_title($parent)) : '';
        }
        return self::clean(get_the_title($id));
    }
    public static function query($filter = 'missing', $search = '', $page = 1, $order = 'date') {
        $empty = array('relation' => 'OR', array('key' => self::ALT, 'compare' => 'NOT EXISTS'), array('key' => self::ALT, 'value' => '', 'compare' => '='));
        $not_decorative = array('relation' => 'OR', array('key' => self::DECORATIVE, 'compare' => 'NOT EXISTS'), array('key' => self::DECORATIVE, 'value' => '1', 'compare' => '!='));
        $args = array('post_type' => 'attachment', 'post_status' => array('inherit', 'private'), 'post_mime_type' => 'image', 'posts_per_page' => 20, 'paged' => max(1, (int) $page), 's' => $search, 'orderby' => $order === 'title' ? 'title' : 'date', 'order' => $order === 'title' ? 'ASC' : 'DESC');
        if ($filter === 'missing') { $args['meta_query'] = array('relation' => 'AND', $empty, $not_decorative); }
        elseif ($filter === 'with') { $args['meta_query'] = array('relation' => 'AND', array('key' => self::ALT, 'value' => '', 'compare' => '!='), $not_decorative); }
        elseif ($filter === 'decorative') { $args['meta_query'] = array(array('key' => self::DECORATIVE, 'value' => '1')); }
        return new WP_Query($args);
    }
    public static function counts() {
        global $wpdb;
        // Aggregate without multiplying attachments that have duplicate metadata.
        return $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total,
            COALESCE(SUM(EXISTS(SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key=%s AND m.meta_value='1')),0) AS decorative,
            COALESCE(SUM(NOT EXISTS(SELECT 1 FROM {$wpdb->postmeta} d WHERE d.post_id=p.ID AND d.meta_key=%s AND d.meta_value='1') AND EXISTS(SELECT 1 FROM {$wpdb->postmeta} a WHERE a.post_id=p.ID AND a.meta_key=%s AND a.meta_value<>'')),0) AS with_alt
            FROM {$wpdb->posts} p WHERE p.post_type='attachment' AND p.post_status IN ('inherit','private') AND p.post_mime_type LIKE 'image/%%'", self::DECORATIVE, self::DECORATIVE, self::ALT), ARRAY_A);
    }
    private static function preview_key() { return 'piratez_io_alt_preview_' . get_current_user_id(); }
    private static function history_key() { return 'piratez_io_alt_history_' . get_current_user_id(); }
    private static function preview($kind, $changes, $rows) {
        if (!$changes) { return self::error('No eligible changes. Existing or decorative text is protected unless you explicitly allow replacement.'); }
        if (strlen(serialize($changes)) > 524288) { return self::error('This preview is too large. Review fewer images or a smaller post.'); }
        $token = wp_generate_uuid4();
        set_transient(self::preview_key(), array('token' => $token, 'kind' => $kind, 'changes' => $changes), 15 * MINUTE_IN_SECONDS);
        return array('token' => $token, 'rows' => $rows, 'kind' => $kind);
    }
    public static function preview_metadata($ids, $mode, $edits, $overwrite) {
        if (!self::allowed()) { return self::error('Permission denied.'); }
        if (!$ids || count($ids) > self::LIMIT || !in_array($mode, array('edit', 'filename', 'attachment_title', 'parent_title', 'decorative'), true)) { return self::error('Select 1–50 images and a valid action.'); }
        $changes = $rows = array();
        foreach (array_unique(array_map('absint', $ids)) as $id) {
            $before = self::state($id);
            $row = array('id' => $id, 'title' => get_the_title($id), 'status' => 'Skipped');
            if (is_wp_error($before)) { $row['status'] = $before->get_error_message(); $rows[] = $row; continue; }
            $old = $before[self::ALT]['value'];
            $row['before'] = $old;
            if (!$overwrite && ($old !== '' || $before[self::DECORATIVE]['value'] === '1')) { $row['status'] = 'Protected existing/decorative text'; $rows[] = $row; continue; }
            $alt = $mode === 'decorative' ? '' : ($mode === 'edit' ? self::clean($edits[$id] ?? '') : self::suggestion($id, $mode));
            if (is_wp_error($alt)) { $row['status'] = $alt->get_error_message(); $rows[] = $row; continue; }
            if ($alt === '' && $mode !== 'edit' && $mode !== 'decorative') { $row['status'] = 'No usable suggestion'; $rows[] = $row; continue; }
            $after = array(self::ALT => array('exists' => true, 'value' => $alt), self::DECORATIVE => array('exists' => $mode === 'decorative', 'value' => $mode === 'decorative' ? '1' : ''));
            if ($after === $before) { $row['status'] = 'Unchanged'; $rows[] = $row; continue; }
            $row['after'] = $alt; $row['status'] = $mode === 'decorative' ? 'Mark decorative' : 'Ready';
            $changes[] = array('id' => $id, 'before' => $before, 'after' => $after);
            $rows[] = $row;
        }
        return self::preview('metadata', $changes, $rows);
    }
    public static function history() { $value = get_option(self::history_key(), array()); return is_array($value) ? $value : array(); }
    private static function save_history($history) {
        $history = array_slice($history, 0, self::HISTORY_LIMIT);
        while (count($history) > 1 && strlen(serialize($history)) > 1048576) { array_pop($history); }
        return update_option(self::history_key(), $history, false);
    }
    private static function lock() {
        global $wpdb;
        $name = 'piratez_io_alt_write_lock'; $value = wp_generate_uuid4() . '|' . time();
        if (!add_option($name, $value, '', false)) {
            $old = get_option($name, ''); $parts = explode('|', (string) $old);
            if (count($parts) !== 2 || (int) $parts[1] + 60 >= time()) { return false; }
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $name, $old));
            wp_cache_delete($name, 'options');
            if (!add_option($name, $value, '', false)) { return false; }
        }
        return $value;
    }
    private static function unlock($value) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name='piratez_io_alt_write_lock' AND option_value=%s", $value));
        wp_cache_delete('piratez_io_alt_write_lock', 'options');
    }
    private static function write_field($id, $key, $before, $after) {
        $values = get_post_meta($id, $key, false);
        $current = array('exists' => count($values) === 1, 'value' => isset($values[0]) ? $values[0] : '');
        if (count($values) > 1 || $current !== $before) { return false; }
        if ($before === $after) { return true; }
        if (!$after['exists']) { delete_post_meta($id, $key, $before['value']); }
        elseif (!$before['exists']) { add_post_meta($id, $key, wp_slash($after['value']), true); }
        else { update_post_meta($id, $key, wp_slash($after['value']), $before['value']); }
        $values = get_post_meta($id, $key, false);
        return count($values) <= 1 && array('exists' => count($values) === 1, 'value' => isset($values[0]) ? $values[0] : '') === $after;
    }
    private static function write_metadata($id, $before, $after) {
        $current = self::state($id);
        if (is_wp_error($current) || $current !== $before) { return self::error('Image changed since preview; skipped.'); }
        $applied = array();
        foreach ($after as $key => $value) {
            if (!self::write_field($id, $key, $before[$key], $value)) {
                foreach (array_reverse($applied) as $done) { self::write_field($id, $done, $after[$done], $before[$done]); }
                return self::error('Metadata write failed; completed fields were rolled back where unchanged. Review the Media Library before retrying.');
            }
            $applied[] = $key;
        }
        return self::state($id) === $after ? true : self::error('Metadata changed during the save; review it before retrying.');
    }
    private static function write_content($id, $before, $after) {
        $post = get_post($id);
        if (!$post || !current_user_can('unfiltered_html') || !current_user_can('edit_post', $id) || wp_is_post_revision($id) || $post->post_content !== $before) { return self::error('Post changed since preview; skipped.'); }
        require_once ABSPATH . 'wp-admin/includes/post.php';
        if (wp_check_post_lock($id)) { return self::error('Another editor is editing this post; skipped.'); }
        $result = wp_update_post(wp_slash(array('ID' => $id, 'post_content' => $after)), true);
        if (is_wp_error($result)) { return $result; }
        clean_post_cache($id);
        return get_post_field('post_content', $id, 'raw') === $after ? true : self::error('WordPress changed or rejected this content; review its revision before retrying.');
    }
    public static function commit($token) {
        if (!self::allowed()) { return self::error('Permission denied.'); }
        $lock = self::lock(); if (!$lock) { return self::error('Another alt-text change is running. Retry shortly.'); }
        try {
            $preview = get_transient(self::preview_key());
            if (!$preview || !is_string($token) || !hash_equals($preview['token'], $token)) { return self::error('Preview expired or was replaced. Preview the changes again.'); }
            delete_transient(self::preview_key());
            $entry = array('id' => wp_generate_uuid4(), 'time' => time(), 'kind' => $preview['kind'], 'changes' => $preview['changes'], 'results' => array());
            $history = self::history(); array_unshift($history, $entry);
            if (!self::save_history($history)) { return self::error('Cannot save undo history; no changes were applied.'); }
            foreach ($entry['changes'] as $change) {
                $result = $entry['kind'] === 'metadata' ? self::write_metadata($change['id'], $change['before'], $change['after']) : self::write_content($change['id'], $change['before'], $change['after']);
                $entry['results'][] = array('id' => $change['id'], 'ok' => !is_wp_error($result), 'message' => is_wp_error($result) ? $result->get_error_message() : 'Saved');
            }
            $history[0] = $entry; self::save_history($history);
            return array('id' => $entry['id'], 'results' => $entry['results']);
        } finally { self::unlock($lock); }
    }
    public static function undo($batch) {
        if (!self::allowed()) { return self::error('Permission denied.'); }
        $lock = self::lock(); if (!$lock) { return self::error('Another alt-text change is running. Retry shortly.'); }
        try {
            $history = self::history();
            foreach ($history as &$entry) {
                if ($entry['id'] !== $batch) { continue; }
                if (!empty($entry['undone'])) { return self::error('This batch has already been reviewed for undo.'); }
                $results = array(); $confirmed = array();
                foreach ($entry['results'] as $saved) { if ($saved['ok']) { $confirmed[$saved['id']] = true; } }
                foreach (array_reverse($entry['changes']) as $change) {
                    if (empty($confirmed[$change['id']])) { $results[] = array('id' => $change['id'], 'ok' => false, 'message' => 'This change was not confirmed saved; review it manually.'); continue; }
                    $result = $entry['kind'] === 'metadata' ? self::write_metadata($change['id'], $change['after'], $change['before']) : self::write_content($change['id'], $change['after'], $change['before']);
                    $results[] = array('id' => $change['id'], 'ok' => !is_wp_error($result), 'message' => is_wp_error($result) ? $result->get_error_message() : 'Restored');
                }
                $entry['undone'] = time(); $entry['undo_results'] = $results; unset($entry); self::save_history($history);
                return array('results' => $results);
            }
            unset($entry); return self::error('Undo batch not found.');
        } finally { self::unlock($lock); }
    }
    /** Exact attachment identity or known full/intermediate image URL. */
    private static function matches($processor, $id) {
        $classes = preg_split('/\s+/', (string) $processor->get_attribute('class'));
        if (in_array('wp-image-' . $id, $classes, true)) { return true; }
        $src = $processor->get_attribute('src'); if (!is_string($src)) { return false; }
        $urls = array(wp_get_attachment_url($id)); $metadata = wp_get_attachment_metadata($id);
        if ($urls[0] && is_array($metadata) && !empty($metadata['sizes'])) {
            $base = trailingslashit(dirname($urls[0]));
            foreach ($metadata['sizes'] as $size) { if (!empty($size['file'])) { $urls[] = $base . $size['file']; } }
        }
        foreach ($urls as $url) {
            if ($url && strtok($src, '?#') === strtok($url, '?#')) { return true; }
        }
        return false;
    }
    /** Classify each IMG in document order without rewriting block serialization. */
    private static function contexts($blocks, &$out) {
        foreach ($blocks as $block) {
            $allowed = in_array($block['blockName'], array(null, 'core/html', 'core/image', 'core/gallery'), true);
            $child = 0;
            foreach ($block['innerContent'] as $chunk) {
                if ($chunk === null) { self::contexts(array($block['innerBlocks'][$child++]), $out); continue; }
                $p = new WP_HTML_Tag_Processor($chunk);
                while ($p->next_tag(array('tag_name' => 'IMG'))) { $out[] = array('allowed' => $allowed, 'block' => $block['blockName'] ?: 'Classic HTML'); }
            }
        }
    }
    public static function occurrences($content, $id) {
        if (!class_exists('WP_HTML_Tag_Processor')) { return self::error('Saved-content review needs WordPress 6.2 or newer. Media Library editing remains available.'); }
        $contexts = array(); self::contexts(parse_blocks($content), $contexts);
        $p = new WP_HTML_Tag_Processor($content); $out = array(); $ordinal = 0;
        while ($p->next_tag(array('tag_name' => 'IMG'))) {
            if (self::matches($p, $id)) { $out[] = array('ordinal' => $ordinal, 'alt' => $p->get_attribute('alt'), 'src' => $p->get_attribute('src'), 'allowed' => $contexts[$ordinal]['allowed'] ?? false, 'block' => $contexts[$ordinal]['block'] ?? 'Unknown'); }
            $ordinal++;
        }
        return $out;
    }
    public static function usage($id, $page = 1) {
        if (is_wp_error(self::state($id))) { return self::error('Image is unavailable.'); }
        global $wpdb;
        $url = wp_get_attachment_url($id);
        $offset = max(0, (int) $page - 1) * 20;
        $posts = $wpdb->get_results($wpdb->prepare("SELECT p.ID,p.post_title,p.post_content FROM {$wpdb->posts} p WHERE p.post_type NOT IN ('attachment','revision','nav_menu_item') AND p.post_status NOT IN ('trash','auto-draft') AND (p.post_content LIKE %s OR p.post_content LIKE %s OR p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id' AND meta_value=%s) OR p.ID=%d) ORDER BY p.ID DESC LIMIT 21 OFFSET %d", '%' . $wpdb->esc_like('wp-image-' . $id) . '%', '%' . $wpdb->esc_like((string) $url) . '%', (string) $id, wp_get_post_parent_id($id), $offset));
        $more = count($posts) > 20; $posts = array_slice($posts, 0, 20); $rows = array();
        foreach ($posts as $post) {
            if (!current_user_can('edit_post', $post->ID)) { continue; }
            $occurrences = self::occurrences($post->post_content, $id);
            $rows[] = array('id' => (int) $post->ID, 'title' => $post->post_title, 'edit' => get_edit_post_link($post->ID, 'raw'), 'hash' => hash('sha256', $post->post_content), 'occurrences' => is_wp_error($occurrences) ? array() : $occurrences, 'dynamic' => (int) get_post_thumbnail_id($post->ID) === $id || (int) wp_get_post_parent_id($id) === (int) $post->ID);
        }
        return array('rows' => $rows, 'more' => $more, 'page' => (int) $page);
    }
    public static function preview_content($post_id, $attachment, $hash, $ordinals, $alt) {
        if (!self::allowed() || !current_user_can('unfiltered_html')) { return self::error('Saved-content changes require an administrator with unfiltered HTML permission.'); }
        if (is_wp_error(self::state($attachment)) || !$ordinals || count($ordinals) > 50) { return self::error('Select image occurrences to change.'); }
        $post = get_post($post_id); $alt = self::clean($alt);
        if (!$post || !current_user_can('edit_post', $post_id) || in_array($post->post_type, array('attachment','revision'), true) || is_wp_error($alt)) { return self::error('Post or alt text is unavailable.'); }
        if (strlen($post->post_content) > 131072 || !hash_equals(hash('sha256', $post->post_content), (string) $hash)) { return self::error('Content changed or exceeds the 128 KiB review limit. Reload usage or edit it in WordPress.'); }
        $occurrences = self::occurrences($post->post_content, $attachment);
        if (is_wp_error($occurrences)) { return $occurrences; }
        $allowed = array(); foreach ($occurrences as $item) { if ($item['allowed']) { $allowed[$item['ordinal']] = $item; } }
        $selected = array_unique(array_map('absint', $ordinals)); $rows = array();
        foreach ($selected as $ordinal) {
            if (!isset($allowed[$ordinal])) { return self::error('An occurrence is not safely editable. Use the WordPress block editor for this block.'); }
            $rows[] = array('id' => (int) $post_id, 'title' => $post->post_title . ' — image ' . ($ordinal + 1), 'before' => $allowed[$ordinal]['alt'], 'after' => $alt, 'status' => 'Ready');
        }
        $p = new WP_HTML_Tag_Processor($post->post_content); $ordinal = 0;
        while ($p->next_tag(array('tag_name' => 'IMG'))) { if (in_array($ordinal, $selected, true)) { $p->set_attribute('alt', $alt); } $ordinal++; }
        $after = $p->get_updated_html();
        if ($after === $post->post_content) { return self::error('Selected content is unchanged.'); }
        return self::preview('content', array(array('id' => (int) $post_id, 'before' => $post->post_content, 'after' => $after)), $rows);
    }
    public static function ajax() {
        if (!self::allowed() || !check_ajax_referer('piratez_io_alt', 'nonce', false)) { wp_send_json_error(array('message' => 'Permission denied or nonce expired.'), 403); }
        $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        $text = function ($key) { return isset($_POST[$key]) && is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : ''; };
        if ($operation === 'preview') {
            $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_filter($_POST['ids'], 'is_scalar') : array();
            $edits = isset($_POST['edits']) && is_array($_POST['edits']) ? wp_unslash($_POST['edits']) : array();
            $result = self::preview_metadata($ids, sanitize_key($text('mode')), $edits, $text('overwrite') === '1');
        } elseif ($operation === 'commit') { $result = self::commit($text('token')); }
        elseif ($operation === 'undo') { $result = self::undo($text('batch')); }
        elseif ($operation === 'usage') { $result = self::usage(absint($text('id')), max(1, absint($text('page')))); }
        elseif ($operation === 'content_preview') { $result = self::preview_content(absint($text('post_id')), absint($text('attachment')), $text('hash'), isset($_POST['ordinals']) && is_array($_POST['ordinals']) ? array_filter($_POST['ordinals'], 'is_scalar') : array(), $text('alt')); }
        else { $result = self::error('Unknown alt-text operation.'); }
        if (is_wp_error($result)) { wp_send_json_error(array('message' => $result->get_error_message()), 400); }
        wp_send_json_success($result);
    }
}
