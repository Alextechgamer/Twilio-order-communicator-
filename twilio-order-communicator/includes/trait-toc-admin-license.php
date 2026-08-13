<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * License tab UI.
 */
trait TOC_Admin_License {

	private function render_license() {
		$license = TOC_License::instance();
		$state   = $license->ui_state();
		$status  = $state['status'];
		?>
		<h2><?php echo esc_html__( 'License', 'twilio-order-communicator' ); ?></h2>
		<p class="description">
			<?php echo esc_html__( 'New installs include a 30-day trial. After that, a license key unlocks premium plugin updates. SMS, voice, chat, and auto-notify always use your own Twilio account and keep working without a key.', 'twilio-order-communicator' ); ?>
		</p>

		<?php if ( ! $state['server_configured'] ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php echo esc_html__( 'No license server URL is configured yet. Set TOC_LICENSE_SERVER_URL in wp-config.php (recommended) or save a server URL below.', 'twilio-order-communicator' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<table class="form-table" role="presentation" id="toc-license-panel" data-status="<?php echo esc_attr( $status ); ?>">
			<tr>
				<th><?php echo esc_html__( 'Status', 'twilio-order-communicator' ); ?></th>
				<td>
					<span class="toc-license-status toc-license-status--<?php echo esc_attr( $status ); ?>" id="toc-license-status-label">
						<?php echo esc_html( $state['status_label'] ); ?>
					</span>
					<?php if ( $state['allows_updates'] ) : ?>
						<span class="description" id="toc-license-updates-note"> — <?php echo esc_html__( 'premium updates enabled', 'twilio-order-communicator' ); ?></span>
					<?php else : ?>
						<span class="description" id="toc-license-updates-note"> — <?php echo esc_html__( 'premium updates paused', 'twilio-order-communicator' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="toc-license-key"><?php echo esc_html__( 'License key', 'twilio-order-communicator' ); ?></label></th>
				<td>
					<input type="text" id="toc-license-key" class="regular-text" autocomplete="off" spellcheck="false"
						placeholder="<?php echo $state['has_key'] ? esc_attr( $state['masked_key'] ) : esc_attr( 'TOC-XXXX-XXXX-XXXX-XXXX' ); ?>"
						value="" />
					<p class="description">
						<?php
						if ( $state['has_key'] ) {
							echo esc_html(
								sprintf(
									/* translators: %s: masked license key */
									__( 'Saved key: %s — leave blank to keep it, or paste a new key to replace.', 'twilio-order-communicator' ),
									$state['masked_key']
								)
							);
						} else {
							echo esc_html__( 'Paste your license key, then Activate. The full key is never shown in the page after save.', 'twilio-order-communicator' );
						}
						?>
					</p>
				</td>
			</tr>
			<?php if ( ! defined( 'TOC_LICENSE_SERVER_URL' ) || ! TOC_LICENSE_SERVER_URL ) : ?>
				<tr>
					<th><label for="toc-license-server"><?php echo esc_html__( 'License server URL', 'twilio-order-communicator' ); ?></label></th>
					<td>
						<input type="url" id="toc-license-server" name="toc_license_server_url" class="regular-text"
							value="<?php echo esc_attr( get_option( 'toc_license_server_url', '' ) ); ?>"
							placeholder="https://licenses.example.com" />
						<p class="description"><?php echo esc_html__( 'Optional fallback when TOC_LICENSE_SERVER_URL is not defined. Prefer the wp-config constant in production.', 'twilio-order-communicator' ); ?></p>
						<p><button type="button" class="button" id="toc-license-save-server"><?php echo esc_html__( 'Save server URL', 'twilio-order-communicator' ); ?></button></p>
					</td>
				</tr>
			<?php else : ?>
				<tr>
					<th><?php echo esc_html__( 'License server', 'twilio-order-communicator' ); ?></th>
					<td><code><?php echo esc_html( TOC_License::instance()->server_url() ); ?></code> <span class="description">(TOC_LICENSE_SERVER_URL)</span></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th><?php echo esc_html__( 'Details', 'twilio-order-communicator' ); ?></th>
				<td>
					<ul class="toc-license-meta" id="toc-license-meta">
						<li><?php echo esc_html__( 'Licensed site:', 'twilio-order-communicator' ); ?> <strong id="toc-lic-site"><?php echo esc_html( $state['site_url'] ?: '—' ); ?></strong></li>
						<li><?php echo esc_html__( 'Activations:', 'twilio-order-communicator' ); ?>
							<strong id="toc-lic-acts">
								<?php
								if ( $state['activations'] !== null && $state['max_sites'] !== null ) {
									echo esc_html( $state['activations'] . ' / ' . $state['max_sites'] );
								} else {
									echo '—';
								}
								?>
							</strong>
						</li>
						<li><?php echo esc_html__( 'Expires:', 'twilio-order-communicator' ); ?>
							<strong id="toc-lic-exp"><?php echo esc_html( $state['expires_at'] !== '' && $state['expires_at'] !== null ? $state['expires_at'] : __( 'Lifetime / none set', 'twilio-order-communicator' ) ); ?></strong>
						</li>
						<li><?php echo esc_html__( 'Customer:', 'twilio-order-communicator' ); ?> <strong id="toc-lic-email"><?php echo esc_html( $state['customer_email'] !== '' ? $state['customer_email'] : '—' ); ?></strong></li>
						<li><?php echo esc_html__( 'Last check:', 'twilio-order-communicator' ); ?> <strong id="toc-lic-check"><?php echo esc_html( $state['last_check'] !== '' ? $state['last_check'] : '—' ); ?></strong></li>
						<?php if ( ! empty( $state['on_trial'] ) || ( ! empty( $state['trial_ends_at'] ) && empty( $state['has_key'] ) ) ) : ?>
						<li><?php echo esc_html__( 'Trial ends:', 'twilio-order-communicator' ); ?> <strong><?php echo esc_html( $state['trial_ends_at'] !== '' ? $state['trial_ends_at'] : '—' ); ?></strong></li>
						<?php endif; ?>
						<li><?php echo esc_html__( 'Instance ID:', 'twilio-order-communicator' ); ?> <code id="toc-lic-instance"><?php echo esc_html( $state['instance_id'] ); ?></code></li>
					</ul>
				</td>
			</tr>
		</table>

		<p class="toc-license-actions">
			<button type="button" class="button button-primary" id="toc-license-activate"><?php echo esc_html__( 'Activate', 'twilio-order-communicator' ); ?></button>
			<button type="button" class="button" id="toc-license-refresh"><?php echo esc_html__( 'Re-check', 'twilio-order-communicator' ); ?></button>
			<button type="button" class="button" id="toc-license-deactivate"><?php echo esc_html__( 'Deactivate', 'twilio-order-communicator' ); ?></button>
			<label style="margin-left:12px;">
				<input type="checkbox" id="toc-license-clear-key" value="1" />
				<?php echo esc_html__( 'Also clear saved key on deactivate', 'twilio-order-communicator' ); ?>
			</label>
			<span id="toc-license-msg" style="margin-left:12px;"></span>
		</p>
		<?php
	}
}
