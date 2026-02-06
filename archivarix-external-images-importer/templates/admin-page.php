<?php
/**
 * Admin page template
 *
 * @package Archivarix External Images Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap aeii-admin-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	
	<div class="aeii-admin-container">
		<nav class="nav-tab-wrapper">
			<a href="#settings" class="nav-tab nav-tab-active"><?php esc_html_e( 'Settings', 'archivarix-external-images-importer' ); ?></a>
			<a href="#process" class="nav-tab"><?php esc_html_e( 'Process', 'archivarix-external-images-importer' ); ?></a>
			<a href="#statistics" class="nav-tab"><?php esc_html_e( 'Statistics', 'archivarix-external-images-importer' ); ?></a>
		</nav>
		
		<div id="settings" class="aeii-tab-content active">
			<form method="post" action="options.php">
				<?php settings_fields( 'aeii_settings' ); ?>
				
				<h2><?php esc_html_e( 'Download Settings', 'archivarix-external-images-importer' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="download_mode"><?php esc_html_e( 'Download Mode', 'archivarix-external-images-importer' ); ?></label></th>
						<td>
							<select name="aeii_options[download_mode]" id="download_mode">
								<option value="original" <?php selected( $options['download_mode'], 'original' ); ?>><?php esc_html_e( 'Original URL only', 'archivarix-external-images-importer' ); ?></option>
								<option value="archive" <?php selected( $options['download_mode'], 'archive' ); ?>><?php esc_html_e( 'Web Archive only', 'archivarix-external-images-importer' ); ?></option>
								<option value="original_then_archive" <?php selected( $options['download_mode'], 'original_then_archive' ); ?>><?php esc_html_e( 'Try original, then Web Archive', 'archivarix-external-images-importer' ); ?></option>
								<option value="archive_then_original" <?php selected( $options['download_mode'], 'archive_then_original' ); ?>><?php esc_html_e( 'Try Web Archive, then original', 'archivarix-external-images-importer' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Restore Local Missing', 'archivarix-external-images-importer' ); ?></th>
						<td>
							<label><input type="checkbox" name="aeii_options[restore_local]" value="1" <?php checked( $options['restore_local'], true ); ?>>
							<?php esc_html_e( 'Include local images (from this domain) that return 404', 'archivarix-external-images-importer' ); ?></label>
							<p class="description">
								<?php esc_html_e( 'Keep checked if you want to restore ALL missing images, including those that were previously hosted on this same domain. Uncheck if you only want to import external images from other websites and skip broken local images.', 'archivarix-external-images-importer' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="missing_action"><?php esc_html_e( 'If Download Fails', 'archivarix-external-images-importer' ); ?></label></th>
						<td>
							<select name="aeii_options[missing_action]" id="missing_action">
								<option value="keep" <?php selected( $options['missing_action'], 'keep' ); ?>><?php esc_html_e( 'Keep image unchanged', 'archivarix-external-images-importer' ); ?></option>
								<option value="remove" <?php selected( $options['missing_action'], 'remove' ); ?>><?php esc_html_e( 'Remove image from post', 'archivarix-external-images-importer' ); ?></option>
								<option value="placeholder" <?php selected( $options['missing_action'], 'placeholder' ); ?>><?php esc_html_e( 'Replace with 1x1 placeholder', 'archivarix-external-images-importer' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Post Types', 'archivarix-external-images-importer' ); ?></th>
						<td>
							<?php
							$post_types     = get_post_types( array( 'public' => true ), 'objects' );
							$selected_types = isset( $options['post_types'] ) ? (array) $options['post_types'] : array();

							// If no selection saved, select all by default (except attachment).
							if ( empty( $selected_types ) ) {
								$selected_types = array_values(
									array_diff(
										array_keys( $post_types ),
										array( 'attachment' )
									)
								);
							}

							foreach ( $post_types as $post_type_obj ) :
								if ( 'attachment' === $post_type_obj->name ) {
									continue;
								}
								?>
								<label style="display: block; margin-bottom: 5px;">
									<input type="checkbox" name="aeii_options[post_types][]" value="<?php echo esc_attr( $post_type_obj->name ); ?>" <?php checked( in_array( $post_type_obj->name, $selected_types, true ) ); ?>>
									<?php echo esc_html( $post_type_obj->labels->name ); ?> <code><?php echo esc_html( $post_type_obj->name ); ?></code>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Select which post types to scan for external images.', 'archivarix-external-images-importer' ); ?></p>
						</td>
					</tr>
				</table>
				
				<h2><?php esc_html_e( 'Naming Settings', 'archivarix-external-images-importer' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Keep Original Name', 'archivarix-external-images-importer' ); ?></th>
						<td>
							<label><input type="checkbox" name="aeii_options[keep_original_name]" value="1" <?php checked( $options['keep_original_name'], true ); ?> id="keep_original_name"> <?php esc_html_e( 'Keep original filename', 'archivarix-external-images-importer' ); ?></label>
							<p class="description"><?php esc_html_e( 'Checked: use the original filename from the URL. Unchecked: rename files using the pattern below.', 'archivarix-external-images-importer' ); ?></p>
						</td>
					</tr>
					<tr class="aeii-rename-pattern-row">
						<th><label for="rename_pattern"><?php esc_html_e( 'Filename Pattern', 'archivarix-external-images-importer' ); ?></label></th>
						<td>
							<input type="text" name="aeii_options[rename_pattern]" id="rename_pattern" value="<?php echo esc_attr( $options['rename_pattern'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Tags: {title} {post_id} {index} {date} {random}', 'archivarix-external-images-importer' ); ?></p>
						</td>
					</tr>
				</table>
				
				<h2><?php esc_html_e( 'Connection Settings', 'archivarix-external-images-importer' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="download_delay"><?php esc_html_e( 'Download Delay', 'archivarix-external-images-importer' ); ?></label></th>
						<td><input type="number" name="aeii_options[download_delay]" id="download_delay" value="<?php echo esc_attr( $options['download_delay'] ); ?>" min="0" max="60" class="small-text"> <?php esc_html_e( 'seconds', 'archivarix-external-images-importer' ); ?>
							<p class="description"><?php esc_html_e( 'Delay between requests.', 'archivarix-external-images-importer' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="timeout"><?php esc_html_e( 'Timeout', 'archivarix-external-images-importer' ); ?></label></th>
						<td><input type="number" name="aeii_options[timeout]" id="timeout" value="<?php echo esc_attr( $options['timeout'] ); ?>" min="5" max="300" class="small-text"> <?php esc_html_e( 'seconds', 'archivarix-external-images-importer' ); ?>
							<p class="description"><?php esc_html_e( 'Request timeout. Increase if downloads fail on slow connections.', 'archivarix-external-images-importer' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="user_agent"><?php esc_html_e( 'User Agent', 'archivarix-external-images-importer' ); ?></label></th>
						<td><input type="text" name="aeii_options[user_agent]" id="user_agent" value="<?php echo esc_attr( $options['user_agent'] ); ?>" class="large-text"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			
			<div class="aeii-archivarix-promo">
				<a href="https://archivarix.com" target="_blank" class="aeii-promo-logo">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 76 76" width="36" height="36">
						<circle fill="#ffa700" cx="38" cy="38" r="37"/>
						<path fill="#fff" d="M23.4 19.1c1.9-.8 3.7-1.2 5.4-1.2 1.4 0 2.9.5 4.5 1.6.8.6 1.8 1.7 2.8 3.4.7 1.2 1.6 3.3 2.6 6.3l5.3 15c1.2 3.4 2.5 6 3.7 8 1.3 2 2.4 3.5 3.4 4.5s2.1 1.7 3.3 2.1c1.1.4 2.1.6 2.8.6s1.4-.1 2-.2v.4c-1.4.5-2.7.7-4.1.7-1.3 0-2.7-.3-4-1-1.3-.7-2.6-1.6-3.7-2.8C45 54 42.9 50.1 41.2 45l-1.7-4.9H27.6l-3 7.7c-.1.3-.2.7-.2 1 0 .3.2.7.5 1.1s.8.6 1.4.6h.3v.4h-8.7v-.4h.4c.7 0 1.4-.2 2-.6.7-.4 1.2-1 1.6-1.9l10.8-25.8c-1.6-2.2-3.5-3.3-5.8-3.3-1 0-2.2.2-3.3.7l-.2-.5zm4.7 19.6h11l-3.4-10.1c-.7-1.9-1.3-3.5-1.8-4.6l-5.8 14.7z"/>
					</svg>
					<span class="aeii-promo-logo-text">ARCHIVARIX</span>
				</a>
				<div class="aeii-promo-content">
					<p class="aeii-promo-text">
						<?php echo wp_kses( __( 'Restore websites from the <strong>Wayback Machine</strong> with high accuracy. Download archived sites, remove ads and trackers, optimize images, and get a fully functional copy powered by the free <strong>Archivarix CMS</strong>. Trusted by thousands of users worldwide since 2017 for website recovery, migration, and SEO projects.', 'archivarix-external-images-importer' ), array( 'strong' => array() ) ); ?>
					</p>
				</div>
				<div class="aeii-promo-links">
					<a href="https://archivarix.com" target="_blank" class="button button-primary"><?php esc_html_e( 'Visit Website', 'archivarix-external-images-importer' ); ?></a>
					<a href="https://archivarix.com/en/wordpress/" target="_blank" class="button"><?php esc_html_e( 'Documentation', 'archivarix-external-images-importer' ); ?></a>
				</div>
			</div>
		</div>
		
		<div id="process" class="aeii-tab-content">
			<h2><?php esc_html_e( 'Process Images', 'archivarix-external-images-importer' ); ?></h2>
			<div class="aeii-step">
				<h3><?php esc_html_e( 'Step 1: Scan', 'archivarix-external-images-importer' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Scan all posts and pages to find external images and missing local images.', 'archivarix-external-images-importer' ); ?></p>
				<button type="button" class="button button-primary" id="aeii-scan-btn"><?php esc_html_e( 'Start Scan', 'archivarix-external-images-importer' ); ?></button>
				<div id="aeii-scan-results" class="aeii-results"></div>
			</div>
			<div class="aeii-step">
				<h3><?php esc_html_e( 'Step 2: Process', 'archivarix-external-images-importer' ); ?></h3>
				<div class="aeii-process-options">
					<div class="aeii-process-option">
						<button type="button" class="button button-primary" id="aeii-process-btn" disabled><?php esc_html_e( 'Process', 'archivarix-external-images-importer' ); ?></button>
						<p class="description">
							<?php esc_html_e( 'You can close this page — processing continues on the server.', 'archivarix-external-images-importer' ); ?>
						</p>
					</div>
				</div>
				<div class="aeii-background-status" style="display:<?php echo esc_attr( $is_background_running ? 'block' : 'none' ); ?>;">
					<span class="aeii-status-indicator running"></span> <?php esc_html_e( 'Background processing running...', 'archivarix-external-images-importer' ); ?>
					<button type="button" class="button button-small aeii-stop-btn" id="aeii-stop-background-btn"><?php esc_html_e( 'Stop', 'archivarix-external-images-importer' ); ?></button>
				</div>
				<div class="aeii-progress-container" style="display:none;">
					<div class="aeii-progress-bar"><div class="aeii-progress-fill"></div></div>
					<div class="aeii-progress-text"></div>
				</div>
			</div>
		</div>
		
		<div id="statistics" class="aeii-tab-content">
			<h2><?php esc_html_e( 'Import Statistics', 'archivarix-external-images-importer' ); ?></h2>
			<div id="aeii-archive-error" class="aeii-archive-error" style="display:none;"></div>
			<div class="aeii-stats-grid">
				<div class="aeii-stat-card success"><div class="aeii-stat-value" id="stat-success"><?php echo esc_html( $statistics['success'] ); ?></div><div class="aeii-stat-label"><?php esc_html_e( 'New Downloads', 'archivarix-external-images-importer' ); ?></div></div>
				<div class="aeii-stat-card cached"><div class="aeii-stat-value" id="stat-cached"><?php echo esc_html( $statistics['cached'] ); ?></div><div class="aeii-stat-label"><?php esc_html_e( 'Cached/Existing', 'archivarix-external-images-importer' ); ?></div></div>
				<div class="aeii-stat-card failed"><div class="aeii-stat-value" id="stat-failed"><?php echo esc_html( $statistics['failed'] ); ?></div><div class="aeii-stat-label"><?php esc_html_e( 'Failed', 'archivarix-external-images-importer' ); ?></div></div>
				<div class="aeii-stat-card removed"><div class="aeii-stat-value" id="stat-removed"><?php echo esc_html( $statistics['removed'] ); ?></div><div class="aeii-stat-label"><?php esc_html_e( 'Removed', 'archivarix-external-images-importer' ); ?></div></div>
				<div class="aeii-stat-card placeholder"><div class="aeii-stat-value" id="stat-placeholder"><?php echo esc_html( $statistics['placeholder'] ); ?></div><div class="aeii-stat-label"><?php esc_html_e( 'Placeholder', 'archivarix-external-images-importer' ); ?></div></div>
			</div>
			<p><button type="button" class="button" id="aeii-reset-stats-btn"><?php esc_html_e( 'Reset Statistics', 'archivarix-external-images-importer' ); ?></button></p>
			
			<h2><?php esc_html_e( 'Import Logs', 'archivarix-external-images-importer' ); ?></h2>
			<div class="aeii-logs-toolbar">
				<label><?php esc_html_e( 'Sort:', 'archivarix-external-images-importer' ); ?> <select id="aeii-logs-sort"><option value="desc"><?php esc_html_e( 'Newest first', 'archivarix-external-images-importer' ); ?></option><option value="asc"><?php esc_html_e( 'Oldest first', 'archivarix-external-images-importer' ); ?></option></select></label>
				<button type="button" class="button" id="aeii-refresh-logs-btn"><?php esc_html_e( 'Refresh', 'archivarix-external-images-importer' ); ?></button>
				<button type="button" class="button" id="aeii-download-logs-btn"><?php esc_html_e( 'Download Logs', 'archivarix-external-images-importer' ); ?></button>
				<button type="button" class="button" id="aeii-delete-selected-logs-btn" disabled><?php esc_html_e( 'Delete Selected', 'archivarix-external-images-importer' ); ?></button>
				<button type="button" class="button" id="aeii-delete-all-logs-btn"><?php esc_html_e( 'Delete All', 'archivarix-external-images-importer' ); ?></button>
			</div>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><td class="check-column"><input type="checkbox" id="aeii-logs-select-all"></td><th><?php esc_html_e( 'Date', 'archivarix-external-images-importer' ); ?></th><th><?php esc_html_e( 'Post', 'archivarix-external-images-importer' ); ?></th><th><?php esc_html_e( 'Image Origin', 'archivarix-external-images-importer' ); ?></th><th><?php esc_html_e( 'Downloaded', 'archivarix-external-images-importer' ); ?></th><th><?php esc_html_e( 'Result', 'archivarix-external-images-importer' ); ?></th><th><?php esc_html_e( 'Source/Action', 'archivarix-external-images-importer' ); ?></th></tr></thead>
				<tbody id="aeii-logs-body"><tr><td colspan="7"><?php esc_html_e( 'Click Refresh to load logs', 'archivarix-external-images-importer' ); ?></td></tr></tbody>
			</table>
			<div class="aeii-logs-pagination" id="aeii-logs-pagination"></div>
		</div>
	</div>
</div>
