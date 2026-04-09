<?php // pezweb-license-client/autoload.php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader para PezWeb License Client
 *
 * Carga automáticamente las clases del namespace PezWeb\License
 */

spl_autoload_register(function ($class) {
	// Prefijo del namespace
	$prefix = "PezWeb\\License\\";

	// Directorio base
	$base_dir = __DIR__ . "/";

	// Verificar si la clase usa el namespace
	$len = strlen($prefix);
	if (strncmp($prefix, $class, $len) !== 0) {
		return;
	}

	// Obtener nombre relativo de la clase
	$relative_class = substr($class, $len);

	// Reemplazar namespace separators con directory separators
	$file = $base_dir . str_replace("\\", "/", $relative_class) . ".php";

	// Cargar archivo si existe
	if (file_exists($file)) {
		require $file;
	}
});
