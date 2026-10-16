<?php
/** Admin-only metadata audit and explicitly approved saved-content review. */
if (!defined('ABSPATH')) { exit; }
if (!Piratez_IO_Alt_Text::allowed()) { wp_die('Permission denied.', '', array('response' => 403)); }
$filter = isset($_GET['filter']) && is_string($_GET['filter']) ? sanitize_key(wp_unslash($_GET['filter'])) : 'missing';
if (!in_array($filter, array('all','missing','with','decorative'), true)) { $filter = 'missing'; }
$search = isset($_GET['s']) && is_string($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
$page = isset($_GET['alt_page']) && is_scalar($_GET['alt_page']) ? max(1, absint($_GET['alt_page'])) : 1;
$order = isset($_GET['order']) && $_GET['order'] === 'title' ? 'title' : 'date';
$images = Piratez_IO_Alt_Text::query($filter, $search, $page, $order);
$counts = Piratez_IO_Alt_Text::counts();
$missing = max(0, (int) $counts['total'] - (int) $counts['with_alt'] - (int) $counts['decorative']);
?>
<div class="wrap piratez-alt">
<h1>Image alt text</h1>
<noscript><p>JavaScript is required to preview, confirm and undo changes. You can still edit individual images through the Media Library.</p></noscript>
<p>Review Media Library descriptions. Empty alt text can be intentional: use the decorative marker for images that do not convey information. Suggestions need human review.</p>
<p class="piratez-alt-counts"><strong><?php echo (int) $counts['total']; ?></strong> images · <strong><?php echo (int) $counts['with_alt']; ?></strong> with alt text · <strong><?php echo $missing; ?></strong> unmarked empty · <strong><?php echo (int) $counts['decorative']; ?></strong> marked decorative</p>
<p class="description">Edits change attachment metadata only. Saved Gutenberg/HTML image alt attributes are reviewed separately through “Usage / content review”. Nothing is filled automatically on upload or page views.</p>
<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="piratez-alt-filter">
<input type="hidden" name="page" value="piratez-image-optimize"><input type="hidden" name="tab" value="alt">
<label>Show <select name="filter"><?php foreach (array('missing'=>'Unmarked empty','with'=>'With alt text','decorative'=>'Decorative','all'=>'All images') as $value=>$label) { echo '<option value="' . esc_attr($value) . '" ' . selected($filter, $value, false) . '>' . esc_html($label) . '</option>'; } ?></select></label>
<label>Search <input type="search" name="s" value="<?php echo esc_attr($search); ?>"></label>
<label>Sort <select name="order"><option value="date" <?php selected($order, 'date'); ?>>Newest</option><option value="title" <?php selected($order, 'title'); ?>>Title A–Z</option></select></label>
<button class="button" type="submit">Filter</button>
</form>
<form id="piratez-alt-form">
<div class="piratez-alt-actions">
<label>Selected images <select name="mode"><option value="edit">Use edited text</option><option value="filename">Suggest from filename</option><option value="attachment_title">Suggest from image title</option><option value="parent_title">Suggest from attached post title</option><option value="decorative">Mark decorative (empty alt)</option></select></label>
<label><input type="checkbox" name="overwrite" value="1"> Allow replacing existing text / decorative markers</label>
<button type="submit" class="button button-primary">Preview selected changes</button>
</div>
<div class="piratez-alt-table"><table class="widefat striped"><thead><tr><td><input type="checkbox" id="piratez-alt-select-all" aria-label="Select images on this page"></td><th>Image</th><th>Title / context</th><th>Media Library alt text</th><th>Review</th></tr></thead><tbody>
<?php foreach ($images->posts as $image) : $id = (int) $image->ID; $state = Piratez_IO_Alt_Text::state($id); $editable = !is_wp_error($state); $alt = $editable ? $state[Piratez_IO_Alt_Text::ALT]['value'] : ''; $decorative = $editable && $state[Piratez_IO_Alt_Text::DECORATIVE]['value'] === '1'; ?>
<tr data-attachment="<?php echo $id; ?>"><td><input type="checkbox" name="ids[]" value="<?php echo $id; ?>" aria-label="<?php echo esc_attr('Select ' . $image->post_title); ?>" <?php disabled(!$editable); ?>></td>
<td><?php echo wp_get_attachment_image($id, array(80,80), false, array('alt'=>'')); ?></td>
<td><a href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php echo esc_html($image->post_title ?: '(Untitled image)'); ?></a><br><code><?php echo esc_html(wp_basename(get_attached_file($id))); ?></code><?php if ($image->post_parent && current_user_can('edit_post', $image->post_parent)) { echo '<p>Attached to: <a href="' . esc_url(get_edit_post_link($image->post_parent)) . '">' . esc_html(get_the_title($image->post_parent)) . '</a></p>'; } ?></td>
<td><label class="screen-reader-text" for="piratez-alt-<?php echo $id; ?>">Alt text for <?php echo esc_html($image->post_title); ?></label><textarea id="piratez-alt-<?php echo $id; ?>" name="edits[<?php echo $id; ?>]" rows="3" maxlength="1000" <?php disabled(!$editable); ?>><?php echo esc_textarea($alt); ?></textarea><?php if ($decorative) { echo '<strong>' . ($alt !== '' ? 'Decorative marker conflicts with non-empty text — review this image' : 'Marked decorative') . '</strong>'; } if (!$editable) { echo '<p>' . esc_html($state->get_error_message()) . '</p>'; } ?></td>
<td><button class="button piratez-alt-usage" type="button" data-id="<?php echo $id; ?>">Usage / content review</button></td></tr>
<?php endforeach; if (!$images->posts) { echo '<tr><td colspan="5">No images match this view.</td></tr>'; } ?>
</tbody></table></div>
</form>
<?php echo '<nav class="tablenav-pages" aria-label="Image pages">' . wp_kses_post(paginate_links(array('base'=>add_query_arg('alt_page','%#%'), 'format'=>'','current'=>$page,'total'=>$images->max_num_pages))) . '</nav>'; ?>
<div id="piratez-alt-message" role="status" aria-live="polite"></div>
<section id="piratez-alt-preview" hidden tabindex="-1" aria-labelledby="piratez-alt-preview-title"><h2 id="piratez-alt-preview-title">Review proposed changes</h2><p>No changes have been saved yet. Confirm only descriptions that fit the images and their context.</p><div class="piratez-alt-preview-rows"></div><button type="button" class="button button-primary" id="piratez-alt-confirm">Confirm changes</button> <button type="button" class="button" id="piratez-alt-cancel">Cancel preview</button></section>
<?php if (!class_exists('WP_HTML_Tag_Processor')) { echo '<p>Saved-content review requires WordPress 6.2 or newer. Media Library alt-text editing remains available.</p>'; } ?>
<section id="piratez-alt-usage" hidden tabindex="-1" aria-labelledby="piratez-alt-usage-title"><h2 id="piratez-alt-usage-title">Saved image usage</h2><p>Searches saved image markup, featured-image links and the attachment parent. Dynamic templates, shortcodes and other plugins may use an image elsewhere. Only select exact occurrences you want to rewrite; core image/gallery, Custom HTML and classic HTML are supported. Other blocks stay under WordPress editor control.</p><div class="piratez-alt-usage-rows"></div></section>
<h2>Recent changes and undo</h2><p>Undo checks the saved state and skips later edits. History is private to your administrator account, limited to 20 batches and roughly 1 MiB; older entries expire as new ones are recorded.</p>
<ul id="piratez-alt-history"><?php foreach (Piratez_IO_Alt_Text::history() as $batch) { echo '<li>' . esc_html(wp_date('Y-m-d H:i', $batch['time'])) . ' — ' . esc_html($batch['kind']) . ' — ' . count($batch['changes']) . ' item(s) '; if (empty($batch['undone'])) { echo '<button type="button" class="button piratez-alt-undo" data-batch="' . esc_attr($batch['id']) . '">Undo unchanged entries</button>'; } else { echo '(Undo reviewed)'; } echo '</li>'; } ?></ul>
</div>
