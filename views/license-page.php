<?php
/**
 * PezWeb license admin view (styles/scripts enqueued from the main plugin).
 */

if (! defined("ABSPATH")) {
	exit();
}

$d = $this->get_text_domain();

$license_key = get_option("pzw_license_key", "");
$is_active = $this->is_valid();
$license_info = $this->get_license_info();
?>

<div class="wrap pzw-license-page">
	<h1>
		<?php
		echo esc_html($this->get_plugin_name());
		echo " — ";
		esc_html_e("License", $d);
		?>
	</h1>

	<?php if ($is_active && $license_info) : ?>
		<div class="notice notice-success inline">
			<p><strong><?php esc_html_e("License active", $d); ?></strong></p>
		</div>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e("Status", $d); ?></th>
				<td>
					<span class="pzw-status-badge pzw-status-active"><?php esc_html_e(
						"Active",
						$d,
					); ?></span>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e("Valid until", $d); ?></th>
				<td>
					<?php
					echo esc_html(
						date_i18n(
							get_option("date_format") .
								" " .
								get_option("time_format"),
							$license_info["valid_until"],
						),
					);

					$days_until = (int) ceil(
						($license_info["valid_until"] - time()) / DAY_IN_SECONDS,
					);
					if ($days_until <= 30) {
						echo " ";
						printf(
							'<span class="pzw-warning">(%s)</span>',
							esc_html(
								sprintf(
									/* translators: %d: days until revalidation */
									_n(
										"Revalidation in %d day",
										"Revalidation in %d days",
										$days_until,
										$d,
									),
									$days_until,
								),
							),
						);
					}
					?>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="button" class="button button-secondary" id="pzw-deactivate-license">
				<?php esc_html_e("Deactivate license", $d); ?>
			</button>
			<button type="button" class="button button-secondary" id="pzw-check-license">
				<?php esc_html_e("Verify license", $d); ?>
			</button>
		</p>

	<?php else : ?>
		<div class="notice notice-warning inline">
			<p><strong><?php esc_html_e("No active license", $d); ?></strong></p>
		</div>

		<form method="post" id="pzw-license-form">
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="license_key"><?php esc_html_e(
							"License key",
							$d,
						); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="license_key"
							name="license_key"
							value="<?php echo esc_attr($license_key); ?>"
							class="regular-text"
							placeholder="pzw_live_xxxxxxxxxxxxxxxx"
						>
						<p class="description">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: account page URL */
									__(
										'Enter the key from <a href="%s" target="_blank" rel="noopener noreferrer">your account</a>.',
										$d,
									),
									esc_url("https://pezweb.com/mi-cuenta"),
								),
								[
									"a" => [
										"href" => [],
										"target" => [],
										"rel" => [],
									],
								],
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary" id="pzw-activate-license">
					<?php esc_html_e("Activate license", $d); ?>
				</button>
			</p>
		</form>

		<hr>

		<div class="pzw-help-section">
			<h2><?php esc_html_e("Need help?", $d); ?></h2>
			<ul>
				<li><a href="https://pezweb.com/docs" target="_blank" rel="noopener noreferrer"><?php esc_html_e(
					"Documentation",
					$d,
				); ?></a></li>
				<li><a href="https://pezweb.com/tienda" target="_blank" rel="noopener noreferrer"><?php esc_html_e(
					"Buy a license",
					$d,
				); ?></a></li>
				<li><a href="https://pezweb.com/soporte" target="_blank" rel="noopener noreferrer"><?php esc_html_e(
					"Support",
					$d,
				); ?></a></li>
			</ul>
		</div>
	<?php endif; ?>

	<div id="pzw-license-message" class="pzw-message" style="display:none;" hidden></div>
</div>
