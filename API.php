<?php // pezweb-license-client/API.php

namespace PezWeb\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cliente API para Servidor de Licencias
 *
 * Maneja toda comunicación HTTP con pezweb.com
 */
class API
{
	/**
	 * @var string URL base del servidor de licencias
	 */
	private $server_url;

	/**
	 * @var int Timeout para requests HTTP (segundos)
	 */
	private $timeout = 15;

	/**
	 * @var string Versión del cliente (para User-Agent)
	 */
	private $version = "1.0.0";

	/**
	 * Constructor
	 *
	 * @param string $server_url URL del servidor (ej: https://pezweb.com)
	 */
	public function __construct($server_url)
	{
		$this->server_url = rtrim($server_url, "/");
	}

	/**
	 * Valida una licencia contra el servidor
	 *
	 * @param string $license_key License key
	 * @param string $site_url URL del sitio del cliente
	 * @param string $validation_hash Hash único del plugin
	 * @return array|WP_Error Resultado de validación o error
	 */
	public function validate_license($license_key, $site_url, $validation_hash)
	{
		$endpoint = "/wp-json/pzw/v1/licence_validation/" . $validation_hash;

		$body = [
			"license_key" => $license_key,
			"site_url" => $site_url,
		];

		return $this->post($endpoint, $body);
	}

	/**
	 * Obtiene notificaciones para un plugin
	 *
	 * @param string $plugin_slug Slug del plugin
	 * @return array|WP_Error Lista de notificaciones o error
	 */
	public function get_notifications($plugin_slug)
	{
		$endpoint = "/wp-json/pzw/v1/notifications/" . $plugin_slug;

		return $this->get($endpoint);
	}

	/**
	 * Desactiva una licencia en el servidor
	 *
	 * @param string $license_key License key
	 * @param string $site_url URL del sitio
	 * @return array|WP_Error
	 */
	public function deactivate_license($license_key, $site_url, $validation_hash = "")
	{
		// Ruta del servidor: pzw/v1/licence_deactivation/{hash}.
		$endpoint = "/wp-json/pzw/v1/licence_deactivation/" . rawurlencode((string) $validation_hash);

		$body = [
			"license_key" => $license_key,
			"site_url" => $site_url,
		];

		return $this->post($endpoint, $body);
	}

	/**
	 * Reporta telemetría (uso del plugin)
	 *
	 * @param string $license_key License key
	 * @param string $plugin_slug Slug del plugin
	 * @param array $data Datos de telemetría
	 * @return array|WP_Error
	 */
	public function report_telemetry($license_key, $plugin_slug, $data)
	{
		$endpoint = "/wp-json/pzw/v1/telemetry";

		$body = [
			"license_key" => $license_key,
			"plugin_slug" => $plugin_slug,
			"site_url" => home_url(),
			"data" => $data,
		];

		return $this->post($endpoint, $body);
	}

	/**
	 * Request GET genérico
	 *
	 * @param string $endpoint Ruta del endpoint
	 * @param array $query_params Parámetros de query string
	 * @return array|WP_Error
	 */
	private function get($endpoint, $query_params = [])
	{
		$url = $this->build_url($endpoint, $query_params);

		$response = wp_remote_get($url, [
			"timeout" => $this->timeout,
			"headers" => $this->get_headers(),
			"user-agent" => $this->get_user_agent(),
		]);

		return $this->parse_response($response);
	}

	/**
	 * Request POST genérico
	 *
	 * @param string $endpoint Ruta del endpoint
	 * @param array $body Datos del body
	 * @return array|WP_Error
	 */
	private function post($endpoint, $body = [])
	{
		$url = $this->build_url($endpoint);

		// WordPress REST API espera JSON en el cuerpo; form-urlencoded a menudo
		// no rellena get_param() igual en todos los hosts.
		$headers = [
			"Accept" => "application/json",
			"Content-Type" => "application/json; charset=utf-8",
		];

		$response = wp_remote_post($url, [
			"timeout" => $this->timeout,
			"headers" => $headers,
			"user-agent" => $this->get_user_agent(),
			"body" => wp_json_encode($body),
		]);

		return $this->parse_response($response);
	}

	/**
	 * Construye URL completa
	 *
	 * @param string $endpoint Endpoint relativo
	 * @param array $query_params Query params
	 * @return string
	 */
	private function build_url($endpoint, $query_params = [])
	{
		$url = $this->server_url . $endpoint;

		if (!empty($query_params)) {
			$url = add_query_arg($query_params, $url);
		}

		return $url;
	}

	/**
	 * Obtiene headers para requests
	 *
	 * @return array
	 */
	private function get_headers()
	{
		return [
			"Accept" => "application/json",
		];
	}

	/**
	 * Genera User-Agent identificador
	 *
	 * @return string
	 */
	private function get_user_agent()
	{
		return sprintf(
			"PezWeb-License-Client/%s; WordPress/%s; %s",
			$this->version,
			get_bloginfo("version"),
			home_url(),
		);
	}

	/**
	 * Parsea respuesta HTTP
	 *
	 * @param array|WP_Error $response Respuesta de wp_remote_*
	 * @return array|WP_Error
	 */
	private function parse_response($response)
	{
		// Error de conexión
		if (is_wp_error($response)) {
			return new \WP_Error(
				"connection_error",
				"Error conectando con servidor de licencias: " .
					$response->get_error_message(),
			);
		}

		// Obtener código de respuesta
		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);

		// Intentar decodificar JSON
		$data = json_decode($body, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return new \WP_Error(
				"invalid_response",
				"Respuesta inválida del servidor",
			);
		}

		// Manejar errores HTTP
		if ($code >= 400) {
			return new \WP_Error(
				$data["code"] ?? "http_error",
				$data["message"] ?? "Error desconocido del servidor",
				["status" => $code],
			);
		}

		return $data;
	}

	/**
	 * Verifica conectividad con el servidor
	 *
	 * @return bool|WP_Error True si conecta, WP_Error si falla
	 */
	public function test_connection()
	{
		$response = wp_remote_get($this->server_url, [
			"timeout" => 5,
			"redirection" => 0,
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);

		if ($code >= 200 && $code < 400) {
			return true;
		}

		return new \WP_Error(
			"connection_failed",
			sprintf("Servidor retornó código %d", $code),
		);
	}

	/**
	 * Obtiene información del servidor
	 *
	 * @return array
	 */
	public function get_server_info()
	{
		return [
			"url" => $this->server_url,
			"timeout" => $this->timeout,
			"version" => $this->version,
			"connection" => $this->test_connection() === true ? "OK" : "Error",
		];
	}
}
