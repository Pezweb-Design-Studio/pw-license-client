<?php // pezweb-license-client/Client.php

namespace PezWeb\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cliente de Licenciamiento Pezweb
 *
 * Sistema simplificado de validación de licencias
 * El servidor controla toda la configuración
 *
 * Integración pw/backend-ui (opcional): si `use_backend_ui()` es true pero la
 * clase no está cargada, se usa la vista legacy (.wrap). El paquete no declara
 * `pw/backend-ui` como dependencia obligatoria en Composer; el plugin host
 * debe requerirlo si usa UI moderna, o ver composer.json del cliente (suggest).
 *
 * Textos: sobrescribe `get_text_domain()` con el text domain del plugin.
 *
 * @version 2.1.0
 */
class Client
{
	/**
	 * @var string Slug único del plugin (hardcoded en cada plugin)
	 */
	private $plugin_slug;

	/**
	 * @var string License key del usuario
	 */
	private $license_key;

	/**
	 * @var Cache Instancia del gestor de cache
	 */
	private $cache;

	/**
	 * @var API Instancia del gestor de API
	 */
	private $api;

	/**
	 * @var string URL del servidor (hardcoded)
	 */
	private const SERVER_URL = "https://pezweb.com";

	/**
	 * FQCN de pw/backend-ui (string) para class_exists sin forzar autoload raro con ::class.
	 */
	private const BACKEND_UI_CLASS = "PW\\BackendUI\\BackendUI";

	/**
	 * @var string Endpoint de validación único por plugin (hardcoded)
	 */
	private const VALIDATION_HASH = ""; // Debe ser definido por cada plugin

	/**
	 * @var string Secret para validar firma HMAC (hardcoded)
	 */
	private const HMAC_SECRET = ""; // Debe ser definido por cada plugin

	/**
	 * @var self Instancia singleton
	 */
	protected static $instance = null;

	/**
	 * Text domain for gettext. Override in the plugin subclass (e.g. return 'my-plugin').
	 */
	protected function get_text_domain(): string
	{
		return "pezweb-license";
	}

	/**
	 * When true and PW\BackendUI\BackendUI is loaded, the license screen uses BackendUI::render_page().
	 * Requires the host plugin to enqueue pw/backend-ui on the license admin screen.
	 */
	protected function use_backend_ui(): bool
	{
		return false;
	}

	/**
	 * Comprueba si pw/backend-ui está disponible (autoload Composer u otro).
	 */
	protected function is_backend_ui_available(): bool
	{
		return class_exists(self::BACKEND_UI_CLASS, true);
	}

	/**
	 * Inicializa el cliente de licencias
	 *
	 * @param array $config ['plugin_slug' => 'mi-plugin']
	 * @return self
	 */
	public static function init($config = [])
	{
		if (static::$instance === null) {
			static::$instance = new static($config);
		}
		return static::$instance;
	}

	/**
	 * Constructor privado (singleton)
	 */
	private function __construct($config)
	{
		if (static::VALIDATION_HASH === "") {
			wp_die(
				esc_html__(
					"PezWeb License Client: VALIDATION_HASH must be defined in the child class.",
					$this->get_text_domain(),
				),
			);
		}

		if (static::HMAC_SECRET === "") {
			wp_die(
				esc_html__(
					"PezWeb License Client: HMAC_SECRET must be defined in the child class.",
					$this->get_text_domain(),
				),
			);
		}

		$this->license_key = get_option("pzw_license_key", "");

		// Inicializar componentes (sin plugin_slug, usamos validation_hash como identificador)
		$this->cache = new Cache(static::VALIDATION_HASH);
		$this->api = new API(self::SERVER_URL);

		// Registrar hooks
		$this->register_hooks();
	}

	/**
	 * Registra hooks de WordPress
	 */
	private function register_hooks()
	{
		// Admin notices
		add_action("admin_notices", [$this, "show_license_notices"]);

		// Validación periódica
		add_action("admin_init", [$this, "maybe_validate_license"]);

		// Settings page
		add_action("admin_menu", [$this, "add_license_page"], 99);

		// AJAX handlers
		add_action("wp_ajax_pzw_activate_license", [
			$this,
			"ajax_activate_license",
		]);
		add_action("wp_ajax_pzw_deactivate_license", [
			$this,
			"ajax_deactivate_license",
		]);
	}

	/**
	 * Valida si hay licencia válida
	 *
	 * @param bool $force_check Forzar validación (ignorar cache)
	 * @return bool
	 */
	public function is_valid($force_check = false)
	{
		if (empty($this->license_key)) {
			return false;
		}

		// Intentar desde cache
		if (!$force_check) {
			$cached = $this->cache->get("license_data");
			if ($cached !== null && $this->is_cache_valid($cached)) {
				return $cached["valid"] ?? false;
			}
		}

		// Misma normalización que el servidor (REST usa esc_url_raw en site_url).
		$site_url = esc_url_raw(home_url());

		// Validar contra servidor
		$result = $this->api->validate_license(
			$this->license_key,
			$site_url,
			static::VALIDATION_HASH,
		);

		if (is_wp_error($result)) {
			// Si hay error de conexión, usar cache antiguo si existe
			$old_cache = $this->cache->get("license_data", true);
			if ($old_cache !== null && $this->is_cache_valid($old_cache)) {
				return $old_cache["valid"] ?? false;
			}
			return false;
		}

		// Validar firma HMAC
		if (!$this->verify_signature($result, $site_url)) {
			return false;
		}

		// Guardar en cache
		$this->cache->set("license_data", $result);
		$this->cache->set("last_validation_attempt", time());

		return $result["valid"] ?? false;
	}

