<?php // pezweb-license-client/Updater.php

namespace PezWeb\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Actualizaciones automáticas desde el servidor de licencias.
 *
 * Usa el filtro nativo `update_plugins_{hostname}` (WP 5.8+): el plugin debe
 * declarar `Update URI` en su cabecera (p. ej. https://pezweb.com/plugins/mi-plugin);
 * así WordPress.org no le ofrece actualizaciones y WordPress pregunta aquí.
 *
 * El servidor (POST pzw/v1/update/{hash}) devuelve la última versión y, si la
 * licencia es válida para el sitio, un enlace de descarga firmado.
 */
class Updater
{
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;
	private const ERROR_TTL = HOUR_IN_SECONDS;

	/** @var API */
	private $api;

	/** @var string */
	private $validation_hash;

	/** @var string Archivo principal del plugin (ruta absoluta). */
	private $plugin_file;

	/** @var string plugin-dir/plugin-file.php */
	private $basename;

	/** @var callable(): string */
	private $license_key;

	public function __construct(API $api, string $validation_hash, string $plugin_file, callable $license_key)
	{
		$this->api = $api;
		$this->validation_hash = $validation_hash;
		$this->plugin_file = $plugin_file;
		$this->basename = plugin_basename($plugin_file);
		$this->license_key = $license_key;
	}

	public function register(): void
	{
		$headers = get_file_data($this->plugin_file, ["UpdateURI" => "Update URI"]);
		$host = wp_parse_url((string) ($headers["UpdateURI"] ?? ""), PHP_URL_HOST);
		if (!$host) {
			return;
		}

		add_filter("update_plugins_" . strtolower($host), [$this, "check_update"], 10, 3);
		add_filter("plugins_api", [$this, "plugin_info"], 20, 3);
		add_filter("upgrader_source_selection", [$this, "keep_install_folder"], 10, 4);
		add_action("upgrader_process_complete", [$this, "clear_cache"], 10, 0);
	}

	/**
	 * Si la carpeta raíz del zip no se llama como la instalación actual, la
	 * renombra; si no, WordPress instalaría el plugin en otra carpeta y quedaría
	 * desactivado.
	 *
	 * @param string|\WP_Error $source
	 * @param string           $remote_source
	 * @param \WP_Upgrader     $upgrader
	 * @param array            $hook_extra
	 * @return string|\WP_Error
	 */
	public function keep_install_folder($source, $remote_source, $upgrader, $hook_extra = [])
	{
		if (is_wp_error($source) || ($hook_extra["plugin"] ?? "") !== $this->basename) {
			return $source;
		}

		$wanted = trailingslashit($remote_source) . dirname($this->basename) . "/";
		if (untrailingslashit($source) === untrailingslashit($wanted)) {
			return $source;
		}

		global $wp_filesystem;
		if (!$wp_filesystem || !$wp_filesystem->move(untrailingslashit($source), untrailingslashit($wanted), true)) {
			return new \WP_Error("pzw_rename_failed", "Could not prepare the update package folder.");
		}

		return $wanted;
	}

	/**
	 * @param array|false $update
	 * @param array       $plugin_data
	 * @param string      $plugin_file
	 * @return array|false
	 */
	public function check_update($update, $plugin_data, $plugin_file)
	{
		if ($plugin_file !== $this->basename) {
			return $update;
		}

		$remote = $this->remote();
		if (!$remote) {
			return $update;
		}

		return [
			"id" => $plugin_data["UpdateURI"] ?? "",
			"slug" => dirname($this->basename),
			"plugin" => $this->basename,
			"version" => (string) $remote["version"],
			"url" => (string) ($remote["url"] ?? ""),
			"package" => (string) ($remote["package"] ?? ""),
			"tested" => (string) ($remote["tested"] ?? ""),
			"requires" => (string) ($remote["requires"] ?? ""),
			"requires_php" => (string) ($remote["requires_php"] ?? ""),
			"upgrade_notice" => (string) ($remote["message"] ?? ""),
		];
	}

	/**
	 * Ventana "Ver detalles" del listado de plugins.
	 */
	public function plugin_info($result, $action, $args)
	{
		if ($action !== "plugin_information" || ($args->slug ?? "") !== dirname($this->basename)) {
			return $result;
		}

		$remote = $this->remote();
		if (!$remote) {
			return $result;
		}

		$data = get_file_data($this->plugin_file, ["Name" => "Plugin Name", "Author" => "Author"]);

		return (object) [
			"name" => $data["Name"] ?: (string) ($remote["name"] ?? ""),
			"slug" => dirname($this->basename),
			"version" => (string) $remote["version"],
			"author" => $data["Author"],
			"homepage" => (string) ($remote["url"] ?? ""),
			"requires" => (string) ($remote["requires"] ?? ""),
			"requires_php" => (string) ($remote["requires_php"] ?? ""),
			"tested" => (string) ($remote["tested"] ?? ""),
			"download_link" => (string) ($remote["package"] ?? ""),
			"sections" => [
				"description" => esc_html((string) ($remote["message"] ?? "")),
			],
		];
	}

	public function clear_cache(): void
	{
		delete_transient($this->cache_key());
	}

	/**
	 * Respuesta del servidor, en cache 12 h (1 h si falló).
	 *
	 * @return array|null
	 */
	private function remote()
	{
		$cached = get_transient($this->cache_key());
		if (is_array($cached)) {
			return empty($cached["version"]) ? null : $cached;
		}

		$response = $this->api->get_update_info(
			(string) call_user_func($this->license_key),
			esc_url_raw(home_url()),
			$this->validation_hash,
		);

		if (is_wp_error($response) || !is_array($response) || empty($response["version"])) {
			set_transient($this->cache_key(), ["version" => ""], self::ERROR_TTL);
			return null;
		}

		set_transient($this->cache_key(), $response, self::CACHE_TTL);
		return $response;
	}

	private function cache_key(): string
	{
		return "pzw_update_" . sanitize_key($this->validation_hash);
	}
}
