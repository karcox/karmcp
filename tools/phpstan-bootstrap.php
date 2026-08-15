<?php
/**
 * Constantes que el plugin define en runtime y PHPStan no puede inferir.
 *
 * Las de WordPress vienen de php-stubs/wordpress-stubs a través de
 * szepeviktor/phpstan-wordpress; aquí solo van las propias, para que el
 * análisis no las reporte como indefinidas en cada fichero que las usa.
 *
 * No se carga en runtime — solo lo lee PHPStan.
 *
 * @package KarMCP
 */

// Las cuatro que define karmcp.php (líneas 89-92). Si se añade una allí, se
// añade aquí.
define( 'KARMCP_VERSION', '0.0.0' );
define( 'KARMCP_DIR', __DIR__ . '/' );
define( 'KARMCP_URL', 'https://example.test/wp-content/plugins/karmcp/' );
define( 'KARMCP_BASENAME', 'karmcp/karmcp.php' );