	/**
	 * Verifica si el cache aún es válido según timestamp
	 *
	 * @param array $cached_data Datos cacheados
	 * @return bool
	 */
	private function is_cache_valid($cached_data)
	{
		if (empty($cached_data["valid_until"])) {
			return false;
		}

		return time() < $cached_data["valid_until"];
	}

	/**
	 * Verifica firma HMAC del servidor
	 *
	 * Debe coincidir con PZW_License_Generator::generate_signature() en el servidor:
	 * payload = "true|false|valid_until|site_url|product_id"
	 *
	 * @param array  $response Respuesta del servidor
	 * @param string $site_url Misma URL enviada en validate_license (p. ej. home_url())
	 * @return bool
	 */
	private function verify_signature($response, $site_url)
	{
		if (empty($response["signature"]) || empty($response["valid_until"])) {
			return false;
		}

		$valid_string = !empty($response["valid"]) ? "true" : "false";
		$valid_until = (int) $response["valid_until"];
		$product_id = isset($response["product_id"]) ? (int) $response["product_id"] : 0;

		// Misma cadena que usó el servidor al firmar (evita diferencias esc_url_raw / JSON).
		$url_in_payload = isset($response["site_url"]) && is_string($response["site_url"])
			? $response["site_url"]
			: $site_url;

		$payload =
			$valid_string .
			"|" .
			$valid_until .
			"|" .
			$url_in_payload .
			"|" .
			$product_id;

		$expected_signature = hash_hmac("sha256", $payload, static::HMAC_SECRET);

		return hash_equals($expected_signature, $response["signature"]);
	}

	/**
	 * Obtiene información completa de la licencia
	 *
	 * @return array|null
	 */
	public function get_license_info()
	{
		$cached = $this->cache->get("license_data");

		if (
			$cached &&
			$this->is_cache_valid($cached) &&
			!empty($cached["valid"])
		) {
			return [
				"status" => "active",
				"valid_until" => $cached["valid_until"],
			];
		}

		return null;
	}

	/**
	 * Valida periódicamente (cada carga de admin)
	 */
	public function maybe_validate_license()
	{
		if (!$this->should_validate_now()) {
			return;
		}

		$this->is_valid(true); // Forzar validación
	}

	/**
	 * Determina si debe validar ahora
	 */
	private function should_validate_now()
	{
		$last_check = $this->cache->get("last_validation_attempt");

		if ($last_check === null) {
			return true;
		}

		// Validar máximo cada 6 horas
		return time() - $last_check > 6 * HOUR_IN_SECONDS;
	}

	/**
	 * URL del enlace «Activar ahora» en avisos de admin.
	 * Por defecto apunta a la pantalla del cliente; el plugin puede sobrescribirla.
	 */
	protected function get_license_activate_url(): string
	{
		return admin_url("admin.php?page=pzw-license");
	}

	/**
	 * Muestra avisos de licencia en admin
	 */
	public function show_license_notices()
	{
		if ($this->is_valid()) {
			return;
		}

		echo '<div class="notice notice-warning is-dismissible"><p><strong>' .
			esc_html($this->get_plugin_name()) .
			":</strong> ";
		echo esc_html__(
			"Activate your license to unlock all features.",
			$this->get_text_domain(),
		);
		echo ' <a href="' .
			esc_url($this->get_license_activate_url()) .
			'">' .
			esc_html__("Activate now", $this->get_text_domain()) .
			"</a></p></div>";
	}

	/**
	 * Agrega página de licencia en admin
	 */
	public function add_license_page()
	{
		add_submenu_page(
			"options-general.php",
			esc_html__("License", $this->get_text_domain()),
			esc_html__("License", $this->get_text_domain()),
			"manage_options",
			"pzw-license",
			[$this, "render_license_page"],
		);
	}

	/**
	 * Renderiza página de licencia (BackendUI o vista clásica).
	 */
	public function render_license_page()
	{
		if (!current_user_can("manage_options")) {
			wp_die(
				esc_html__(
					"You do not have permission to access this page.",
					$this->get_text_domain(),
				),
			);
		}

		if ($this->use_backend_ui() && $this->is_backend_ui_available()) {
			$this->render_license_page_backend_ui();
			return;
		}

		$this->render_license_page_legacy();
	}

	/**
	 * Vista clásica (.wrap) sin pw/backend-ui.
	 */
	protected function render_license_page_legacy(): void
	{
		require_once __DIR__ . "/views/license-page.php";
	}

