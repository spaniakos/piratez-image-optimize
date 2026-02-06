<?php
/**
 * Admin dashboard view.
 *
 * @package Piratez_IO
 * @var bool   $ready
 * @var array  $issues
 * @var bool   $processing_enabled
 * @var array  $state
 * @var array  $counts
 * @var array  $stats
 * @var array  $sizes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap piratez-io-admin">
	<h1><?php esc_html_e( 'Piratez Image Optimize', 'piratez-image-optimize' ); ?></h1>

	<section class="ssw-status ssw-section">
		<h2><?php esc_html_e( 'Status', 'piratez-image-optimize' ); ?></h2>
		<?php if ( $ready ) : ?>
			<p class="ssw-status-ready"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'READY', 'piratez-image-optimize' ); ?></p>
		<?php else : ?>
			<p class="ssw-status-blocked"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'BLOCKED', 'piratez-image-optimize' ); ?></p>
			<p><?php esc_html_e( 'The plugin cannot run until the following are fixed:', 'piratez-image-optimize' ); ?></p>
			<ul class="ssw-issues">
				<?php foreach ( $issues['hard'] as $item ) : ?>
					<li>
						<strong><?php echo esc_html( $item['message'] ); ?></strong>
						<?php if ( ! empty( $item['action'] ) ) : ?>
							<br><span class="ssw-action"><?php echo esc_html( $item['action'] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! empty( $issues['soft'] ) ) : ?>
				<p><?php esc_html_e( 'Optional (improves performance):', 'piratez-image-optimize' ); ?></p>
				<ul class="ssw-issues ssw-soft">
					<?php foreach ( $issues['soft'] as $item ) : ?>
						<li><?php echo esc_html( $item['message'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php endif; ?>
	</section>

	<?php if ( $ready ) : ?>
		<section class="ssw-dashboard ssw-section">
			<h2><?php esc_html_e( 'Dashboard', 'piratez-image-optimize' ); ?></h2>
			<div class="ssw-cards">
				<div class="ssw-card">
					<span class="ssw-value" id="ssw-mb-saved"><?php echo esc_html( number_format_i18n( $stats['mb_saved'], 1 ) ); ?></span>
					<span class="ssw-label"><?php esc_html_e( 'MB saved (total)', 'piratez-image-optimize' ); ?></span>
				</div>
				<div class="ssw-card">
					<span class="ssw-value" id="ssw-images-optimized"><?php echo esc_html( number_format_i18n( $stats['images_optimized'] ) ); ?></span>
					<span class="ssw-label"><?php esc_html_e( 'Images optimized', 'piratez-image-optimize' ); ?></span>
				</div>
				<div class="ssw-card">
					<span class="ssw-value" id="ssw-progress"><?php echo (int) $state['processed']; ?> / <?php echo (int) $state['total']; ?></span>
					<span class="ssw-label"><?php esc_html_e( 'Batch progress (processed / total attachments)', 'piratez-image-optimize' ); ?></span>
				</div>
				<div class="ssw-card">
					<span class="ssw-value" id="ssw-needing-work"><?php echo (int) $counts['needing_work']; ?></span>
					<span class="ssw-label"><?php esc_html_e( 'Images required optimization', 'piratez-image-optimize' ); ?></span>
				</div>
			</div>
		</section>

		<section class="ssw-actions ssw-section">
			<h2><?php esc_html_e( 'Actions', 'piratez-image-optimize' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ssw-inline-form">
				<input type="hidden" name="action" value="piratez_io_toggle_processing" />
				<?php wp_nonce_field( 'piratez_io_toggle', '_wpnonce' ); ?>
				<label>
					<input type="checkbox" name="processing_enabled" value="1" <?php checked( $processing_enabled ); ?> />
					<?php esc_html_e( 'Enable processing (batch and on-upload)', 'piratez-image-optimize' ); ?>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Save', 'piratez-image-optimize' ); ?></button>
			</form>
			<p class="ssw-batch-actions">
				<button type="button" class="button button-primary" id="ssw-start-batch" <?php echo $state['status'] === 'running' ? ' disabled' : ''; ?>><?php esc_html_e( 'Regenerate missing sizes + WebP', 'piratez-image-optimize' ); ?></button>
				<button type="button" class="button" id="ssw-pause-batch" <?php echo $state['status'] !== 'running' ? ' disabled' : ''; ?>><?php esc_html_e( 'Pause', 'piratez-image-optimize' ); ?></button>
				<span id="ssw-batch-message" class="ssw-message"></span>
			</p>
		</section>

		<section class="ssw-sizes ssw-section">
			<h2><?php esc_html_e( 'Registered image sizes', 'piratez-image-optimize' ); ?></h2>
			<p><code><?php echo esc_html( implode( ', ', $sizes ) ); ?></code></p>
		</section>
	<?php endif; ?>
</div>
