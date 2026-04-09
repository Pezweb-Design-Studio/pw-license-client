<?php // pezweb-license-client/Cache.php

namespace PezWeb\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sistema de Cache para Licencias
 *
 * Almacena resultados de validación localmente para reducir requests al servidor
 * Usa WordPress Transients API
 */
class Cache
{
	/**
	 * @var string Prefijo para claves de cache
	 */
	private $prefix;

	/**
	 * @var int Duración del cache en segundos (default: 24 horas)
	 */
	private $duration;

	/**
	 * Constructor
	 *
	 * @param string $prefix Prefijo único para este plugin
	 */
	public function __construct($prefix)
	{
		$this->prefix = "pzw_cache_" . sanitize_key($prefix) . "_";
		$this->duration = 0; // No usar duración fija, el servidor controla con valid_until
	}

	/**
	 * Obtiene un valor del cache
	 *
	 * @param string $key Clave del cache
	 * @param bool $ignore_expiration Ignorar expiración (fallback mode)
	 * @return mixed|null Valor o null si no existe
	 */
	public function get($key, $ignore_expiration = false)
	{
		$cache_key = $this->prefix . $key;

		if ($ignore_expiration) {
			// Obtener directamente de la base de datos, ignorando expiración
			$value = get_option($cache_key . "_fallback");
			return $value !== false ? $value : null;
		}

		$value = get_transient($cache_key);
		return $value !== false ? $value : null;
	}

	/**
	 * Guarda un valor en cache
	 *
	 * @param string $key Clave del cache
	 * @param mixed $value Valor a guardar
	 * @param int|null $expiration Duración custom (opcional, default: 1 año)
	 * @return bool
	 */
	public function set($key, $value, $expiration = null)
	{
		$cache_key = $this->prefix . $key;
		$expiration = $expiration ?? YEAR_IN_SECONDS; // Default 1 año

		// Guardar en transient (con expiración larga)
		$result = set_transient($cache_key, $value, $expiration);

		// Guardar copia fallback sin expiración (para modo offline)
		update_option($cache_key . "_fallback", $value, false);

		return $result;
	}

	/**
	 * Elimina un valor del cache
	 *
	 * @param string $key Clave del cache
	 * @return bool
	 */
	public function delete($key)
	{
		$cache_key = $this->prefix . $key;

		delete_transient($cache_key);
		delete_option($cache_key . "_fallback");

		return true;
	}

	/**
	 * Limpia todo el cache del plugin
	 *
	 * @return int Número de entradas eliminadas
	 */
	public function clear()
	{
		global $wpdb;

		// Limpiar transients
		$pattern = $wpdb->esc_like("_transient_" . $this->prefix) . "%";
		$transients_deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
				$pattern,
			),
		);

		// Limpiar timeouts de transients
		$timeout_pattern =
			$wpdb->esc_like("_transient_timeout_" . $this->prefix) . "%";
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
				$timeout_pattern,
			),
		);

		// Limpiar fallbacks
		$fallback_pattern = $wpdb->esc_like($this->prefix) . "%_fallback";
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
				$fallback_pattern,
			),
		);

		return $transients_deleted;
	}

	/**
	 * Verifica si una clave existe en cache (válida)
	 *
	 * @param string $key Clave del cache
	 * @return bool
	 */
	public function has($key)
	{
		return $this->get($key) !== null;
	}

	/**
	 * Obtiene múltiples valores del cache
	 *
	 * @param array $keys Array de claves
	 * @return array Array asociativo clave => valor
	 */
	public function get_multiple($keys)
	{
		$result = [];

		foreach ($keys as $key) {
			$result[$key] = $this->get($key);
		}

		return $result;
	}

	/**
	 * Guarda múltiples valores en cache
	 *
	 * @param array $items Array asociativo clave => valor
	 * @param int|null $expiration Duración custom
	 * @return bool
	 */
	public function set_multiple($items, $expiration = null)
	{
		$success = true;

		foreach ($items as $key => $value) {
			if (!$this->set($key, $value, $expiration)) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Obtiene estadísticas del cache
	 *
	 * @return array
	 */
	public function get_stats()
	{
		global $wpdb;

		$pattern = $wpdb->esc_like("_transient_" . $this->prefix) . "%";
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s",
				$pattern,
			),
		);

		return [
			"entries" => (int) $count,
			"prefix" => $this->prefix,
			"duration" => $this->duration,
			"duration_human" => human_time_diff(0, $this->duration),
		];
	}
}