	/**
	 * Vista con PW\BackendUI\BackendUI::render_page().
	 */
	protected function render_license_page_backend_ui(): void
	{
		if (!$this->is_backend_ui_available()) {
			$this->render_license_page_legacy();
			return;
		}

		$domain = $this->get_text_domain();
		$license_key = get_option("pzw_license_key", "");
		$is_active = $this->is_valid();
		$license_info = $this->get_license_info();
		$plugin_name = $this->get_plugin_name();

		$bui = \PW\BackendUI\BackendUI::init();
		$bui->render_page([
			"title" => __("License", $domain),
			"description" => sprintf(
				/* translators: %s: product name */
				__("Activate or manage your license for %s.", $domain),
				$plugin_name,
			),
			"content" => function ($bui) use (
				$license_key,
				$is_active,
				$license_info,
				$domain,
			) {
				$ui = $bui->ui();
				include __DIR__ . "/views/license-page-backend-ui.php";
			},
		]);
	}

	/**
	 * AJAX: Activar licencia
	 */
	public function ajax_activate_license()
	{
		check_ajax_referer("pzw_license_nonce", "nonce");

		if (!current_user_can("manage_options")) {
			wp_send_json_error(
				esc_html__(
					"Insufficient permissions.",
					$this->get_text_domain(),
				),
			);
		}

		$license_key = sanitize_text_field($_POST["license_key"] ?? "");

		if (empty($license_key)) {
			wp_send_json_error(
				esc_html__(
					"License key is required.",
					$this->get_text_domain(),
				),
			);
		}

		$site_url = esc_url_raw(home_url());

		// Validar contra servidor
		$result = $this->api->validate_license(
			$license_key,
			$site_url,
			static::VALIDATION_HASH,
		);

		if (is_wp_error($result)) {
			wp_send_json_error($result->get_error_message());
		}

		// Validar firma
		if (!$this->verify_signature($result, $site_url)) {
			wp_send_json_error(
				esc_html__(
					"Invalid server response.",
					$this->get_text_domain(),
				),
			);
		}

		if (empty($result["valid"])) {
			wp_send_json_error(
				esc_html__(
					"Invalid or unauthorized license for this plugin.",
					$this->get_text_domain(),
				),
			);
		}

		// Guardar licencia
		update_option("pzw_license_key", $license_key);
		$this->cache->set("license_data", $result);

		$expires_date = date_i18n(
			get_option("date_format") . " " . get_option("time_format"),
			$result["valid_until"],
		);

		wp_send_json_success([
			"message" => esc_html__(
				"License activated successfully.",
				$this->get_text_domain(),
			),
			"valid_until" => $expires_date,
		]);
	}

	/**
	 * AJAX: Desactivar licencia
	 */
	public function ajax_deactivate_license()
	{
		check_ajax_referer("pzw_license_nonce", "nonce");

		if (!current_user_can("manage_options")) {
			wp_send_json_error(
				esc_html__(
					"Insufficient permissions.",
					$this->get_text_domain(),
				),
			);
		}

		delete_option("pzw_license_key");
		$this->cache->clear();

		wp_send_json_success(
			esc_html__(
				"License deactivated.",
				$this->get_text_domain(),
			),
		);
	}

	/**
	 * Obtiene nombre del plugin actual
	 */
	protected function get_plugin_name()
	{
		if (!function_exists("get_plugin_data")) {
			require_once ABSPATH . "wp-admin/includes/plugin.php";
		}

		// Obtener backtrace para encontrar el plugin que llamó al cliente
		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

		foreach ($trace as $frame) {
			if (empty($frame["file"])) {
				continue;
			}

			// Buscar si el archivo está dentro de wp-content/plugins
			if (strpos($frame["file"], WP_PLUGIN_DIR) === 0) {
				$relative = str_replace(
					WP_PLUGIN_DIR . "/",
					"",
					$frame["file"],
				);
				$plugin_folder = explode("/", $relative)[0];

				// Buscar el plugin en la lista
				$plugins = get_plugins("/" . $plugin_folder);
				if (!empty($plugins)) {
					$plugin_data = reset($plugins);
					return $plugin_data["Name"];
				}
			}
		}

		return __("Plugin", $this->get_text_domain());
	}

	/**
	 * Obtiene versión del plugin
	 */
	private function get_plugin_version()
	{
		if (!function_exists("get_plugin_data")) {
			require_once ABSPATH . "wp-admin/includes/plugin.php";
		}

		$plugins = get_plugins();
		foreach ($plugins as $file => $data) {
			if (strpos($file, $this->plugin_slug) !== false) {
				return $data["Version"];
			}
		}

		return "1.0.0";
	}

	/**
	 * Actualiza la license key en memoria (sin re-crear singleton)
	 *
	 * @param string $license_key Nueva key
	 */
	public function set_license_key($license_key)
	{
		$this->license_key = $license_key;
	}

	/**
	 * Método estático para verificar licencia desde cualquier parte
	 *
	 * @param bool $force_check Forzar validación contra servidor
	 * @return bool
	 */
	public static function check($force_check = false)
	{
		if (static::$instance === null) {
			return false;
		}
		return static::$instance->is_valid($force_check);
	}
}
