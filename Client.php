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
 * Varios plugins Pezweb pueden usar este cliente en el mismo sitio: cada
 * subclase tiene su propia instancia, su propia opción de clave, su propia
 * página de licencia y sus propias acciones AJAX (todas derivadas de
 * VALIDATION_HASH).
 *
 * Servidor: SERVER_URL (sobrescribible en la subclase) y el filtro
 * `pzw_license_server_url` ($url, $validation_hash) para pruebas locales.
 *
 * @version 2.3.0
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
	 * @var Updater|null Actualizaciones automáticas (si el plugin pasó plugin_file)
	 */
	private $updater = null;

	/**
	 * @var string URL del servidor (origen, sin path). Sobrescribible en la subclase.
	 */
	protected const SERVER_URL = "https://pezweb.com";

	/**
	 * Opción legacy (cliente <= 2.1) compartida por todos los plugins.
	 */
	private const LEGACY_LICENSE_OPTION = "pzw_license_key";

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
	 * @var array<class-string, self> Una instancia por subclase (un plugin no recibe la de otro).
	 */
	private static $instances = [];

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
		if (!isset(self::$instances[static::class])) {
			self::$instances[static::class] = new static($config);
		}
		return self::$instances[static::class];
	}

	/**
	 * Instancia ya inicializada de esta subclase, o null.
	 *
	 * @return static|null
	 */
	public static function instance()
	{
		return self::$instances[static::class] ?? null;
	}

	/**
	 * URL del servidor de licencias (origen, sin path).
	 *
	 * Filtro `pzw_license_server_url`: permite apuntar a un servidor local en
	 * desarrollo (p. ej. desde un mu-plugin) sin cambiar el código del plugin.
	 */
	protected function server_url(): string
	{
		return (string) apply_filters(
			"pzw_license_server_url",
			static::SERVER_URL,
			static::VALIDATION_HASH,
		);
	}

	/**
	 * Nombre de la opción donde se guarda la clave de este plugin.
	 */
	protected function license_option_name(): string
	{
		return "pzw_license_key_" . sanitize_key(static::VALIDATION_HASH);
	}

	/**
	 * Slug de la pantalla de licencia de este plugin.
	 */
	public function license_page_slug(): string
	{
		return "pzw-license-" . sanitize_key(static::VALIDATION_HASH);
	}

	/**
	 * Menú padre de la pantalla de licencia. Sobrescribir para colgarla del
	 * menú del plugin (p. ej. "mi-plugin-dashboard").
	 */
	protected function license_parent_slug(): string
	{
		return "options-general.php";
	}

	/**
	 * Nombre de una acción AJAX propia de este plugin.
	 */
	private function ajax_action(string $name): string
	{
		return "pzw_" . $name . "_license_" . sanitize_key(static::VALIDATION_HASH);
	}

	/**
	 * Clave guardada para este plugin (la legacy compartida se lee en el
	 * constructor solo si este plugin todavía no tiene la suya).
	 */
	public function get_license_key(): string
	{
		return (string) $this->license_key;
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

		$this->license_key = (string) get_option($this->license_option_name(), "");
		if ($this->license_key === "") {
			$this->license_key = (string) get_option(self::LEGACY_LICENSE_OPTION, "");
		}

		// Inicializar componentes (sin plugin_slug, usamos validation_hash como identificador)
		$this->cache = new Cache(static::VALIDATION_HASH);
		$this->api = new API($this->server_url());

		// Actualizaciones automáticas (opcional): ['plugin_file' => __FILE__ del plugin].
		if (!empty($config["plugin_file"]) && is_string($config["plugin_file"])) {
			$this->updater = new Updater(
				$this->api,
				static::VALIDATION_HASH,
				$config["plugin_file"],
				fn() => $this->license_key,
			);
			$this->updater->register();
		}

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

		// Validación periódica: admin, WP-Cron y respaldo no bloqueante desde el frontend.
		add_action("admin_init", [$this, "maybe_validate_license"]);
		add_filter("cron_schedules", [self::class, "add_cron_schedule"]);
		add_action($this->cron_hook(), [$this, "cron_refresh"]);
		add_action("init", [$this, "ensure_schedule"]);
		add_action("init", [$this, "maybe_spawn_refresh"], 99);
		add_action("wp_ajax_nopriv_" . $this->ajax_action("refresh"), [$this, "ajax_refresh_license"]);
		add_action("wp_ajax_" . $this->ajax_action("refresh"), [$this, "ajax_refresh_license"]);

		// Settings page
		add_action("admin_menu", [$this, "add_license_page"], 99);

		// AJAX handlers (acciones propias de este plugin)
		add_action("wp_ajax_" . $this->ajax_action("activate"), [
			$this,
			"ajax_activate_license",
		]);
		add_action("wp_ajax_" . $this->ajax_action("deactivate"), [
			$this,
			"ajax_deactivate_license",
		]);
		add_action("wp_ajax_" . $this->ajax_action("check"), [
			$this,
			"ajax_check_license",
		]);
	}

	/*
	 * Validación resiliente
	 * ---------------------
	 * - El estado (última respuesta firmada del servidor + intentos) vive en una
	 *   opción persistente por plugin; las visitas solo LEEN ese estado, nunca
	 *   llaman al servidor.
	 * - Se revalida por cron cada 6 h (+ un evento puntual al vencer valid_until),
	 *   en el admin si el estado tiene más de 6 h, y, si el cron no corre, con
	 *   un disparo no bloqueante desde el frontend cuando el estado tiene más
	 *   de 12 h.
	 * - Si el servidor no responde (error de red, 5xx, 429, firma inválida), se
	 *   mantiene la última validación exitosa hasta valid_until + 7 días, sin
	 *   pasar nunca del vencimiento real de la licencia. Solo una respuesta
	 *   firmada valid:false la apaga antes.
	 * - Reintentos con backoff (5 min, 15 min, 1 h, 6 h) y Retry-After.
	 */

	private const GRACE_PERIOD = 7 * DAY_IN_SECONDS;
	private const REFRESH_INTERVAL = 6 * HOUR_IN_SECONDS;
	private const STALE_AFTER = 12 * HOUR_IN_SECONDS;
	private const MAX_BACKOFF = 6 * HOUR_IN_SECONDS;
	private const BACKOFF_STEPS = [5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS];
	private const VALIDATION_TIMEOUT = 5;

	/**
	 * ¿Hay licencia válida? Lee el estado guardado (sin red).
	 *
	 * @param bool $force_check Revalidar ahora contra el servidor (admin/AJAX/cron).
	 * @return bool
	 */
	public function is_valid($force_check = false)
	{
		if ($this->license_key === "") {
			return false;
		}

		if ($force_check) {
			$this->refresh(true);
		}

		return $this->state_allows();
	}

	/**
	 * Revalida contra el servidor y actualiza el estado.
	 *
	 * @param bool $ignore_backoff true en acciones del usuario (verificar, activar).
	 * @return bool Licencia válida según el estado resultante.
	 */
	public function refresh(bool $ignore_backoff = false): bool
	{
		if ($this->license_key === "") {
			return false;
		}

		$state = $this->get_state();
		if (!$ignore_backoff && time() < (int) $state["next_retry_at"]) {
			return $this->state_allows();
		}

		// Misma normalización que el servidor (REST usa esc_url_raw en site_url).
		$site_url = esc_url_raw(home_url());

		$this->api->set_timeout(self::VALIDATION_TIMEOUT);
		$result = $this->api->validate_license(
			$this->license_key,
			$site_url,
			static::VALIDATION_HASH,
		);

		$state["checked_at"] = time();

		if (is_wp_error($result) || !is_array($result) || !$this->verify_signature($result, $site_url)) {
			// Sin respuesta firmada: no es un veredicto, se mantiene lo último (gracia) y se reintenta más tarde.
			$state["failures"] = (int) $state["failures"] + 1;
			$step = self::BACKOFF_STEPS[min($state["failures"], count(self::BACKOFF_STEPS)) - 1];
			$retry_after = is_wp_error($result) ? (int) ($result->get_error_data()["retry_after"] ?? 0) : 0;
			$state["next_retry_at"] = time() + min(self::MAX_BACKOFF, max($step, $retry_after));
			$this->save_state($state);

			return $this->state_allows();
		}

		$state["data"] = $result;
		$state["site"] = $site_url;
		$state["last_success_at"] = time();
		$state["failures"] = 0;
		$state["next_retry_at"] = 0;
		$this->save_state($state);

		// Revalidar justo cuando vence la firma (además del cron periódico).
		if (!empty($result["valid_until"])) {
			// Un solo evento puntual: reemplaza el anterior.
			wp_clear_scheduled_hook($this->cron_hook(), ["expiry"]);
			wp_schedule_single_event((int) $result["valid_until"] + MINUTE_IN_SECONDS, $this->cron_hook(), ["expiry"]);
		}

		return !empty($result["valid"]);
	}

	/**
	 * Evaluación del estado guardado (sin red). La firma se reverifica.
	 */
	private function state_allows(): bool
	{
		$state = $this->get_state();
		$data = $state["data"];

		if (!is_array($data) || empty($data["valid"]) || empty($data["valid_until"])) {
			return false;
		}

		if (!$this->verify_signature($data, (string) $state["site"])) {
			return false;
		}

		return time() < $this->usable_until($data);
	}

	/**
	 * Hasta cuándo sirve una respuesta válida: valid_until + gracia, sin pasar
	 * del vencimiento real (license_expires; null/ausente = sin tope).
	 */
	private function usable_until(array $data): int
	{
		$until = (int) $data["valid_until"] + self::GRACE_PERIOD;

		$expires = $data["license_expires"] ?? null;
		if (is_numeric($expires) && (int) $expires > 0) {
			$until = min($until, (int) $expires);
		}

		return $until;
	}

	/**
	 * Válida, pero sin confirmación reciente del servidor (pasó valid_until).
	 */
	public function in_grace_period(): bool
	{
		$data = $this->get_state()["data"];

		return $this->state_allows() && is_array($data) && time() >= (int) $data["valid_until"];
	}

	private function state_option(): string
	{
		return "pzw_license_state_" . sanitize_key(static::VALIDATION_HASH);
	}

	/**
	 * @return array{data: array|null, site: string, checked_at: int, last_success_at: int, failures: int, next_retry_at: int}
	 */
	private function get_state(): array
	{
		$defaults = [
			"data" => null,
			"site" => "",
			"checked_at" => 0,
			"last_success_at" => 0,
			"failures" => 0,
			"next_retry_at" => 0,
		];

		$state = get_option($this->state_option(), null);
		if (is_array($state)) {
			return array_merge($defaults, $state);
		}

		// Migración desde el cache de transients (cliente <= 2.2).
		$old = $this->cache->get("license_data", true);
		if (is_array($old)) {
			$defaults["data"] = $old;
			$defaults["site"] = esc_url_raw(home_url());
			$defaults["checked_at"] = (int) ($this->cache->get("last_validation_attempt", true) ?? 0);
			$defaults["last_success_at"] = $defaults["checked_at"];
		}

		return $defaults;
	}

	private function save_state(array $state): void
	{
		update_option($this->state_option(), $state, true);
	}

	private function clear_state(): void
	{
		delete_option($this->state_option());
		$this->cache->clear();
		wp_unschedule_hook($this->cron_hook());
	}

	private function cron_hook(): string
	{
		return "pzw_license_refresh_" . sanitize_key(static::VALIDATION_HASH);
	}

	/**
	 * Cron (periódico y puntual).
	 */
	public function cron_refresh(): void
	{
		$this->refresh();
	}

	/**
	 * Programa la revalidación periódica mientras haya clave.
	 */
	public function ensure_schedule(): void
	{
		if ($this->license_key === "" || wp_next_scheduled($this->cron_hook())) {
			return;
		}

		wp_schedule_event(time() + HOUR_IN_SECONDS, "pzw_license_six_hours", $this->cron_hook());
	}

	/**
	 * @param array $schedules
	 * @return array
	 */
	public static function add_cron_schedule($schedules)
	{
		if (!isset($schedules["pzw_license_six_hours"])) {
			$schedules["pzw_license_six_hours"] = [
				"interval" => self::REFRESH_INTERVAL,
				"display" => "Every 6 hours (PezWeb license)",
			];
		}

		return $schedules;
	}

	/**
	 * Respaldo si WP-Cron no corre: con el estado viejo (más de 12 h), una visita
	 * dispara una revalidación no bloqueante. La visita no espera.
	 */
	public function maybe_spawn_refresh(): void
	{
		if ($this->license_key === "" || is_admin() || wp_doing_ajax() || wp_doing_cron()) {
			return;
		}

		if (!$this->refresh_is_due(self::STALE_AFTER) || get_transient($this->spawn_lock()) !== false) {
			return;
		}

		set_transient($this->spawn_lock(), 1, 10 * MINUTE_IN_SECONDS);

		wp_remote_post(admin_url("admin-ajax.php"), [
			"blocking" => false,
			"timeout" => 0.01,
			"sslverify" => apply_filters("https_local_ssl_verify", false),
			"body" => ["action" => $this->ajax_action("refresh")],
		]);
	}

	/**
	 * AJAX público (sin parámetros): solo revalida el estado guardado si
	 * corresponde. Las comprobaciones van ANTES de llamar al servidor, así no
	 * sirve para forzar peticiones.
	 */
	public function ajax_refresh_license(): void
	{
		if ($this->refresh_is_due(self::STALE_AFTER) && get_transient($this->refresh_lock()) === false) {
			set_transient($this->refresh_lock(), 1, MINUTE_IN_SECONDS);
			$this->refresh();
			delete_transient($this->refresh_lock());
		}

		wp_die("", "", ["response" => 204]);
	}

	/**
	 * Toca revalidar: hay clave, el estado es más viejo que $age y no estamos en backoff.
	 */
	private function refresh_is_due(int $age): bool
	{
		if ($this->license_key === "") {
			return false;
		}

		$state = $this->get_state();

		return time() - (int) $state["checked_at"] > $age && time() >= (int) $state["next_retry_at"];
	}

	private function spawn_lock(): string
	{
		return "pzw_license_spawn_" . sanitize_key(static::VALIDATION_HASH);
	}

	private function refresh_lock(): string
	{
		return "pzw_license_refreshing_" . sanitize_key(static::VALIDATION_HASH);
	}

	/**
	 * Host canónico para reconstruir el HMAC (debe coincidir con el servidor).
	 */
	private static function canonical_site_for_signature($url)
	{
		$url = (string) $url;
		$parsed = parse_url($url);
		if (!empty($parsed['host'])) {
			return strtolower($parsed['host']);
		}

		return strtolower($url);
	}

	/**
	 * Verifica firma HMAC del servidor
	 *
	 * Debe coincidir con PZW_License_Generator::generate_signature() en el servidor:
	 * payload = "true|false|valid_until|host_canónico|product_id"
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

		// Misma cadena que usó el servidor al firmar (host canónico; ver PZW canonical_site_for_signature).
		$url_in_payload = isset($response["site_url"]) && is_string($response["site_url"])
			? $response["site_url"]
			: self::canonical_site_for_signature($site_url);

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
		$cached = $this->get_state()["data"];

		if (is_array($cached) && $this->state_allows()) {
			return [
				"status" => "active",
				// Última confirmación firmada del servidor vigente hasta aquí (luego, gracia).
				"valid_until" => (int) $cached["valid_until"],
				"usable_until" => $this->usable_until($cached),
				"grace" => $this->in_grace_period(),
				// Vencimiento real de la licencia: timestamp, null = de por vida,
				// o no presente si el servidor es anterior a pw_licence 2.1.
				"license_expires" => array_key_exists("license_expires", $cached)
					? ($cached["license_expires"] === null ? null : (int) $cached["license_expires"])
					: false,
			];
		}

		return null;
	}

	/**
	 * Texto de vencimiento para la pantalla de licencia.
	 *
	 * @param array $license_info Resultado de get_license_info().
	 * @return array{label:string,hint:string}
	 */
	public function expiry_text(array $license_info): array
	{
		$d = $this->get_text_domain();
		$expires = $license_info["license_expires"] ?? false;

		if ($expires === null) {
			return ["label" => __("Lifetime license", $d), "hint" => ""];
		}

		if ($expires === false) {
			// Servidor sin vencimiento en la respuesta: solo se sabe que está vigente.
			return ["label" => __("License active", $d), "hint" => ""];
		}

		$label = sprintf(
			/* translators: %s: expiration date */
			__("Valid until %s", $d),
			date_i18n(get_option("date_format"), (int) $expires),
		);

		$days = (int) ceil(((int) $expires - time()) / DAY_IN_SECONDS);
		$hint = "";
		if ($days <= 30) {
			$hint = sprintf(
				/* translators: %d: days until the license expires */
				_n("Expires in %d day", "Expires in %d days", max(0, $days), $d),
				max(0, $days),
			);
		}

		return ["label" => $label, "hint" => $hint];
	}

	/**
	 * Valida periódicamente (cada carga de admin)
	 */
	public function maybe_validate_license()
	{
		// Respeta el backoff: con el servidor caído no frena cada carga del admin.
		if ($this->refresh_is_due(self::REFRESH_INTERVAL)) {
			$this->refresh();
		}
	}

	/**
	 * URL del enlace «Activar ahora» en avisos de admin.
	 * Por defecto apunta a la pantalla del cliente; el plugin puede sobrescribirla.
	 */
	protected function get_license_activate_url(): string
	{
		$parent = $this->license_parent_slug();
		$base = str_ends_with($parent, ".php") ? $parent : "admin.php";

		return add_query_arg("page", $this->license_page_slug(), admin_url($base));
	}

	/**
	 * Muestra avisos de licencia en admin
	 */
	public function show_license_notices()
	{
		if ($this->is_valid()) {
			if ($this->in_grace_period() && current_user_can("manage_options")) {
				$state = $this->get_state();
				echo '<div class="notice notice-warning"><p><strong>' .
					esc_html($this->get_plugin_name()) .
					":</strong> ";
				echo esc_html(
					sprintf(
						/* translators: 1: date of the last successful check, 2: date until the license keeps working */
						__(
							"The license server could not be reached since %1\$s. The license stays active until %2\$s while we keep retrying.",
							$this->get_text_domain(),
						),
						date_i18n(get_option("date_format"), (int) $state["last_success_at"]),
						date_i18n(get_option("date_format"), $this->usable_until((array) $state["data"])),
					),
				);
				echo "</p></div>";
			}
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
		$hook = add_submenu_page(
			$this->license_parent_slug(),
			esc_html__("License", $this->get_text_domain()),
			esc_html__("License", $this->get_text_domain()),
			"manage_options",
			$this->license_page_slug(),
			[$this, "render_license_page"],
		);

		if ($hook) {
			add_action("admin_enqueue_scripts", function ($current) use ($hook) {
				if ($current === $hook) {
					$this->enqueue_license_script();
				}
			});
		}
	}

	/**
	 * JS de la pantalla de licencia (activar / desactivar / verificar).
	 * Inline: no depende de dónde quede instalado el paquete dentro de vendor/.
	 */
	private function enqueue_license_script(): void
	{
		$handle = "pzw-license-" . sanitize_key(static::VALIDATION_HASH);
		wp_register_script($handle, false, [], "2.2.0", true);
		wp_enqueue_script($handle);

		$config = [
			"ajaxUrl" => admin_url("admin-ajax.php"),
			"nonce" => wp_create_nonce("pzw_license_nonce"),
			"actions" => [
				"activate" => $this->ajax_action("activate"),
				"deactivate" => $this->ajax_action("deactivate"),
				"check" => $this->ajax_action("check"),
			],
			"i18n" => [
				"working" => __("Working…", $this->get_text_domain()),
				"error" => __("Could not contact the server. Try again.", $this->get_text_domain()),
				"confirmDeactivate" => __("Deactivate the license on this site?", $this->get_text_domain()),
			],
		];

		$js = <<<'JS'
(function (cfg) {
	function msg(text, ok) {
		var box = document.getElementById("pzw-license-message");
		if (!box) { return; }
		box.hidden = false;
		box.style.display = "";
		box.className = "pzw-message notice inline " + (ok ? "notice-success" : "notice-error");
		box.textContent = text;
	}
	function call(action, data, btn) {
		var body = new URLSearchParams(Object.assign({ action: cfg.actions[action], nonce: cfg.nonce }, data || {}));
		if (btn) { btn.disabled = true; }
		msg(cfg.i18n.working, true);
		return fetch(cfg.ajaxUrl, { method: "POST", credentials: "same-origin", body: body })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				var text = res && res.data ? (typeof res.data === "string" ? res.data : res.data.message) : cfg.i18n.error;
				msg(text || cfg.i18n.error, !!(res && res.success));
				if (res && res.success) { setTimeout(function () { window.location.reload(); }, 800); }
			})
			.catch(function () { msg(cfg.i18n.error, false); })
			.finally(function () { if (btn) { btn.disabled = false; } });
	}
	document.addEventListener("DOMContentLoaded", function () {
		var form = document.getElementById("pzw-license-form");
		if (form) {
			form.addEventListener("submit", function (e) {
				e.preventDefault();
				var input = document.getElementById("license_key");
				call("activate", { license_key: input ? input.value.trim() : "" }, document.getElementById("pzw-activate-license"));
			});
		}
		var off = document.getElementById("pzw-deactivate-license");
		if (off) {
			off.addEventListener("click", function () {
				if (window.confirm(cfg.i18n.confirmDeactivate)) { call("deactivate", {}, off); }
			});
		}
		var chk = document.getElementById("pzw-check-license");
		if (chk) { chk.addEventListener("click", function () { call("check", {}, chk); }); }
	});
})(%s);
JS;

		wp_add_inline_script($handle, sprintf($js, wp_json_encode($config)));
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
		$license_key = $this->get_license_key();
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

		// Guardar licencia (opción propia de este plugin)
		update_option($this->license_option_name(), $license_key, false);
		$this->license_key = $license_key;
		$this->save_state([
			"data" => $result,
			"site" => $site_url,
			"checked_at" => time(),
			"last_success_at" => time(),
			"failures" => 0,
			"next_retry_at" => 0,
		]);
		$this->ensure_schedule();
		if ($this->updater) {
			$this->updater->clear_cache();
		}

		$expires_date = !empty($result["license_expires"])
			? date_i18n(get_option("date_format"), (int) $result["license_expires"])
			: "";

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

		// Liberar la activación en el servidor (si falla la red, igual se borra localmente).
		if ($this->license_key !== "") {
			$this->api->deactivate_license(
				$this->license_key,
				esc_url_raw(home_url()),
				static::VALIDATION_HASH,
			);
		}

		delete_option($this->license_option_name());
		delete_option(self::LEGACY_LICENSE_OPTION);
		$this->license_key = "";
		$this->clear_state();
		if ($this->updater) {
			$this->updater->clear_cache();
		}

		wp_send_json_success(
			esc_html__(
				"License deactivated.",
				$this->get_text_domain(),
			),
		);
	}

	/**
	 * AJAX: Verificar licencia contra el servidor (ignora cache).
	 */
	public function ajax_check_license()
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

		if ($this->is_valid(true)) {
			wp_send_json_success(
				esc_html__("License is valid.", $this->get_text_domain()),
			);
		}

		wp_send_json_error(
			esc_html__(
				"The license is not valid for this site.",
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
		$instance = static::instance();
		if ($instance === null) {
			return false;
		}
		return $instance->is_valid($force_check);
	}
}
