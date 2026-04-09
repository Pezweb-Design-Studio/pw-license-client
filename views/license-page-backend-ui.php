<?php
/**
 * License admin view — pw/backend-ui (PezWeb License Client).
 *
 * @var \PW\BackendUI\Components\ComponentRenderer $ui
 * @var string                                     $domain       Text domain.
 * @var string                                     $license_key  Current key (masked flow server-side).
 * @var bool                                       $is_active    Whether license validates.
 * @var array|null                                 $license_info Cached license metadata when active.
 */

defined("ABSPATH") || exit();

$d = $domain;

if ($is_active && $license_info) {
	$ui->card([
		"title" => __("License status", $d),
		"description" => __("Your product license is active on this site.", $d),
		"padded" => true,
		"content" => function () use ($ui, $license_info, $d) {
			$ui->notice([
				"type" => "success",
				"message" => esc_html__(
					"License active. Thank you for your purchase.",
					$d,
				),
			]);
			echo '<div class="pzw-bui-license-status-row">';
			echo '<span class="pw-bui-label" style="margin:0;">' .
				esc_html__("Status", $d) .
				"</span>";
			$ui->badge([
				"label" => __("Active", $d),
				"variant" => "success",
				"size" => "md",
			]);
			echo "</div>";
			$date = date_i18n(
				get_option("date_format") . " " . get_option("time_format"),
				$license_info["valid_until"],
			);
			$ui->paragraph([
				"text" => esc_html__("Valid until", $d) . ": " . esc_html($date),
				"variant" => "muted",
				"class" => "pzw-bui-license-valid-until",
			]);
			$days_until = (int) ceil(
				($license_info["valid_until"] - time()) / DAY_IN_SECONDS,
			);
			if ($days_until <= 30) {
				$ui->paragraph([
					"text" => esc_html(
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
					"variant" => "default",
					"class" => "pzw-bui-license-renew-hint",
				]);
			}
		},
		"footer" => function () use ($ui, $d) {
			echo '<div class="pzw-bui-license-actions">';
			$ui->button([
				"label" => __("Deactivate license", $d),
				"type" => "button",
				"variant" => "secondary",
				"attrs" => ["id" => "pzw-deactivate-license"],
			]);
			$ui->button([
				"label" => __("Verify license", $d),
				"type" => "button",
				"variant" => "outline",
				"attrs" => ["id" => "pzw-check-license"],
			]);
			echo "</div>";
		},
	]);
} else {
	echo '<form id="pzw-license-form" method="post">';
	$ui->card([
		"title" => __("Activate your license", $d),
		"description" => __(
			"Enter the license key you received after purchase.",
			$d,
		),
		"padded" => true,
		"content" => function () use ($ui, $license_key, $d) {
			$ui->notice([
				"type" => "warning",
				"message" => esc_html__(
					"No active license on this site yet.",
					$d,
				),
			]);
			$ui->input([
				"name" => "license_key",
				"id" => "license_key",
				"label" => __("License key", $d),
				"value" => $license_key,
				"placeholder" => "pzw_live_xxxxxxxxxxxxxxxx",
			]);
			$account = sprintf(
				/* translators: %s: account URL */
				__(
					'Get your key in <a href="%s" target="_blank" rel="noopener noreferrer">your Pezweb account</a>.',
					$d,
				),
				esc_url("https://pezweb.com/mi-cuenta"),
			);
			$ui->paragraph([
				"text" => $account,
				"variant" => "muted",
				"class" => "pzw-bui-license-account-hint",
			]);
		},
		"footer" => function () use ($ui, $d) {
			$ui->button([
				"label" => __("Activate license", $d),
				"type" => "submit",
				"variant" => "primary",
				"attrs" => ["id" => "pzw-activate-license"],
			]);
		},
	]);
	echo "</form>";

	$ui->card([
		"title" => __("Need help?", $d),
		"padded" => true,
		"content" => function () use ($ui, $d) {
			$ui->paragraph([
				"text" =>
					'<a href="' .
					esc_url("https://pezweb.com/docs") .
					'" target="_blank" rel="noopener noreferrer">' .
					esc_html__("Documentation", $d) .
					"</a>",
			]);
			$ui->paragraph([
				"text" =>
					'<a href="' .
					esc_url("https://pezweb.com/tienda") .
					'" target="_blank" rel="noopener noreferrer">' .
					esc_html__("Buy a license", $d) .
					"</a>",
			]);
			$ui->paragraph([
				"text" =>
					'<a href="' .
					esc_url("https://pezweb.com/soporte") .
					'" target="_blank" rel="noopener noreferrer">' .
					esc_html__("Support", $d) .
					"</a>",
			]);
		},
	]);
}

echo '<div id="pzw-license-message" class="pzw-message pzw-license-message-bui" style="display:none;" hidden></div>';
