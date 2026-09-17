<?php
/**
* Plugin Name: CT Online WooCommerce Precio Ajustado
 * Description: Muestra precios ajustados (promociones, IVA, margen, tipo cambio USD) en WooCommerce, con caché.
 * Version: 1.0
 * Author: Felix Salazar
 * 
 * Changelog:
 * 1.0 - Versión inicial funcional que ajusta precios con promociones, IVA, margen y tipo de cambio USD. Implementa caché y consulta API por lote para optimizar rendimiento.
 */

if (!defined('ABSPATH')) exit;
function registrar_llamada_a_sku($sku, $origen = 'desconocido') {
    $info = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
    $llamador = isset($info[1]['function']) ? $info[1]['function'] : 'N/A';
    error_log("📍 Llamada a SKU: $sku desde $origen > función: $llamador");
}


// FUNCIONES API
function obtener_config_ctonline() {
    static $config = null; // Guarda en memoria para evitar leer muchas veces

    if ($config !== null) return $config;

    $ruta_json = plugin_dir_path(__FILE__) . 'ctonline_config.json';
    if (file_exists($ruta_json)) {
        $contenido = file_get_contents($ruta_json);
        $config = json_decode($contenido, true) ?: [];
    } else {
        $config = [];
    }
    return $config;
}


function guardar_config_ctonline($nueva_config) {
    $ruta_json = plugin_dir_path(__FILE__) . 'ctonline_config.json';
    file_put_contents($ruta_json, json_encode($nueva_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Limpia transients de precios para forzar que se recalcule con el nuevo IVA/Margen
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ct_precio_completo_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ct_precio_completo_%'");

    
}


function servicioApi($metodo, $servicio, $json = null, $token = null) {

    error_log("🛠️ API llamada: $metodo $servicio");

    $url = 'http://connect.ctonline.mx:3001/' . $servicio;

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $metodo);

    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    if ($token) {
        $headers[] = 'x-auth: ' . $token;
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);

    $curl_error = curl_error($ch);

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);


    // ============================================================
    // ERROR CURL
    // ============================================================

    if ($result === false) {

        error_log(
            "❌ ERROR CURL: " .
            $curl_error
        );

        throw new Exception(
            "Error CURL: " . $curl_error
        );
    }


    // ============================================================
    // REGISTRAR RESPUESTA
    // ============================================================

    error_log(
        "📡 HTTP CODE: " .
        $http_code
    );

    error_log(
        "📡 RESPUESTA CT ONLINE: " .
        $result
    );


    // ============================================================
    // RESPUESTA VACÍA
    // ============================================================

    if (trim($result) === '') {

        throw new Exception(
            "CT Online devolvió una respuesta vacía. HTTP: " .
            $http_code
        );
    }


    // ============================================================
    // DECODIFICAR JSON
    // ============================================================

    $decoded = json_decode($result);


    if (
        json_last_error() !== JSON_ERROR_NONE
    ) {

        error_log(
            "❌ ERROR JSON: " .
            json_last_error_msg()
        );

        throw new Exception(
            "Error decodificando JSON: " .
            json_last_error_msg() .
            " | HTTP: " .
            $http_code
        );
    }


    return $decoded;
}



function crearNuevoToken() {
    $token = get_transient('ctonline_token');
    if ($token) return $token;

    try {
        $config = obtener_config_ctonline();
        $json = json_encode([
            'email' => $config['email'] ?? '',
            'cliente' => $config['cliente'] ?? '',
            'rfc' => $config['rfc'] ?? ''
        ]);

        $respuesta = servicioApi('POST', 'cliente/token', $json);
        if (!isset($respuesta->token)) throw new Exception("No se pudo obtener token");
        
        set_transient('ctonline_token', $respuesta->token, 30 * MINUTE_IN_SECONDS);
        return $respuesta->token;

    } catch (Exception $e) {
        if (current_user_can('manage_woocommerce')) {
            echo '<div style="color:red;font-weight:bold;">⚠️ Error al obtener token: ' . esc_html($e->getMessage()) . '</div>';
        }
        return null;
    }
}





function calcular_precio_ctonline($precio_api) {
    $config = obtener_config_ctonline();
    $iva = isset($config['iva']) ? floatval($config['iva']) : 0;
    $margen = isset($config['margen']) ? floatval($config['margen']) : 0;

    $precio = floatval($precio_api);
    $precio_con_iva = $precio * (1 + ($iva / 100));
    $precio_final = $precio_con_iva * (1 + ($margen / 100));

    return round($precio_final, 2);
}


//----------------------------------------------------------------------

/* 
------------------------------------------------------
 ✅ FUNCIÓN 1: Obtener precio ajustado con fallback
------------------------------------------------------
*/
function obtener_precio_ajustado_ctonline_completo($sku) {
    if (empty($sku)) return false;
    registrar_llamada_a_sku($sku, 'precio_completo');

    // Evitar llamadas innecesarias en admin
    if (is_admin() && !defined('DOING_AJAX')) return false;

    // Cache interno
    if (isset($GLOBALS['ct_precio_cache_sku'][$sku])) {
        return $GLOBALS['ct_precio_cache_sku'][$sku];
    }

    $transient_key = 'ct_precio_completo_' . sanitize_key($sku);
    $cached = get_transient($transient_key);
    if ($cached !== false) {
        $GLOBALS['ct_precio_cache_sku'][$sku] = $cached;
        return $cached;
    }

    try {
        // Intentar API
        $token = crearNuevoToken();
        if (!$token) throw new Exception("Token no disponible");

        $respuesta = servicioApi('GET', 'existencia/promociones/' . urlencode($sku), null, $token);
        if (!isset($respuesta->precio)) throw new Exception("Respuesta inválida");

        // --- Procesar precio ---
        $precio_normal = floatval($respuesta->precio);
        $precio_promocion = null;
        $promo_ini = null;
        $promo_fin = null;

        if (isset($respuesta->almacenes[0]->promocion->precio)) {
            $precio_promocion = floatval($respuesta->almacenes[0]->promocion->precio);
            $promo_ini = $respuesta->almacenes[0]->promocion->vigente->ini ?? null;
            $promo_fin = $respuesta->almacenes[0]->promocion->vigente->fin ?? null;
        }

        // Moneda USD -> convertir
        $moneda = strtoupper($respuesta->moneda ?? 'MXN');
        if ($moneda === 'USD') {
            $tipoCambioObj = servicioApi('GET', 'pedido/tipoCambio', null, $token);
            if (isset($tipoCambioObj->tipoCambio) && is_numeric($tipoCambioObj->tipoCambio)) {
                $precio_normal *= floatval($tipoCambioObj->tipoCambio);
                if ($precio_promocion) $precio_promocion *= floatval($tipoCambioObj->tipoCambio);
            }
        }

        // Ajuste IVA y margen
        $config = obtener_config_ctonline();
        $iva = isset($config['iva']) ? floatval($config['iva']) / 100 : 0.16;
        $margen = isset($config['margen']) ? floatval($config['margen']) / 100 : 0.20;

        $precio_normal_ajustado = round($precio_normal * (1 + $iva) * (1 + $margen), 2);
        $precio_promocion_ajustado = $precio_promocion ? round($precio_promocion * (1 + $iva) * (1 + $margen), 2) : null;

        $resultado = [
            'precio_normal_ajustado' => $precio_normal_ajustado,
            'precio_ajustado' => $precio_promocion_ajustado ?? $precio_normal_ajustado,
            'promocion' => $precio_promocion_ajustado !== null,
            'promo_ini' => $promo_ini,
            'promo_fin' => $promo_fin
        ];

        // Guardar en transient y backup
        set_transient($transient_key, $resultado, 3600);
        $GLOBALS['ct_precio_cache_sku'][$sku] = $resultado;

        $product_id = wc_get_product_id_by_sku($sku);
        if ($product_id) {
            update_post_meta($product_id, '_ctonline_precio_backup', $resultado);
        }

        return $resultado;

    } catch (Exception $e) {
        error_log("❌ Error al obtener precio ajustado: " . $e->getMessage());

        // ✅ Fallback: usar último backup si existe
        $product_id = wc_get_product_id_by_sku($sku);
        if ($product_id) {
            $backup = get_post_meta($product_id, '_ctonline_precio_backup', true);
            if (!empty($backup) && is_array($backup)) {
                return $backup;
            }
        }
        return false;
    }
}


//------------------------------------------------------------------

// Ajustar precios numéricos internamente
add_filter('woocommerce_product_get_price', 'ajustar_precio_ctonline_numerico', 20, 2);
add_filter('woocommerce_product_get_regular_price', 'ajustar_precio_ctonline_numerico', 20, 2);
function ajustar_precio_ctonline_numerico($precio, $product) {
    $sku = $product->get_sku();
    if (!$sku) return $precio;

    $precio_ct = obtener_precio_ajustado_ctonline_completo($sku);
    return $precio_ct !== false ? $precio_ct['precio_ajustado'] : $precio;
}

// Mostrar precios con formato y mensaje de agotado
add_filter('woocommerce_get_price_html', 'ctonline_precio_html_con_promocion_y_agotado', 9999, 2);
function ctonline_precio_html_con_promocion_y_agotado($price_html, $product) {
    static $html_precio_cache = [];

    $sku = $product->get_sku();
    if (!$sku) return $price_html;

    if (isset($html_precio_cache[$sku])) {
        return $html_precio_cache[$sku];
    }

    // 1. Intentar usar transient
    $precio_ct = get_transient('ct_precio_completo_' . sanitize_key($sku));

    // 2. Si no hay transient, usar backup en post_meta
    if (!$precio_ct) {
        $backup = get_post_meta($product->get_id(), '_ctonline_precio_backup', true);
        if ($backup && is_array($backup)) {
            $precio_ct = $backup;
        }
    }

    // Si no hay precio de ningún tipo, devolver original
    if (!$precio_ct || empty($precio_ct['precio_ajustado'])) {
        return $price_html;
    }

    // Obtener existencia (si hay en cache)
    $existencias = get_transient('ct_existencia_almacenes_' . sanitize_key($sku));
    $agotado = $existencias && isset($existencias['existencia_total']) && $existencias['existencia_total'] <= 0;

    // Formatear precio
    $precio_mx = wc_price($precio_ct['precio_ajustado']);
    $html = '';

    // Mostrar promoción si aplica
    if (!empty($precio_ct['promocion']) && !empty($precio_ct['precio_normal_ajustado'])) {
        $precio_normal = wc_price($precio_ct['precio_normal_ajustado']);
        $ini = !empty($precio_ct['promo_ini']) ? date_i18n('d/m/Y', strtotime($precio_ct['promo_ini'])) : '';
        $fin = !empty($precio_ct['promo_fin']) ? date_i18n('d/m/Y', strtotime($precio_ct['promo_fin'])) : '';
        $html .= "<del>$precio_normal</del> <ins>$precio_mx</ins>";
        if ($ini && $fin) {
            $html .= "<br><small style='color:green;'>Promoción vigente: $ini al $fin</small>";
        }
    } else {
        $html .= $precio_mx;
    }

    // Agregar mensaje de agotado
    if ($agotado) {
        $html .= "<br><span style='color:red; font-weight:bold;'>Agotado</span>";
    }

    // Cachear HTML
    $html_precio_cache[$sku] = $html;
    return $html;
}



/* 
------------------------------------------------------
 ✅ FUNCIÓN 2: Obtener existencias con fallback
------------------------------------------------------
*/
function obtener_existencia_almacenes_ctonline($sku) {
    if (empty($sku)) return false;
    registrar_llamada_a_sku($sku, 'existencia_almacenes');

    static $mem_existencias = [];
    if (isset($mem_existencias[$sku])) return $mem_existencias[$sku];

    $transient_key = 'ct_existencia_almacenes_' . sanitize_key($sku);
    $cached = get_transient($transient_key);
    if ($cached !== false) {
        $mem_existencias[$sku] = $cached;
        return $cached;
    }

    try {
        // Intentar API
        $token = crearNuevoToken();
        if (!$token) throw new Exception("Token no disponible");

        $respuesta = servicioApi('GET', 'existencia/promociones/' . urlencode($sku), null, $token);
        if (!isset($respuesta->almacenes) || !is_array($respuesta->almacenes)) {
            throw new Exception("Respuesta sin almacenes");
        }

        // Procesar existencias
        $existencia_total = 0;
        $detalle_almacenes = [];
        foreach ($respuesta->almacenes as $almObj) {
            foreach ($almObj as $alm => $cantidad) {
                if ($alm !== 'promocion' && is_numeric($cantidad)) {
                    $detalle_almacenes[] = "$alm: $cantidad";
                    $existencia_total += intval($cantidad);
                }
            }
        }

        $detalle = $existencia_total > 0 ? implode(', ', $detalle_almacenes) : 'Sin disponibilidad';
        $resultado = [
            'existencia_total' => $existencia_total,
            'detalle_almacenes' => $detalle
        ];

        // Guardar en transient y backup
        set_transient($transient_key, $resultado, 1800);
        $mem_existencias[$sku] = $resultado;

        $product_id = wc_get_product_id_by_sku($sku);
        if ($product_id) {
            update_post_meta($product_id, '_ctonline_existencia_backup', $existencia_total);
            update_post_meta($product_id, '_ctonline_existencia_detalle', $detalle);
        }

        return $resultado;

    } catch (Exception $e) {
        error_log("❌ Error en obtener_existencia_almacenes_ctonline: " . $e->getMessage());

        // ✅ Fallback: usar último backup
        $product_id = wc_get_product_id_by_sku($sku);
        if ($product_id) {
            $backup_total = get_post_meta($product_id, '_ctonline_existencia_backup', true);
            $backup_detalle = get_post_meta($product_id, '_ctonline_existencia_detalle', true);
            if ($backup_total !== '') {
                return [
                    'existencia_total' => intval($backup_total),
                    'detalle_almacenes' => $backup_detalle ?: 'Sin disponibilidad'
                ];
            }
        }

        return [
            'existencia_total' => 0,
            'detalle_almacenes' => 'Sin disponibilidad'
        ];
    }
}


add_action('woocommerce_single_product_summary', 'mostrar_existencias_almacenes_ctonline', 25);
function mostrar_existencias_almacenes_ctonline() {
    global $product;
    if (!$product) return;

    $sku = $product->get_sku();
    if (!$sku) return;

    $info_existencia = obtener_existencia_almacenes_ctonline($sku);
    if (!$info_existencia) {
        echo '<p style="color:red; font-weight:bold;">Error: Funciones de API no disponibles.</p>';
        return;
    }

    echo '<div class="ctonline-existencia-info" style="margin-top:10px;">';
    echo '<p><strong>Existencia total:</strong> ' . esc_html($info_existencia['existencia_total']) . '</p>';
    echo '<p><strong>Almacenes:</strong> ' . esc_html($info_existencia['detalle_almacenes']) . '</p>';
    echo '</div>';
}


// --- FIN ---


// Función auxiliar para obtener existencia total del SKU desde API
function obtener_existencias_ctonline($sku) {
    if (empty($sku)) return false;

    try {
        $token = crearNuevoToken();
        $respuesta = servicioApi('GET', 'existencia/promociones/' . urlencode($sku), null, $token);

        if (!isset($respuesta->almacenes) || !is_array($respuesta->almacenes)) return false;

        $existencia_total = 0;
        foreach ($respuesta->almacenes as $almObj) {
            foreach ($almObj as $alm => $cantidad) {
                if ($alm !== 'promocion' && is_numeric($cantidad)) {
                    $existencia_total += intval($cantidad);
                }
            }
        }

        return ['existencia_total' => $existencia_total];
    } catch (Exception $e) {
        return false;
    }
}




// Este código consulta masivamente existencias y precios, los guarda en caché, y muestra precios/agotado sin hacer llamadas en tiempo real

// CRON: Ejecuta cada hora para cargar precios y existencias en caché
add_action('ctonline_precargar_existencias_y_precios_evento', 'ctonline_precargar_existencias_y_precios');
if (!wp_next_scheduled('ctonline_precargar_existencias_y_precios_evento')) {
    wp_schedule_event(time(), 'hourly', 'ctonline_precargar_existencias_y_precios_evento');
}

function ctonline_precargar_existencias_y_precios() {
    try {
        $token = crearNuevoToken();
        $respuesta = servicioApi('GET', 'existencia/promociones', null, $token);

        if (!is_array($respuesta)) {
            // Si la respuesta no es válida, guardar array vacío para evitar errores en el shortcode
            set_transient('ct_promociones_completo', [], 3600);
            return;
        }

        $promociones = [];

        foreach ($respuesta as $item) {
            $sku = $item->codigo ?? null;
            if (!$sku) continue;

            // --- EXISTENCIA ---
            $existencia_total = 0;
            $detalle_almacenes = [];
            if (isset($item->almacenes) && is_array($item->almacenes)) {
                foreach ($item->almacenes as $almObj) {
                    foreach ($almObj as $alm => $cantidad) {
                        if ($alm !== 'promocion' && is_numeric($cantidad)) {
                            $cantidad_num = intval($cantidad);
                            $detalle_almacenes[] = "$alm: $cantidad_num";
                            $existencia_total += $cantidad_num;
                        }
                    }
                }
            }

            set_transient('ct_existencia_almacenes_' . sanitize_key($sku), [
                'existencia_total' => $existencia_total,
                'detalle_almacenes' => count($detalle_almacenes) ? implode(', ', $detalle_almacenes) : 'Sin disponibilidad'
            ], 3600);

            // --- PRECIOS ---
            if (!isset($item->precio)) continue;

            $precio_normal = floatval($item->precio);
            $precio_promocion = null;
            $promo_ini = null;
            $promo_fin = null;

            if (isset($item->almacenes[0]->promocion->precio)) {
                $precio_promocion = floatval($item->almacenes[0]->promocion->precio);
                $promo_ini = $item->almacenes[0]->promocion->vigente->ini ?? null;
                $promo_fin = $item->almacenes[0]->promocion->vigente->fin ?? null;
            }

            $moneda = strtoupper($item->moneda ?? 'MXN');

            // Si es USD, convertir a MXN
            if ($moneda === 'USD') {
                $tipo_cambio = obtener_tipo_cambio_cacheado($token);
                $precio_normal *= $tipo_cambio;
                if ($precio_promocion) $precio_promocion *= $tipo_cambio;
            }

            $config = obtener_config_ctonline();
            $iva = isset($config['iva']) ? floatval($config['iva']) / 100 : 0.16;
            $margen = isset($config['margen']) ? floatval($config['margen']) / 100 : 0.20;

            $precio_normal_ajustado = round($precio_normal * (1 + $iva) * (1 + $margen), 2);
            $precio_promocion_ajustado = $precio_promocion ? round($precio_promocion * (1 + $iva) * (1 + $margen), 2) : null;

            set_transient('ct_precio_completo_' . sanitize_key($sku), [
                'precio_normal_ajustado' => $precio_normal_ajustado,
                'precio_ajustado' => $precio_promocion_ajustado ?? $precio_normal_ajustado,
                'promocion' => $precio_promocion_ajustado !== null,
                'promo_ini' => $promo_ini,
                'promo_fin' => $promo_fin
            ], 3600);

            // 👉 Añadir al listado general de promociones
            if ($precio_promocion_ajustado !== null && $promo_ini && $promo_fin) {
                $promociones[] = [
                    'sku' => $sku,
                    'precio_normal' => $precio_normal_ajustado,
                    'precio_promocion' => $precio_promocion_ajustado,
                    'promo_ini' => $promo_ini,
                    'promo_fin' => $promo_fin
                ];
            }
        }

        // ✅ Siempre guardar el transient, aunque esté vacío
        set_transient('ct_promociones_completo', $promociones, 3600);

    } catch (Exception $e) {
        error_log("Error en precarga masiva CTOnline: " . $e->getMessage());
        // Para evitar errores en shortcodes, guardar array vacío si falla algo
        set_transient('ct_promociones_completo', [], 3600);
    }
}


function obtener_tipo_cambio_cacheado($token) {
    $cache_key = 'ct_tipo_cambio_usd_mxn';
    $valor = get_transient($cache_key);
    if ($valor !== false) return $valor;

    $tipoCambioObj = servicioApi('GET', 'pedido/tipoCambio', null, $token);
    if (isset($tipoCambioObj->tipoCambio) && is_numeric($tipoCambioObj->tipoCambio)) {
        $valor = floatval($tipoCambioObj->tipoCambio);
        set_transient($cache_key, $valor, 3600); // Cache por 1 hora
        return $valor;
    }

    return 1; // Por defecto, si no se puede obtener el tipo de cambio
}




//------------------------




// ✅ Hook principal para renderizar almacenes y cotizar paquetería por almacén




// ============================================================
// ✅ CHECKOUT:
// ALMACENES + PRODUCTOS INDIVIDUALES + COTIZACIÓN
// ============================================================

////PARTE DEL CHECKOUT NUEVA

// ============================================================================
// 🛒 CARGAR UNA COTIZACIÓN EXISTENTE AL CARRITO
// ============================================================================
//
// URL:
// https://cyberdepot.com.mx/checkout/?cotizacion=123
//
// Recupera los mismos productos y cantidades de la cotización.
// ============================================================================

add_action('wp_loaded', 'ctonline_cargar_cotizacion_al_carrito', 20);

function ctonline_cargar_cotizacion_al_carrito() {

    if ( is_admin() ) {
        return;
    }

    if ( empty($_GET['cotizacion']) ) {
        return;
    }

    if ( ! function_exists('WC') || ! WC()->cart ) {
        return;
    }

    $cotizacion_id = absint($_GET['cotizacion']);

    if ( ! $cotizacion_id ) {
        return;
    }

    $order = wc_get_order($cotizacion_id);

    if ( ! $order ) {
        return;
    }

    // Solo permitir cotizaciones
    if ( $order->get_status() !== 'cotizacion' ) {
        return;
    }

    // Evitar cargar la misma cotización varias veces
    if (
        WC()->session &&
        WC()->session->get('ctonline_cotizacion_cargada') == $cotizacion_id
    ) {
        return;
    }

    // Vaciar carrito actual
    WC()->cart->empty_cart();

    $productos_finales = $order->get_meta(
        '_ctonline_productos_finales',
        true
    );

    $productos = json_decode(
        $productos_finales,
        true
    );

    if ( ! is_array($productos) || empty($productos) ) {
        return;
    }

    foreach ( $productos as $producto ) {

        $sku = isset($producto['producto'])
            ? sanitize_text_field($producto['producto'])
            : '';

        $cantidad = isset($producto['cantidad'])
            ? absint($producto['cantidad'])
            : 0;

        if ( empty($sku) || $cantidad <= 0 ) {
            continue;
        }

        $product_id = wc_get_product_id_by_sku($sku);

        if ( ! $product_id ) {
            continue;
        }

        $product = wc_get_product($product_id);

        if ( ! $product ) {
            continue;
        }

        WC()->cart->add_to_cart(
            $product_id,
            $cantidad
        );
    }

    // Guardar referencia de la cotización
    WC()->session->set(
        'ctonline_cotizacion_cargada',
        $cotizacion_id
    );

    WC()->session->set(
        'ctonline_cotizacion_original',
        $cotizacion_id
    );

    // Recuperar envío guardado
    $envio = floatval(
        $order->get_meta(
            '_ctonline_envio_costo',
            true
        )
    );

    $paqueteria = $order->get_meta(
        '_paqueteria_seleccionada',
        true
    );

    WC()->session->set(
        'ct_envio_precio',
        $envio
    );

    WC()->session->set(
        'paqueteria_seleccionada',
        $paqueteria
    );
}


// ============================================================================
// 🔄 EVITAR RECARGAR COTIZACIÓN EN CADA UPDATE_CHECKOUT
// ============================================================================

add_action(
    'woocommerce_before_checkout_form',
    function() {

        if (
            WC()->session &&
            WC()->session->get('ctonline_cotizacion_original')
        ) {

            echo '<div style="
                background:#e8f5e9;
                border:1px solid #81c784;
                padding:12px;
                margin-bottom:15px;
                border-radius:5px;
                font-weight:bold;
            ">
                🛒 Estás comprando una cotización existente.
                Verifica el envío y la paquetería antes de realizar el pedido.
            </div>';
        }
    }
);


// ============================================================================
// 🚚 CHECKOUT:
// ALMACENES + PRODUCTOS INDIVIDUALES + COTIZACIÓN
// ============================================================================

add_action(
    'woocommerce_checkout_after_order_review',
    function () {

        $cart = WC()->cart->get_cart();

        if ( empty($cart) ) {
            return;
        }

        $token = crearNuevoToken();

        if ( empty($token) ) {

            echo '<p style="color:red;font-weight:bold;">
                Error al obtener token de API CT Online.
            </p>';

            return;
        }

        $productos_completos = [];

        foreach ( $cart as $item ) {

            $product = $item['data'];

            $sku      = $product->get_sku();
            $nombre   = $product->get_name();
            $cantidad = $item['quantity'];

            $res = servicioApi(
                'GET',
                'existencia/promociones/' . $sku,
                null,
                $token
            );

            $almacenes_disponibles = [];

            if ( ! empty($res->almacenes) ) {

                foreach ( $res->almacenes as $alm ) {

                    foreach ( $alm as $clave => $exist ) {

                        if (
                            strtolower($clave) === 'promocion'
                        ) {
                            continue;
                        }

                        if ( $exist >= $cantidad ) {

                            $almacenes_disponibles[] =
                                $clave;
                        }
                    }
                }
            }

            $productos_completos[] = [

                'sku' =>
                    $sku,

                'nombre' =>
                    $nombre,

                'cantidad' =>
                    $cantidad,

                'precio' =>
                    isset($res->precio)
                        ? floatval($res->precio)
                        : floatval($product->get_price()),

                'moneda' =>
                    isset($res->moneda)
                        ? $res->moneda
                        : 'MXN',

                'almacenes' =>
                    $almacenes_disponibles
            ];
        }


        // ====================================================================
        // BUSCAR ALMACÉN COMÚN
        // ====================================================================

        $interseccion = null;

        foreach ( $productos_completos as $producto ) {

            if ( empty($producto['almacenes']) ) {
                continue;
            }

            if ( is_null($interseccion) ) {

                $interseccion =
                    $producto['almacenes'];

            } else {

                $interseccion =
                    array_intersect(
                        $interseccion,
                        $producto['almacenes']
                    );
            }
        }


        $productos_por_almacen = [];
        $productos_finales     = [];


        // ====================================================================
        // TODOS EN UN MISMO ALMACÉN
        // ====================================================================

        if ( ! empty($interseccion) ) {

            $almacen_comun =
                reset($interseccion);

            foreach (
                $productos_completos
                as $producto
            ) {

                $productos_por_almacen[
                    $almacen_comun
                ][] = [

                    'producto' =>
                        $producto['sku'],

                    'nombre' =>
                        $producto['nombre'],

                    'cantidad' =>
                        $producto['cantidad'],

                    'precio' =>
                        $producto['precio'],

                    'moneda' =>
                        $producto['moneda'],

                    'almacen' =>
                        $almacen_comun
                ];


                $productos_finales[] = [

                    'producto' =>
                        $producto['sku'],

                    'cantidad' =>
                        $producto['cantidad'],

                    'precio' =>
                        $producto['precio'],

                    'moneda' =>
                        $producto['moneda'],

                    'almacen' =>
                        $almacen_comun
                ];
            }


        } else {

            // =================================================================
            // CADA PRODUCTO EN EL PRIMER ALMACÉN DISPONIBLE
            // =================================================================

            foreach (
                $productos_completos
                as $producto
            ) {

                if ( empty($producto['almacenes']) ) {
                    continue;
                }

                $almacen_individual =
                    $producto['almacenes'][0];


                $productos_por_almacen[
                    $almacen_individual
                ][] = [

                    'producto' =>
                        $producto['sku'],

                    'nombre' =>
                        $producto['nombre'],

                    'cantidad' =>
                        $producto['cantidad'],

                    'precio' =>
                        $producto['precio'],

                    'moneda' =>
                        $producto['moneda'],

                    'almacen' =>
                        $almacen_individual
                ];


                $productos_finales[] = [

                    'producto' =>
                        $producto['sku'],

                    'cantidad' =>
                        $producto['cantidad'],

                    'precio' =>
                        $producto['precio'],

                    'moneda' =>
                        $producto['moneda'],

                    'almacen' =>
                        $almacen_individual
                ];
            }
        }


        // ====================================================================
        // CONTENEDOR
        // ====================================================================

        echo '<div id="ct-envio-container">';


        if ( count($productos_por_almacen) > 1 ) {

            echo '<div
                id="mensaje-varios-almacenes"
                style="color:red;font-weight:bold;margin-bottom:10px;"
            >
                SU PEDIDO SE ENCUENTRA EN VARIOS ALMACENES
            </div>';
        }


        echo '
            <h4
                class="ct-texto-guiones"
                style="margin:0 0 10px 20px;font-size:18px;"
            >
                1- Elija los almacenes para completar su pedido.<br>
                2- Puede seleccionar o deseleccionar productos.<br>
                3- Favor de <strong>seleccionar paquetería</strong>
                para poder <strong>realizar pedido</strong>.
            </h4>
        ';


        // ====================================================================
        // LISTA ALMACENES
        // ====================================================================

        echo '<ul
            id="almacenes-lista"
            style="list-style:none;padding-left:20px;"
        >';


        foreach (
            $productos_por_almacen
            as $clave => $productos
        ) {

            echo '<li style="margin-bottom:20px;">';


            echo '
                <label>

                    <input
                        type="checkbox"
                        class="almacen-checkbox"
                        value="' . esc_attr($clave) . '"
                        checked
                    >

                    <strong>Almacén:</strong>
                    ' . esc_html($clave) . '

                </label>

                <br>
            ';


            // =================================================================
            // PRODUCTOS
            // =================================================================

            foreach (
                $productos
                as $prod
            ) {

                echo '
                    <div
                        class="producto"
                        data-sku="' . esc_attr(
                            $prod['producto']
                        ) . '"

                        data-cantidad="' . esc_attr(
                            $prod['cantidad']
                        ) . '"

                        data-precio="' . esc_attr(
                            $prod['precio']
                        ) . '"

                        data-moneda="' . esc_attr(
                            $prod['moneda']
                        ) . '"

                        data-almacen="' . esc_attr(
                            $clave
                        ) . '"
                    >

                        <label style="cursor:pointer;">

                            <input
                                type="checkbox"
                                class="producto-checkbox"
                                checked

                                data-sku="' . esc_attr(
                                    $prod['producto']
                                ) . '"

                                data-cantidad="' . esc_attr(
                                    $prod['cantidad']
                                ) . '"

                                data-precio="' . esc_attr(
                                    $prod['precio']
                                ) . '"

                                data-moneda="' . esc_attr(
                                    $prod['moneda']
                                ) . '"

                                data-almacen="' . esc_attr(
                                    $clave
                                ) . '"
                            >

                            • ' . esc_html(
                                $prod['nombre']
                            ) . '

                            (' . esc_html(
                                $prod['producto']
                            ) . ')

                            (x' . esc_html(
                                $prod['cantidad']
                            ) . ')

                        </label>

                    </div>
                ';
            }


            // =================================================================
            // BOTÓN COTIZAR
            // =================================================================

            echo '
                <button
                    type="button"
                    class="btn-cotizar-individual"
                    data-almacen="' . esc_attr($clave) . '"

                    style="
                        background:#ff6600;
                        color:#fff;
                        padding:6px 14px;
                        margin:10px 0;
                        border:none;
                        border-radius:4px;
                        cursor:pointer;
                        font-size:13px;
                    "
                >
                    Cotizar envío
                </button>
            ';


            // =================================================================
            // RESULTADO
            // =================================================================

            echo '
                <div
                    class="select-paquete-wrapper"
                    id="cotizacion-' . esc_attr($clave) . '"
                    style="margin-top:8px;"
                >
                </div>
            ';


            echo '</li>';
        }


        echo '</ul>';


        // ====================================================================
        // TOTAL ENVÍO
        // ====================================================================

        echo '
            <div
                style="
                    display:flex;
                    flex-direction:column;
                    align-items:flex-start;
                    gap:10px;
                    margin-top:10px;
                "
            >

                <div
                    id="suma-paquetes-contenedor"
                    style="font-weight:bold;font-size:16px;"
                >
                    🚚 Total envío:
                    <strong>$0.00</strong>
                </div>

            </div>
        ';


        // ====================================================================
        // HIDDEN
        // ====================================================================

        echo '
            <input
                type="hidden"
                name="paqueteria_envio"
                id="paqueteria_envio_hidden"
                value=""
            >

            <input
                type="hidden"
                name="ct_envio_precio"
                id="ct_envio_precio_hidden"
                value=""
            >

            <input
                type="hidden"
                name="almacenes_seleccionados"
                id="almacenes_seleccionados_hidden"
                value=""
            >

            <input
                type="hidden"
                name="ctonline_productos_finales"
                id="ctonline_productos_finales_hidden"
                value="' .
                    esc_attr(
                        json_encode(
                            $productos_finales,
                            JSON_UNESCAPED_UNICODE
                        )
                    ) .
            '"
            >
        ';


        echo '</div>';
        ?>

        <script>

        jQuery(document).ready(function($) {

            // ================================================================
            // PRODUCTOS
            // ================================================================

            const productos = <?php
                echo json_encode(
                    $productos_finales,
                    JSON_UNESCAPED_UNICODE
                );
            ?>;


            // ================================================================
            // MEMORIA
            // ================================================================

            if (
                typeof window.ctProductosSeleccionados ===
                'undefined'
            ) {
                window.ctProductosSeleccionados = null;
            }


            if (
                typeof window.ctPaqueteriasSeleccionadas ===
                'undefined'
            ) {
                window.ctPaqueteriasSeleccionadas = {};
            }


            // ================================================================
            // CLAVE PRODUCTO
            // ================================================================

            function claveProducto(sku, almacen) {

                return String(almacen) +
                    '|' +
                    String(sku);
            }


            // ================================================================
            // RESTAURAR PRODUCTOS
            // ================================================================

            function restaurarSeleccionProductos() {

                if (
                    !Array.isArray(
                        window.ctProductosSeleccionados
                    )
                ) {
                    return;
                }


                $('.producto-checkbox').each(
                    function() {

                        const sku =
                            String(
                                $(this).data('sku')
                            );

                        const almacen =
                            String(
                                $(this).data('almacen')
                            );

                        const clave =
                            claveProducto(
                                sku,
                                almacen
                            );


                        $(this).prop(
                            'checked',
                            window.ctProductosSeleccionados
                                .includes(clave)
                        );
                    }
                );


                $('.almacen-checkbox').each(
                    function() {

                        const almacen =
                            String(
                                $(this).val()
                            );


                        const cantidad =
                            $('.producto-checkbox[data-almacen="' +
                                almacen +
                                '"]:checked'
                            ).length;


                        $(this).prop(
                            'checked',
                            cantidad > 0
                        );
                    }
                );
            }


            // ================================================================
            // RESTAURAR PAQUETERÍAS
            // ================================================================

            function restaurarPaqueterias() {

                if (
                    !window.ctPaqueteriasSeleccionadas ||
                    typeof window.ctPaqueteriasSeleccionadas !==
                    'object'
                ) {
                    return;
                }


                $.each(
                    window.ctPaqueteriasSeleccionadas,
                    function(almacen, info) {

                        if (
                            !info ||
                            !info.paqueteria
                        ) {
                            return;
                        }


                        $(
                            '.paqueteria-radio[data-almacen="' +
                            almacen +
                            '"]'
                        ).each(
                            function() {

                                if (
                                    String(
                                        $(this).val()
                                    ).toLowerCase()
                                    ===
                                    String(
                                        info.paqueteria
                                    ).toLowerCase()
                                ) {

                                    $(this).prop(
                                        'checked',
                                        true
                                    );
                                }
                            }
                        );
                    }
                );
            }


            // ================================================================
            // ACTUALIZAR PRODUCTOS
            // ================================================================

            function actualizarProductosFinales() {

                let productosSeleccionados = [];

                let almacenesSeleccionados = [];

                let clavesSeleccionadas = [];


                $('.producto-checkbox:checked').each(
                    function() {

                        const $checkbox = $(this);


                        const sku =
                            String(
                                $checkbox.data('sku')
                            );


                        const cantidad =
                            parseInt(
                                $checkbox.data(
                                    'cantidad'
                                ),
                                10
                            ) || 0;


                        const precio =
                            parseFloat(
                                $checkbox.data(
                                    'precio'
                                )
                            ) || 0;


                        const moneda =
                            $checkbox.data(
                                'moneda'
                            ) || 'MXN';


                        const almacen =
                            String(
                                $checkbox.data(
                                    'almacen'
                                )
                            );


                        productosSeleccionados.push({

                            producto: sku,

                            cantidad: cantidad,

                            precio: precio,

                            moneda: moneda,

                            almacen: almacen

                        });


                        clavesSeleccionadas.push(
                            claveProducto(
                                sku,
                                almacen
                            )
                        );


                        if (
                            !almacenesSeleccionados.includes(
                                almacen
                            )
                        ) {

                            almacenesSeleccionados.push(
                                almacen
                            );
                        }
                    }
                );


                window.ctProductosSeleccionados =
                    clavesSeleccionadas;


                $('#ctonline_productos_finales_hidden')
                    .val(
                        JSON.stringify(
                            productosSeleccionados
                        )
                    );


                $('#almacenes_seleccionados_hidden')
                    .val(
                        JSON.stringify(
                            almacenesSeleccionados
                        )
                    );


                return {

                    productos:
                        productosSeleccionados,

                    almacenes:
                        almacenesSeleccionados
                };
            }


            // ================================================================
            // 🚚 COTIZAR
            // ================================================================

            $(document).on(
                'click',
                '.btn-cotizar-individual',
                function() {

                    const $boton = $(this);


                    const almacen =
                        String(
                            $boton.data('almacen')
                        );


                    const cp =
                        $('[name="billing_postcode"]')
                        .val();


                    if (
                        !cp ||
                        cp.length !== 5
                    ) {

                        alert(
                            'Por favor ingrese un código postal válido antes de cotizar.'
                        );

                        return;
                    }


                    const actuales =
                        actualizarProductosFinales();


                    const productosDelAlmacen =
                        actuales.productos.filter(
                            function(producto) {

                                return String(
                                    producto.almacen
                                ) === String(
                                    almacen
                                );
                            }
                        );


                    if (
                        productosDelAlmacen.length === 0
                    ) {

                        alert(
                            'No hay productos seleccionados para este almacén.'
                        );

                        return;
                    }


                    delete window
                        .ctPaqueteriasSeleccionadas[
                            almacen
                        ];


                    $('.paqueteria-radio[data-almacen="' +
                        almacen +
                        '"]'
                    ).prop(
                        'checked',
                        false
                    );


                    $('#cotizacion-' + almacen)
                        .html(
                            '<em style="color:#555;">Cotizando...</em>'
                        );


                    $boton.prop(
                        'disabled',
                        true
                    );


                    $.post(
                        '<?php echo esc_url(
                            admin_url('admin-ajax.php')
                        ); ?>',
                        {

                            action:
                                'cotizar_envio_ctonline',

                            cp:
                                cp,

                            productos:
                                JSON.stringify(
                                    actuales.productos
                                ),

                            almacenes:
                                JSON.stringify([
                                    almacen
                                ])
                        },

                        function(html) {

                            $('#cotizacion-' + almacen)
                                .html(html);


                            $boton.prop(
                                'disabled',
                                false
                            );


                            verificarPaqueteriasSeleccionadas();

                            verificarBotonRealizarPedido();
                        }
                    )
                    .fail(
                        function() {

                            $('#cotizacion-' + almacen)
                                .html(
                                    '<p style="color:red;">' +
                                    '❌ Error al consultar el servicio de paquetería.' +
                                    '</p>'
                                );


                            $boton.prop(
                                'disabled',
                                false
                            );
                        }
                    );
                }
            );


            // ================================================================
            // PRODUCTO
            // ================================================================

            $(document).on(
                'change',
                '.producto-checkbox',
                function() {

                    const $producto =
                        $(this);


                    const almacen =
                        String(
                            $producto.data(
                                'almacen'
                            )
                        );


                    const productosDelAlmacen =
                        $('.producto-checkbox[data-almacen="' +
                            almacen +
                            '"]:checked'
                        );


                    if (
                        productosDelAlmacen.length === 0
                    ) {

                        $('.almacen-checkbox[value="' +
                            almacen +
                            '"]'
                        ).prop(
                            'checked',
                            false
                        );


                        $('.paqueteria-radio[data-almacen="' +
                            almacen +
                            '"]'
                        ).prop(
                            'checked',
                            false
                        );


                        $('#cotizacion-' + almacen)
                            .empty();


                        delete window
                            .ctPaqueteriasSeleccionadas[
                                almacen
                            ];

                    } else {

                        $('.almacen-checkbox[value="' +
                            almacen +
                            '"]'
                        ).prop(
                            'checked',
                            true
                        );


                        $('.paqueteria-radio[data-almacen="' +
                            almacen +
                            '"]'
                        ).prop(
                            'checked',
                            false
                        );


                        delete window
                            .ctPaqueteriasSeleccionadas[
                                almacen
                            ];


                        $('#cotizacion-' + almacen)
                            .html(
                                '<em style="color:#555;">' +
                                'Debe volver a cotizar el envío debido al cambio de productos.' +
                                '</em>'
                            );
                    }


                    actualizarProductosFinales();

                    verificarPaqueteriasSeleccionadas();

                    verificarBotonRealizarPedido();
                }
            );


            // ================================================================
            // ALMACÉN
            // ================================================================

            $(document).on(
                'change',
                '.almacen-checkbox',
                function() {

                    const almacen =
                        String(
                            $(this).val()
                        );


                    if (
                        !$(this).is(':checked')
                    ) {

                        $('.producto-checkbox[data-almacen="' +
                            almacen +
                            '"]'
                        ).prop(
                            'checked',
                            false
                        );


                        $('.paqueteria-radio[data-almacen="' +
                            almacen +
                            '"]'
                        ).prop(
                            'checked',
                            false
                        );


                        $('#cotizacion-' + almacen)
                            .empty();


                        delete window
                            .ctPaqueteriasSeleccionadas[
                                almacen
                            ];

                    } else {

                        $('.producto-checkbox[data-almacen="' +
                            almacen +
                            '"]'
                        ).prop(
                            'checked',
                            true
                        );


                        $('.paqueteria-radio[data-almacen="' +
                            almacen +
                            '"]'
                        ).prop(
                            'checked',
                            false
                        );


                        delete window
                            .ctPaqueteriasSeleccionadas[
                                almacen
                            ];


                        $('#cotizacion-' + almacen)
                            .empty();
                    }


                    actualizarProductosFinales();

                    verificarPaqueteriasSeleccionadas();

                    verificarBotonRealizarPedido();
                }
            );


            // ================================================================
            // 🚚 PAQUETERÍAS
            // ================================================================

            function verificarPaqueteriasSeleccionadas() {

                const selected = {};

                let suma = 0;


                $('.paqueteria-radio:checked').each(
                    function() {

                        const almacen =
                            String(
                                $(this).data(
                                    'almacen'
                                )
                            );


                        const paqueteria =
                            $(this).val();


                        const precio =
                            parseFloat(
                                $(this).data(
                                    'precio'
                                )
                            ) || 0;


                        selected[almacen] = {

                            paqueteria:
                                paqueteria,

                            precio:
                                precio
                        };


                        suma += precio;
                    }
                );


                window.ctPaqueteriasSeleccionadas =
                    selected;


                $('#paqueteria_envio_hidden')
                    .val(
                        JSON.stringify(
                            selected
                        )
                    );


                $('#ct_envio_precio_hidden')
                    .val(
                        suma.toFixed(2)
                    );


                $('#suma-paquetes-contenedor')
                    .html(
                        '🚚 Total envío: <strong>$' +
                        suma.toFixed(2) +
                        '</strong>'
                    );


                verificarBotonRealizarPedido();


                // ============================================================
                // GUARDAR SESIÓN
                // ============================================================

                $.post(
                    '<?php echo esc_url(
                        admin_url('admin-ajax.php')
                    ); ?>',
                    {

                        action:
                            'guardar_envio_ctonline',

                        precio:
                            suma.toFixed(2),

                        paqueterias:
                            JSON.stringify(
                                selected
                            )
                    },

                    function(response) {

                        if (
                            response.success
                        ) {

                            $(document.body)
                                .trigger(
                                    'update_checkout'
                                );
                        }
                    }
                );
            }


            // ================================================================
            // CAMBIO PAQUETERÍA
            // ================================================================

            $(document).on(
                'change',
                '.paqueteria-radio',
                function() {

                    verificarPaqueteriasSeleccionadas();
                }
            );


            // ================================================================
            // 🔒 BLOQUEAR REALIZAR PEDIDO
            // ================================================================

            function verificarBotonRealizarPedido() {

                let valido = true;

                let almacenesSeleccionados = [];


                $('.almacen-checkbox:checked').each(
                    function() {

                        almacenesSeleccionados.push(
                            String(
                                $(this).val()
                            )
                        );
                    }
                );


                if (
                    almacenesSeleccionados.length === 0
                ) {

                    valido = false;
                }


                $.each(
                    almacenesSeleccionados,
                    function(index, almacen) {

                        const productosDelAlmacen =
                            $('.producto-checkbox[data-almacen="' +
                                almacen +
                                '"]:checked'
                            );


                        if (
                            productosDelAlmacen.length === 0
                        ) {

                            valido = false;

                            return false;
                        }


                        const radio =
                            $(
                                '.paqueteria-radio[data-almacen="' +
                                almacen +
                                '"]:checked'
                            );


                        if (
                            radio.length === 0
                        ) {

                            valido = false;

                            return false;
                        }


                        const precio =
                            parseFloat(
                                radio.data('precio')
                            ) || 0;


                        if (
                            precio <= 0
                        ) {

                            valido = false;

                            return false;
                        }
                    }
                );


                const $boton =
                    $('#place_order');


                if (
                    valido
                ) {

                    $boton.prop(
                        'disabled',
                        false
                    );


                    $boton.css({

                        opacity:
                            '1',

                        cursor:
                            'pointer'
                    });

                } else {

                    $boton.prop(
                        'disabled',
                        true
                    );


                    $boton.css({

                        opacity:
                            '0.5',

                        cursor:
                            'not-allowed'
                    });
                }
            }


            // ================================================================
            // UPDATE CHECKOUT
            // ================================================================

            $(document.body).on(
                'updated_checkout',
                function() {

                    restaurarSeleccionProductos();

                    restaurarPaqueterias();

                    actualizarProductosFinales();

                    verificarBotonRealizarPedido();
                }
            );


            // ================================================================
            // INICIAL
            // ================================================================

            actualizarProductosFinales();

            verificarBotonRealizarPedido();


            // ================================================================
            // SI VENIMOS DE UNA COTIZACIÓN
            // ================================================================

            <?php if (
                WC()->session &&
                WC()->session->get('ctonline_cotizacion_original')
            ): ?>

                setTimeout(
                    function() {

                        console.log(
                            '🛒 Cotización cargada al checkout.'
                        );

                        // No confiar automáticamente en el envío anterior.
                        // El cliente debe cotizar nuevamente para validar
                        // precio y disponibilidad actual.

                        $('.btn-cotizar-individual')
                            .first()
                            .focus();

                    },
                    500
                );

            <?php endif; ?>

        });

        </script>

        <?php
    }
);


// ============================================================================
// 🎨 ESTILOS
// ============================================================================

add_action(
    'wp_head',
    function () {
        ?>

        <style>

        #ct-envio-container {
            max-width:100%;
            width:100%;
            margin-top:10px;
            margin-bottom:30px;
        }

        @media (min-width:768px) {

            #ct-envio-container {

                float:left;

                width:48%;

                margin-left:0;
            }
        }

        #mensaje-varios-almacenes {

            color:red;

            font-weight:bold;

            margin:20px 0 10px 0;

            padding-left:5px;
        }

        #almacenes-lista {

            list-style:none;

            padding-left:5px;

            font-size:13px;
        }

        #almacenes-lista li {

            margin-bottom:10px;

            padding-left:0;
        }

        .producto {

            margin-left:0;

            font-size:12.5px;

            line-height:1.3;

            white-space:normal;
        }

        .producto-checkbox {

            margin-right:4px;
        }

        .ct-texto-guiones {

            padding-left:5px;

            font-size:13px;
        }

        </style>

        <?php
    }
);


// ============================================================================
// 🚚 AJAX COTIZAR ENVÍO
// ============================================================================

add_action(
    'wp_ajax_cotizar_envio_ctonline',
    'cotizar_envio_ctonline'
);

add_action(
    'wp_ajax_nopriv_cotizar_envio_ctonline',
    'cotizar_envio_ctonline'
);


function cotizar_envio_ctonline() {

    $cp =
        sanitize_text_field(
            $_POST['cp'] ?? ''
        );


    $productos =
        json_decode(
            stripslashes(
                $_POST['productos'] ?? '[]'
            ),
            true
        );


    $almacenes =
        json_decode(
            stripslashes(
                $_POST['almacenes'] ?? '[]'
            ),
            true
        );


    if (
        empty($cp) ||
        empty($productos) ||
        empty($almacenes)
    ) {

        echo '<p style="color:red;">
            Datos incompletos para cotización.
        </p>';

        wp_die();
    }


    $token =
        crearNuevoToken();


    if ( empty($token) ) {

        echo '<p style="color:red;">
            Error al obtener token para cotización.
        </p>';

        wp_die();
    }


    $mostrar_json_crudo =
        get_option(
            'ctonline_mostrar_json_crudo',
            'no'
        );


    foreach (
        $almacenes
        as $almacen
    ) {

        $productos_filtrados =
            array_filter(
                $productos,
                function($prod) use ($almacen) {

                    // CORRECCIÓN: String() no existe en PHP.
                    // Se usa strval() para comparar correctamente.

                    return isset(
                        $prod['almacen']
                    )
                    &&
                    strval($prod['almacen']) ===
                    strval($almacen);
                }
            );


        if (
            empty($productos_filtrados)
        ) {

            echo '<p style="color:red;">
                No hay productos seleccionados
                para el almacén ' .
                esc_html($almacen) .
                '.
            </p>';

            continue;
        }


        $json_payload =
            json_encode(
                [

                    'destino' =>
                        strval($cp),

                    'productos' =>
                        array_values(
                            $productos_filtrados
                        )

                ],
                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE
            );


      
try {

    $respuesta = servicioApi(
        'POST',
        'paqueteria/cotizacion',
        $json_payload,
        $token
    );

} catch (Exception $e) {

    error_log(
        '❌ ERROR COTIZACION CT ONLINE: ' .
        $e->getMessage()
    );

    echo '<div style="
        color:red;
        background:#ffeaea;
        border:1px solid #ff0000;
        padding:10px;
        margin-top:10px;
    ">
        <strong>❌ Error al generar la cotización</strong><br><br>';

    echo esc_html(
        $e->getMessage()
    );

    echo '</div>';

    wp_die();
}




        if (
            $mostrar_json_crudo === 'yes'
        ) {

            echo '<pre>
<strong>Payload enviado:</strong>
' .
                esc_html(
                    $json_payload
                ) .
            '</pre>';


            echo '<pre>
<strong>Respuesta API:</strong>
' .
                esc_html(
                    print_r(
                        $respuesta,
                        true
                    )
                ) .
            '</pre>';
        }


        if (
            !empty(
                $respuesta->respuesta->cotizaciones
            )
        ) {

            $logos = [

                'estafeta' =>
                    'https://cyberdepot.com.mx/wp-content/uploads/2025/07/estafeta.jpg',

                'paquetexpress' =>
                    'https://cyberdepot.com.mx/wp-content/uploads/2025/07/paquetexpress.jpg'
            ];


            echo '
                <div
                    class="paqueteria-opciones"
                    data-almacen="' .
                    esc_attr($almacen) .
                    '"
                    style="margin-top:10px;"
                >
            ';


            foreach (
                $respuesta->respuesta->cotizaciones
                as $cot
            ) {

                $empresa =
                    strtolower(
                        $cot->empresa
                    );


                $precio =
                    floatval(
                        $cot->total
                    );


                $precio_fmt =
                    number_format(
                        $precio,
                        2
                    );


                $logo =
                    $logos[$empresa] ?? '';


                echo '
                    <label
                        class="paqueteria-opcion"
                        style="
                            display:flex;
                            align-items:center;
                            border:1px solid #ccc;
                            padding:10px;
                            margin-bottom:5px;
                            border-radius:5px;
                            cursor:pointer;
                        "
                    >';


                echo '
                    <input
                        type="radio"

                        name="paqueteria[' .
                        esc_attr($almacen) .
                        ']"

                        class="paqueteria-radio"

                        data-almacen="' .
                        esc_attr($almacen) .
                        '"

                        value="' .
                        esc_attr($cot->empresa) .
                        '"

                        data-precio="' .
                        esc_attr($precio) .
                        '"

                        style="margin-right:10px;"
                    >
                ';


                if ($logo) {

                    echo '
                        <img
                            src="' .
                            esc_url($logo) .
                            '"

                            alt="' .
                            esc_attr(
                                $cot->empresa
                            ) .
                            '"

                            style="
                                height:30px;
                                margin-right:10px;
                            "
                        >
                    ';
                }


                echo '
                    <span style="font-weight:bold;">
                        $' .
                        esc_html($precio_fmt) .
                    '</span>
                ';


                echo '</label>';
            }


            echo '</div>';

        } else {

            echo '<p style="color:red;">
                ❌ Sin cotizaciones para el almacén ' .
                esc_html($almacen) .
                '.
            </p>';
        }
    }


    wp_die();
}


// ============================================================================
// ============================================================================
// 💰 AGREGAR COSTO DE ENVÍO AL CARRITO
// ============================================================================

add_action(
    'woocommerce_cart_calculate_fees',
    function($cart) {

        if (
            is_admin() &&
            ! defined('DOING_AJAX')
        ) {
            return;
        }

        if (
            ! WC()->session
        ) {
            return;
        }

        $envio =
            floatval(
                WC()->session->get(
                    'ct_envio_precio',
                    0
                )
            );

        if (
            $envio > 0
        ) {

            $cart->add_fee(
                'Costo de envío',
                $envio,
                true
            );
        }
    }
);


// ============================================================================
// 🔒 FIJAR PRECIOS DE PRODUCTOS DE UNA COTIZACIÓN
// ============================================================================
// Cuando el cliente entra mediante:
// ?cotizacion=ID
//
// WooCommerce normalmente toma nuevamente el precio actual del producto.
//
// Este filtro hace que, SOLO cuando existe una cotización cargada,
// se utilice el precio que quedó guardado en dicha cotización.
// ============================================================================

add_action(
    'woocommerce_before_calculate_totals',
    'ctonline_aplicar_precios_cotizacion',
    20
);


function ctonline_aplicar_precios_cotizacion($cart) {

    if (
        is_admin() &&
        ! defined('DOING_AJAX')
    ) {
        return;
    }

    if (
        ! WC()->session
    ) {
        return;
    }

    // ================================================================
    // IDENTIFICAR COTIZACIÓN
    // ================================================================

    $cotizacion_id =
        absint(
            WC()->session->get(
                '_ctonline_cotizacion_origen',
                0
            )
        );


    if (
        !$cotizacion_id
    ) {
        return;
    }


    // ================================================================
    // OBTENER PEDIDO DE COTIZACIÓN
    // ================================================================

    $cotizacion =
        wc_get_order(
            $cotizacion_id
        );


    if (
        !$cotizacion
    ) {
        return;
    }


    // ================================================================
    // VERIFICAR QUE REALMENTE SEA COTIZACIÓN
    // ================================================================

    if (
        $cotizacion->get_status()
        !==
        'cotizacion'
    ) {
        return;
    }


    // ================================================================
    // VERIFICAR EXPIRACIÓN
    // ================================================================

    $fecha_expiracion =
        absint(
            $cotizacion->get_meta(
                '_ctonline_fecha_expiracion',
                true
            )
        );


    if (
        $fecha_expiracion &&
        time() > $fecha_expiracion
    ) {

        // Marcar como expirada en sesión
        WC()->session->set(
            '_ctonline_cotizacion_expirada',
            $cotizacion_id
        );

        return;
    }


    // ================================================================
    // PRODUCTOS GUARDADOS EN LA COTIZACIÓN
    // ================================================================

    $productos_cotizacion =
        $cotizacion->get_meta(
            '_ctonline_productos_cotizacion',
            true
        );


    if (
        empty($productos_cotizacion)
        ||
        !is_array($productos_cotizacion)
    ) {
        return;
    }


    // ================================================================
    // RECORRER CARRITO
    // ================================================================

    foreach (
        $cart->get_cart()
        as $cart_item_key => $cart_item
    ) {

        if (
            empty($cart_item['data'])
            ||
            !is_a(
                $cart_item['data'],
                'WC_Product'
            )
        ) {
            continue;
        }


        $product_id =
            $cart_item['product_id'];


        $variation_id =
            !empty($cart_item['variation_id'])
            ? $cart_item['variation_id']
            : 0;


        // ============================================================
        // BUSCAR PRODUCTO EN LA COTIZACIÓN
        // ============================================================

        foreach (
            $productos_cotizacion
            as $producto_cotizado
        ) {

            $id_guardado =
                isset(
                    $producto_cotizado['product_id']
                )
                ? absint(
                    $producto_cotizado['product_id']
                )
                : 0;


            $variation_guardada =
                isset(
                    $producto_cotizado['variation_id']
                )
                ? absint(
                    $producto_cotizado['variation_id']
                )
                : 0;


            // --------------------------------------------------------
            // COMPARAR PRODUCTO
            // --------------------------------------------------------

            if (
                $id_guardado !==
                absint($product_id)
            ) {
                continue;
            }


            // Si es una variación, también debe coincidir
            if (
                $variation_guardada !==
                absint($variation_id)
            ) {
                continue;
            }


            // ========================================================
            // PRECIO DE LA COTIZACIÓN
            // ========================================================

            if (
                isset(
                    $producto_cotizado['total']
                )
            ) {

                $total_cotizado =
                    floatval(
                        $producto_cotizado['total']
                    );


                $cantidad_cotizada =
                    isset(
                        $producto_cotizado['quantity']
                    )
                    ? max(
                        1,
                        intval(
                            $producto_cotizado['quantity']
                        )
                    )
                    : 1;


                // ====================================================
                // IMPORTANTE
                // ====================================================
                // "total" es el total de la línea.
                //
                // WooCommerce necesita el precio UNITARIO.
                // ====================================================

                $precio_unitario =
                    $total_cotizado /
                    $cantidad_cotizada;


                // ====================================================
                // APLICAR PRECIO CONGELADO
                // ====================================================

                $cart_item['data']->set_price(
                    $precio_unitario
                );


                // Debug
                error_log(
                    'COTIZACION - PRECIO FIJADO: Producto #' .
                    $product_id .
                    ' | Cantidad: ' .
                    $cantidad_cotizada .
                    ' | Precio cotización: $' .
                    $precio_unitario
                );
            }


            // Ya encontramos el producto
            break;
        }
    }
}


// ============================================================================
// 💾 GUARDAR ENVÍO EN SESIÓN
// ============================================================================

add_action(
    'wp_ajax_guardar_envio_ctonline',
    'guardar_envio_ctonline'
);

add_action(
    'wp_ajax_nopriv_guardar_envio_ctonline',
    'guardar_envio_ctonline'
);


function guardar_envio_ctonline() {

    $precio =
        isset($_POST['precio'])
        ? floatval($_POST['precio'])
        : 0;


    $paqueterias =
        isset($_POST['paqueterias'])
        ? stripslashes(
            $_POST['paqueterias']
        )
        : '{}';


    WC()->session->set(
        'ct_envio_precio',
        $precio
    );


    WC()->session->set(
        'paqueteria_seleccionada',
        $paqueterias
    );


    wp_send_json_success([

        'mensaje' =>
            'Sesión actualizada',

        'precio' =>
            $precio,

        'paqueterias' =>
            $paqueterias
    ]);
}


// ============================================================================
// 🧾 GUARDAR DATOS EN PEDIDO REAL
// ============================================================================

add_action(
    'woocommerce_checkout_create_order',
    function($order) {

        // ================================================================
        // PRODUCTOS FINALES
        // ================================================================

        if (
            isset(
                $_POST['ctonline_productos_finales']
            )
        ) {

            $productos_finales =
                stripslashes(
                    $_POST[
                        'ctonline_productos_finales'
                    ]
                );


            $order->update_meta_data(
                '_ctonline_productos_finales',
                $productos_finales
            );
        }


        // ================================================================
        // IDENTIFICAR SI VIENE DE COTIZACIÓN
        // ================================================================

        $cotizacion_origen =
            WC()->session
                ? absint(
                    WC()->session->get(
                        '_ctonline_cotizacion_origen',
                        0
                    )
                )
                : 0;


        if (
            $cotizacion_origen
        ) {

            $order->update_meta_data(
                '_ctonline_cotizacion_origen',
                $cotizacion_origen
            );
        }


        // ================================================================
        // PAQUETERÍAS
        // ================================================================

        if (
            isset(
                $_POST['paqueteria_envio']
            )
            &&
            !empty(
                $_POST['paqueteria_envio']
            )
        ) {

            $order->update_meta_data(
                '_paqueteria_seleccionada',
                stripslashes(
                    $_POST['paqueteria_envio']
                )
            );

        } else {

            $order->update_meta_data(
                '_paqueteria_seleccionada',
                WC()->session->get(
                    'paqueteria_seleccionada',
                    ''
                )
            );
        }


        // ================================================================
        // COSTO ENVÍO
        // ================================================================

        $envio =
            floatval(
                WC()->session->get(
                    'ct_envio_precio',
                    0
                )
            );


        if (
            $envio > 0
        ) {

            $order->update_meta_data(
                '_ctonline_envio_costo',
                $envio
            );


            $shipping_item =
                new WC_Order_Item_Shipping();


            $shipping_item->set_method_title(
                'Paquetería'
            );


            $shipping_item->set_method_id(
                'cotizacion_envio'
            );


            $shipping_item->set_total(
                $envio
            );


            $order->add_item(
                $shipping_item
            );
        }
    }
);


// ============================================================================
// 🧹 LIMPIAR SESIÓN DE COTIZACIÓN CUANDO SE CREA PEDIDO REAL
// ============================================================================

add_action(
    'woocommerce_checkout_order_processed',
    function($order_id) {

        if (
            ! WC()->session
        ) {
            return;
        }


        WC()->session->__unset(
            '_ctonline_cotizacion_cargada'
        );


        WC()->session->__unset(
            '_ctonline_cotizacion_original'
        );


        WC()->session->__unset(
            '_ctonline_cotizacion_origen'
        );


        WC()->session->__unset(
            '_ctonline_cotizacion_expirada'
        );


        // También limpiamos los datos de envío
        // para que no se reutilicen en otra compra.

        WC()->session->__unset(
            'ct_envio_precio'
        );


        WC()->session->__unset(
            'paqueteria_seleccionada'
        );


        WC()->session->__unset(
            '_ctonline_envio_costo'
        );


        WC()->session->__unset(
            '_paqueteria_seleccionada'
        );
    }
);
// ============================================================
// ✅ THANK YOU → GENERAR PEDIDO EN CT ONLINE
// ============================================================

add_action(
    'woocommerce_thankyou',
    function($order_id) {

        $order =
            wc_get_order(
                $order_id
            );


        if (!$order) {
            return;
        }


        $paqueterias =
            json_decode(
                $order->get_meta(
                    '_paqueteria_seleccionada'
                ),
                true
            );


        $productos_finales =
            json_decode(
                $order->get_meta(
                    '_ctonline_productos_finales'
                ),
                true
            );


        $mostrar_json_crudo =
            get_option(
                'ctonline_mostrar_json_crudo',
                'no'
            ) === 'yes';


        // ====================================================
        // VALIDAR PAQUETERÍAS
        // ====================================================

        if (
            !is_array($paqueterias) ||
            empty($paqueterias)
        ) {

            echo '<div style="color:red;">
                ❌ El campo
                _paqueteria_seleccionada
                no es válido.
            </div>';

            return;
        }


        // ====================================================
        // VALIDAR PRODUCTOS
        // ====================================================

        if (
            !is_array($productos_finales) ||
            empty($productos_finales)
        ) {

            echo '<div style="color:red;">
                ❌ El campo
                _ctonline_productos_finales
                no es válido.
            </div>';

            return;
        }


        // ====================================================
        // TOKEN
        // ====================================================

        $token =
            crearNuevoToken();


        if (empty($token)) {

            echo '<div style="color:red;">
                ❌ No se pudo obtener token.
            </div>';

            return;
        }


        // ====================================================
        // PRODUCTOS POR ALMACÉN
        // ====================================================

        $productos_por_almacen = [];


        foreach (
            $productos_finales as $p
        ) {

            if (
                empty($p['producto']) ||
                empty($p['almacen'])
            ) {
                continue;
            }


            $productos_por_almacen[
                $p['almacen']
            ][] = [

                'clave' =>
                    $p['producto'],

                'cantidad' =>
                    $p['cantidad'],

                'precio' =>
                    $p['precio'],

                'moneda' =>
                    $p['moneda'],

                'almacen' =>
                    $p['almacen']

            ];
        }


        // ====================================================
        // FOLIOS
        // ====================================================

        $folios_generados = [];

        $total_envio = 0;

        $letra = 'A';


        // ====================================================
        // PEDIDO POR ALMACÉN
        // ====================================================

        foreach (
            $productos_por_almacen as $almacen =>
            $productos
        ) {

            $info =
                $paqueterias[$almacen]
                ?? null;


            if (!$info) {
                continue;
            }


            $idPedidoConLetra =
                $order->get_id() .
                $letra;


            // =================================================
            // PAYLOAD
            // =================================================

            $datos = [

                'idPedido' =>
                    $idPedidoConLetra,

                'almacen' =>
                    $almacen,

                'tipoPago' =>
                    '03',

                'cfdi' =>
                    'G01',

                'guiaConnect' => [

                    'generarGuia' =>
                        true,

                    'paqueteria' =>
                        $info['paqueteria']

                ],

                'envio' => [[

                    'nombre' =>
                        $order->get_formatted_billing_full_name(),

                    'direccion' =>
                        $order->get_billing_address_1(),

                    'noExterior' =>
                        'S/N',

                    'colonia' =>
                        $order->get_billing_address_2()
                        ?: 'Colonia',

                    'estado' =>
                        $order->get_billing_state(),

                    'ciudad' =>
                        $order->get_billing_city(),

                    'codigoPostal' =>
                        (int)
                        $order->get_billing_postcode(),

                    'telefono' =>
                        preg_replace(
                            '/[^0-9]/',
                            '',
                            $order->get_billing_phone()
                        ),

                    'entreCalles' =>
                        'Sin especificar'

                ]],

                // ✅ SOLO PRODUCTOS SELECCIONADOS
                'producto' =>
                    $productos

            ];


            // =================================================
            // DEBUG
            // =================================================

            if (
                $mostrar_json_crudo
            ) {

                echo '<h3>
                    📦 Payload enviado para almacén
                    <strong>' .
                    esc_html($almacen) .
                    '</strong>:
                </h3>';

                echo '<pre>' .
                    esc_html(
                        json_encode(
                            $datos,
                            JSON_PRETTY_PRINT |
                            JSON_UNESCAPED_UNICODE
                        )
                    ) .
                '</pre>';
            }


            // =================================================
            // ENVIAR A CT ONLINE
            // =================================================

            $res =
                servicioApi(
                    'POST',
                    'pedido',
                    json_encode(
                        $datos
                    ),
                    $token
                );


            // =================================================
            // DEBUG RESPUESTA
            // =================================================

            if (
                $mostrar_json_crudo
            ) {

                echo '<h3>
                    📬 Respuesta de la API para almacén
                    <strong>' .
                    esc_html($almacen) .
                    '</strong>:
                </h3>';

                echo '<pre>' .
                    esc_html(
                        print_r(
                            $res,
                            true
                        )
                    ) .
                '</pre>';
            }


            // =================================================
            // OBTENER FOLIO
            // =================================================

            $folio_api = null;


            if (
                is_object($res)
            ) {

                if (
                    isset(
                        $res->errorCode
                    )
                ) {

                    $error_code =
                        sanitize_text_field(
                            $res->errorCode
                        );


                    $error_message =
                        sanitize_text_field(
                            $res->errorMessage
                            ?? ''
                        );


                    $error_reference =
                        sanitize_text_field(
                            $res->errorReference
                            ?? ''
                        );


                    $order->update_meta_data(
                        '_ctonline_error_code_' .
                        $almacen,
                        $error_code
                    );


                    $order->update_meta_data(
                        '_ctonline_error_message_' .
                        $almacen,
                        $error_message
                    );


                    $order->update_meta_data(
                        '_ctonline_error_reference_' .
                        $almacen,
                        $error_reference
                    );


                    echo '
                        <div style="
                            margin:15px 0;
                            padding:15px;
                            border:1px solid #e0b000;
                            background:#fff8d6;
                            color:#5f4b00;
                            border-radius:5px;
                        ">

                            <strong>
                                ⚠️ Pedido recibido
                            </strong>
                            <br>

                            Tu pedido fue registrado,
                            pero no pudimos procesarlo
                            automáticamente en este momento.
                            <br>

                            Nuestro equipo revisará
                            el pedido y se pondrá en
                            contacto contigo si es necesario.

                        </div>
                    ';


                    error_log(
                        '[CTOnline] Error pedido ' .
                        $order->get_id() .
                        ' | Almacén ' .
                        $almacen .
                        ' | Código ' .
                        $error_code .
                        ' | Mensaje ' .
                        $error_message .
                        ' | Referencia ' .
                        $error_reference
                    );

                } elseif (

                    isset(
                        $res->respuestaCT
                    )
                    &&
                    is_object(
                        $res->respuestaCT
                    )
                    &&
                    isset(
                        $res->respuestaCT->pedidoWeb
                    )

                ) {

                    $folio_api =
                        $res->respuestaCT->pedidoWeb;
                }


            } elseif (
                is_array($res)
            ) {

                $respuesta =
                    $res[0] ?? null;


                if (
                    is_object(
                        $respuesta
                    )
                ) {

                    $folio_api =
                        $respuesta
                            ->respuestaCT
                            ->pedidoWeb
                            ?? null;


                } elseif (
                    is_array(
                        $respuesta
                    )
                ) {

                    $folio_api =
                        $respuesta[
                            'respuestaCT'
                        ][
                            'pedidoWeb'
                        ]
                        ?? null;
                }
            }


            // =================================================
            // GUARDAR FOLIO
            // =================================================

            if (
                !empty($folio_api)
            ) {

                $folio =
                    sanitize_text_field(
                        $folio_api
                    );


                $order->update_meta_data(
                    '_ctonline_folio_' .
                    $almacen,
                    $folio
                );


                $folios_generados[] =
                    'Almacén ' .
                    $almacen .
                    ': ' .
                    $folio;

            } else {

                echo '<div style="color:red;">
                    ⚠️ No se recibió folio para almacén ' .
                    esc_html($almacen) .
                    '.
                </div>';
            }


            // =================================================
            // COSTO ENVÍO
            // =================================================

            $costo =
                isset(
                    $info['precio']
                )
                ? floatval(
                    $info['precio']
                )
                : 0;


            $total_envio +=
                $costo;


            $letra++;
        }


        // ====================================================
        // MOSTRAR COSTO DE ENVÍO
        // ====================================================

        echo '
            <h3>
                🚚 Costo de envío por almacén:
            </h3>

            <ul>
        ';


        foreach (
            $paqueterias as $almacen =>
            $info
        ) {

            $costo =
                isset(
                    $info['precio']
                )
                ? floatval(
                    $info['precio']
                )
                : 0;


            echo '<li>
                Almacén
                <strong>' .
                esc_html($almacen) .
                '</strong>:
                ' .
                esc_html(
                    $info['paqueteria']
                ) .
                ' - $' .
                number_format(
                    $costo,
                    2
                ) .
            '</li>';
        }


        echo '
                <li>
                    <strong>
                        Total envío:
                    </strong>
                    $' .
                    number_format(
                        $total_envio,
                        2
                    ) .
                '</li>
            </ul>
        ';


        // ====================================================
        // MI CUENTA
        // ====================================================

        $mi_cuenta_url =
            wc_get_page_permalink(
                'myaccount'
            );


        echo '
            <div style="
                margin-top:20px;
                padding:15px 20px;
                border:2px solid #28a745;
                background:#f0fff4;
                border-radius:8px;
                font-size:16px;
                color:#333;
                font-weight:500;
            ">

                Podrá checar el estatus de su pedido
                en todo momento entrando al apartado

                <a
                    href="' .
                    esc_url(
                        $mi_cuenta_url
                    ) .
                    '"
                    style="
                        color:#ff0000;
                        font-weight:bold;
                        text-decoration:underline;
                    "
                >
                    Mi cuenta
                </a>

                en su número de pedido.

            </div>
        ';


        $order->save();


        // ====================================================
        // CODI
        // ====================================================

        if (
            $order->get_payment_method()
            === 'bacs'
        ) {

            $total =
                $order->get_total();


            $concepto =
                'Pago pedido #' .
                $order->get_order_number();


            $codi_url =
                'https://www.banxico.org.mx/codi/qr/?' .
                're=CARLOS%20FELIX%20SALAZAR' .
                '&cl=072760001340296472' .
                '&am=' .
                number_format(
                    $total,
                    2,
                    '.',
                    ''
                ) .
                '&ct=001&rn=' .
                urlencode(
                    $concepto
                );


            $qr_image =
                'https://api.qrserver.com/v1/create-qr-code/?' .
                'size=150x150&data=' .
                urlencode(
                    $codi_url
                );


            echo '
                <div style="
                    margin-top:30px;
                    padding:15px;
                    border:1px solid #ddd;
                    background:#f9f9f9;
                ">

                    <h3>
                        Aparte de poder pagar mediante
                        transferencia directa SPEI®
                        también puedes hacerlo
                        escaneando este código QR
                        con tu app bancaria (CoDi®):
                    </h3>

                    <p style="
                        margin-top:10px;
                        font-weight:bold;
                    ">

                        Busque este logo en su aplicación:

                        <img
                            src="' .
                            esc_url(
                                get_site_url() .
                                '/wp-content/uploads/CODI.png'
                            ) .
                            '"
                            alt="Logo CoDi"
                            style="
                                width:70px;
                                vertical-align:middle;
                                margin-left:10px;
                            "
                        >

                    </p>

                    <img
                        src="' .
                        esc_url(
                            $qr_image
                        ) .
                        '"
                        alt="QR CoDi"
                        width="150"
                        height="150"
                        style="margin-top:10px;"
                    >

                </div>
            ';
        }

    
});


// ============================================================
// 📦 MOSTRAR FOLIOS EN DETALLE DEL PEDIDO
// ============================================================

add_action(
    'woocommerce_order_details_after_order_table',
    function($order) {

        if (
            is_order_received_page()
        ) {
            return;
        }


        $meta =
            $order->get_meta_data();


        $folios = [];


        foreach (
            $meta as $m
        ) {

            if (
                strpos(
                    $m->key,
                    '_ctonline_folio_'
                ) === 0
            ) {

                $almacen =
                    str_replace(
                        '_ctonline_folio_',
                        '',
                        $m->key
                    );


                $folios[] = [

                    'almacen' =>
                        $almacen,

                    'folio' =>
                        esc_html(
                            $m->value
                        )

                ];
            }
        }


        if (
            !empty($folios)
        ) {

            echo '
                <h3>
                    Folios generados:
                </h3>

                <ul>
            ';


            foreach (
                $folios as $f
            ) {

                echo '
                    <li>
                        <strong>
                            Almacén ' .
                            esc_html(
                                $f['almacen']
                            ) .
                            ':
                        </strong>
                        ' .
                        $f['folio'] .
                    '</li>
                ';


                echo '
                    <li>
                        <button
                            class="button consultar-guias-btn"
                            data-folio="' .
                            esc_attr(
                                $f['folio']
                            ) .
                            '"
                            data-order-id="' .
                            esc_attr(
                                $order->get_id()
                            ) .
                            '"
                        >
                            📦 Rastrear Pedido
                        </button>
                    </li>
                ';


                echo '
                    <div
                        id="resultado-guias-' .
                        esc_attr(
                            $f['folio']
                        ) .
                        '"
                        style="
                            margin-top:10px;
                            color:#333;
                        "
                    >
                    </div>
                ';
            }


            echo '</ul>';
        }

    }
);


// ============================================================
// ✅ AJAX CONSULTAR GUÍAS
// ============================================================

add_action(
    'wp_ajax_consultar_guias_ctonline',
    'ajax_consultar_guias_ctonline'
);

add_action(
    'wp_ajax_nopriv_consultar_guias_ctonline',
    'ajax_consultar_guias_ctonline'
);


function ajax_consultar_guias_ctonline() {

    $folio =
        sanitize_text_field(
            $_POST['folio'] ?? ''
        );


    $order_id =
        absint(
            $_POST['order_id'] ?? 0
        );


    if (
        !$folio ||
        !$order_id
    ) {

        wp_send_json_error([
            'mensaje' =>
                'Datos incompletos.'
        ]);
    }


    // ========================================================
    // FOLIO DE PRUEBA
    // ========================================================

    if (
        $folio ===
        'WP01-0000023282'
    ) {

        wp_send_json([

            'guias' => [

                '3058716484660700812468',

                '1058716484660700812123'

            ],

            'paqueteria' =>
                'paquetexpress'

        ]);
    }


    // ========================================================
    // PEDIDO
    // ========================================================

    $order =
        wc_get_order(
            $order_id
        );


    if (!$order) {

        wp_send_json_error([
            'mensaje' =>
                'Pedido no encontrado.'
        ]);
    }


    // ========================================================
    // FUNCIONES API
    // ========================================================

    if (
        !function_exists(
            'crearNuevoToken'
        )
        ||
        !function_exists(
            'servicioApi'
        )
    ) {

        wp_send_json_error([
            'mensaje' =>
                'Funciones API no disponibles en el plugin.'
        ]);
    }


    // ========================================================
    // TOKEN
    // ========================================================

    $token =
        crearNuevoToken();


    if (!$token) {

        wp_send_json_error([
            'mensaje' =>
                'No se pudo autenticar con la API.'
        ]);
    }


    // ========================================================
    // API
    // ========================================================

    $res =
        servicioApi(
            'GET',
            'paqueteria/detalles/guia/' .
            $folio,
            null,
            $token
        );


    // ========================================================
    // CONVERTIR A ARRAY
    // ========================================================

    $res =
        json_decode(
            json_encode(
                $res
            ),
            true
        );


    // ========================================================
    // DEBUG
    // ========================================================

    if (
        defined('WP_DEBUG') &&
        WP_DEBUG
    ) {

        error_log(
            '[CTOnline][Mi Cuenta] Folio: ' .
            $folio .
            ' | Respuesta: ' .
            print_r(
                $res,
                true
            )
        );
    }


    // ========================================================
    // RESPUESTA
    // ========================================================

    if (
        !isset(
            $res['respuesta']
        )
    ) {

        wp_send_json([
            'mensaje' =>
                'No se obtuvo respuesta válida de la API.'
        ]);
    }


    $respuesta =
        $res['respuesta'];


    // ========================================================
    // GUÍAS
    // ========================================================

    if (
        !empty(
            $respuesta['guias']
        )
        &&
        is_array(
            $respuesta['guias']
        )
    ) {

        wp_send_json([

            'guias' =>
                $respuesta['guias'],

            'paqueteria' =>
                $respuesta['paqueteria']
                ?? 'estafeta'

        ]);
    }


    // ========================================================
    // MENSAJE
    // ========================================================

    if (
        !empty(
            $respuesta['mensaje']
        )
    ) {

        wp_send_json([

            'mensaje' =>
                $respuesta['mensaje']

        ]);
    }


    // ========================================================
    // FALLBACK
    // ========================================================

    wp_send_json([

        'mensaje' =>
            'Las guías se encuentran pendiente de asignar, consultar más tarde.'

    ]);
}


// ============================================================
// 📦 JAVASCRIPT GUÍAS
// ============================================================

add_action(
    'woocommerce_view_order',
    function () {
        ?>

        <script>

        document.addEventListener(
            'DOMContentLoaded',
            function() {

                document
                    .querySelectorAll(
                        '.consultar-guias-btn'
                    )
                    .forEach(
                        function(btn) {

                            btn.addEventListener(
                                'click',
                                function() {

                                    const folio =
                                        btn.dataset.folio;

                                    const orderId =
                                        btn.dataset.orderId;

                                    const resultado =
                                        document.getElementById(
                                            'resultado-guias-' +
                                            folio
                                        );

                                    resultado.innerHTML =
                                        '<em>Consultando guías...</em>';


                                    fetch(
                                        '<?php echo esc_url(
                                            admin_url(
                                                'admin-ajax.php'
                                            )
                                        ); ?>',
                                        {

                                            method:
                                                'POST',

                                            headers: {
                                                'Content-Type':
                                                    'application/x-www-form-urlencoded'
                                            },

                                            body:
                                                new URLSearchParams({

                                                    action:
                                                        'consultar_guias_ctonline',

                                                    folio:
                                                        folio,

                                                    order_id:
                                                        orderId

                                                })

                                        }
                                    )

                                    .then(
                                        response =>
                                            response.json()
                                    )

                                    .then(
                                        function(data) {

                                            resultado.innerHTML =
                                                '';


                                            if (
                                                data.guias
                                            ) {

                                                const paqueteria =
                                                    (
                                                        data.paqueteria ||
                                                        'estafeta'
                                                    ).toLowerCase();


                                                let base =
                                                    '';


                                                if (
                                                    paqueteria ===
                                                    'estafeta'
                                                ) {

                                                    base =
                                                        'https://www.estafeta.com/rastrear-envio/?guia=';

                                                } else if (
                                                    paqueteria ===
                                                    'paquetexpress'
                                                ) {

                                                    base =
                                                        'https://www.paquetexpress.com.mx/rastreo/';
                                                }


                                                data.guias.forEach(
                                                    function(guia) {

                                                        const link =
                                                            document.createElement(
                                                                'a'
                                                            );


                                                        link.href =
                                                            base +
                                                            encodeURIComponent(
                                                                guia
                                                            );


                                                        link.textContent =
                                                            '🔗 ' +
                                                            guia;


                                                        link.target =
                                                            '_blank';


                                                        link.rel =
                                                            'noopener noreferrer';


                                                        const div =
                                                            document.createElement(
                                                                'div'
                                                            );


                                                        div.appendChild(
                                                            link
                                                        );


                                                        resultado.appendChild(
                                                            div
                                                        );

                                                    }
                                                );


                                            } else if (
                                                data.mensaje
                                            ) {

                                                resultado.innerHTML =
                                                    '<div style="color:red;">' +
                                                    data.mensaje +
                                                    '</div>';

                                            } else {

                                                resultado.textContent =
                                                    'No se pudo consultar guías.';
                                            }

                                        }
                                    )

                                    .catch(
                                        function() {

                                            resultado.innerHTML =
                                                '<div style="color:red;">' +
                                                'Error al consultar guías.' +
                                                '</div>';

                                        }
                                    );

                                }
                            );

                        }
                    );

            }
        );

        </script>

        <?php
    }
);


// ============================================================
// 🧾 COLUMNA ADMIN FOLIOS
// ============================================================

add_action(
    'manage_shop_order_posts_custom_column',
    function(
        $column,
        $post_id
    ) {

        if (
            $column !== 'ctonline_folio'
        ) {
            return;
        }


        $order =
            wc_get_order(
                $post_id
            );


        if (!$order) {

            echo '—';

            return;
        }


        $meta =
            $order->get_meta_data();


        $folios = [];


        foreach (
            $meta as $m
        ) {

            if (
                strpos(
                    $m->key,
                    '_ctonline_folio_'
                ) === 0
            ) {

                $almacen =
                    str_replace(
                        '_ctonline_folio_',
                        '',
                        $m->key
                    );


                $folios[] =
                    'Almacén ' .
                    esc_html(
                        $almacen
                    ) .
                    ': ' .
                    esc_html(
                        $m->value
                    );
            }
        }


        echo $folios
            ? implode(
                '<br>',
                $folios
            )
            : '—';

    },
    10,
    2
);




add_action('admin_menu', function () {
    add_menu_page(
        'Confirmar Pedido', // ← ESTE es el título de la página (arriba)
        'CT ONLINE',        // ← ESTE es el texto que aparece en el menú lateral
        'manage_woocommerce',
        'ctonline_confirmar_pedidos',
        'ctonline_pagina_confirmar_pedidos',
        'dashicons-yes',
        56
    );

    // Submenú REAL con título adecuado
    add_submenu_page(
        'ctonline_confirmar_pedidos',
        'Confirmar Pedido',       // ← Título de la página (arriba)
        'Confirmar Pedido',       // ← Texto en el submenú lateral (debe ser DIFERENTE de 'CT ONLINE')
        'manage_options',
        'ctonline_confirmar_pedidos', // ← mismo slug para que cargue esa página
        'ctonline_pagina_confirmar_pedidos'
    );

    // Submenú adicional
    add_submenu_page(
        'ctonline_confirmar_pedidos',
        'Convertidor JSON a CSV',
        'Convertidor JSON a CSV',
        'manage_options',
        'ctonline_json_to_csv',
        'json_to_csv_page'
    );
    
    add_submenu_page(
    'ctonline_confirmar_pedidos',
    'Configuración CT Online',
    'Configuración',
    'manage_options',
    'ctonline_configuracion',
    'ctonline_pagina_configuracion'
);
    
    
});


// === Reemplazar el icono por una imagen personalizada ===
add_action('admin_head', function () {
    ?>
    <style>
        #adminmenu .toplevel_page_ctonline_confirmar_pedidos > a .wp-menu-image {
            background-image: url('https://ctonline.mx/static2/img/logo.png') !important;
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center center;
        }
        #adminmenu .toplevel_page_ctonline_confirmar_pedidos > a .wp-menu-image img {
            display: none; /* Evitar conflictos */
        }
    </style>
    <?php
});


// === Página del menú de confirmaciones ===
// ✅ Submenú Confirmar pedidos: buscar todos los pedidos con cualquier folio
function ctonline_pagina_confirmar_pedidos() {
    if (!current_user_can('manage_woocommerce')) wp_die('No autorizado.');

    if (isset($_POST['guardar_configuracion'])) {
        $activar = isset($_POST['activar_auto_confirm']) ? 'yes' : 'no';
        update_option('ctonline_auto_confirmacion', $activar);
        echo '<div class="updated notice"><p>Configuración guardada.</p></div>';
    }

    if (!empty($_POST['confirmar_folio'])) {
        $folio = sanitize_text_field($_POST['confirmar_folio']);
        $token_data = ctonline_crear_token();

        if (!empty($token_data->token)) {
            $token = $token_data->token;
            $payload = json_encode(['folio' => $folio]);
            $response = servicioApi('POST', 'pedido/confirmar', $payload, $token);

            echo '<div style="border:1px solid green;padding:10px;margin:10px 0;background:#f6fff6;"><strong>Respuesta:</strong><pre>';
            print_r($response);
            echo '</pre></div>';

            $orders = wc_get_orders(['limit' => -1]);
            foreach ($orders as $order) {
                foreach ($order->get_meta_data() as $meta) {
                    if ($meta->value === $folio) {
                        $order->update_meta_data('_ctonline_confirmado', 'sí');
                        $order->save();
                        break;
                    }
                }
            }
        } else {
            echo '<p style="color:red;">Error al obtener el token.</p>';
        }
    }

    $auto = get_option('ctonline_auto_confirmacion', 'no');
    echo '<h2>Configuración</h2>';
    echo '<form method="post" style="margin-bottom:20px;">';
    echo '<label><input type="checkbox" name="activar_auto_confirm" value="yes" ' . checked($auto, 'yes', false) . '> Activar confirmación automática para pagos con tarjeta</label><br><br>';
    echo '<input type="submit" name="guardar_configuracion" class="button button-primary" value="Guardar configuración">';
    echo '</form>';

    $orders = wc_get_orders(['limit' => -1, 'orderby' => 'date', 'order' => 'DESC']);

    echo '<h2>Pedidos con folios</h2>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>ID Pedido</th><th>Folios</th><th>Fecha</th><th>¿Confirmado?</th><th>Acción</th></tr></thead><tbody>';

    foreach ($orders as $order) {
        $meta = $order->get_meta_data();
        $folios = [];
        foreach ($meta as $m) {
            if (strpos($m->key, '_ctonline_folio_') === 0) {
                $folios[] = $m->value;
            }
        }

        if (empty($folios)) continue;

        echo '<tr>';
        echo '<td>' . esc_html($order->get_id()) . '</td>';
        echo '<td>' . esc_html(implode(', ', $folios)) . '</td>';
        echo '<td>' . esc_html($order->get_date_created()->date('Y-m-d H:i')) . '</td>';
        echo '<td>' . esc_html($order->get_meta('_ctonline_confirmado') ?: 'no') . '</td>';
        echo '<td>';
        foreach ($folios as $folio) {
            echo '<form method="post" style="display:inline;margin-right:5px;">';
            echo '<input type="hidden" name="confirmar_folio" value="' . esc_attr($folio) . '">';
            echo '<button type="submit" class="button">Confirmar</button>';
            echo '</form>';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}


// ============================================================
// CONVERTIDOR JSON → CSV PARA WOOCOMMERCE
// ============================================================

function json_to_csv_page() {

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['json_file'])) {
        json_to_csv_handle_upload();
        return;
    }

    ?>
    <div class="wrap">

        <h1>Convertir JSON a CSV para WooCommerce</h1>

        <form method="POST" enctype="multipart/form-data">

            <label for="json_file">
                Seleccionar archivo JSON:
            </label>

            <br><br>

            <input
                type="file"
                name="json_file"
                id="json_file"
                accept=".json"
                required
            >

            <br><br>

            <button
                type="submit"
                class="button button-primary"
            >
                Convertir a CSV
            </button>

        </form>

    </div>
    <?php
}


// ============================================================
// OBTENER GTIN
// ============================================================

function obtener_gtin($producto) {

    if (!empty($producto['ean'])) {
        return $producto['ean'];
    }

    if (!empty($producto['upc'])) {
        return $producto['upc'];
    }

    if (!empty($producto['sustituto'])) {
        return $producto['sustituto'];
    }

    if (!empty($producto['clave'])) {
        return $producto['clave'];
    }

    return 'SIN_GTIN';
}

// ============================================================
// PROCESAR JSON Y GENERAR CSV
// ============================================================

function json_to_csv_handle_upload() {

    // Limpiar cualquier salida anterior
    if (ob_get_length()) {
        ob_end_clean();
    }

    nocache_headers();


    // ========================================================
    // ARCHIVO JSON
    // ========================================================

    $jsonFile = $_FILES['json_file']['tmp_name'];

    $fileExtension = pathinfo(
        $_FILES['json_file']['name'],
        PATHINFO_EXTENSION
    );


    // ========================================================
    // COMPROBAR EXTENSIÓN
    // ========================================================

    if (strtolower($fileExtension) !== 'json') {

        wp_die(
            'Por favor, sube un archivo JSON válido (.json)'
        );

    }


    // ========================================================
    // LEER JSON
    // ========================================================

    $jsonData = file_get_contents($jsonFile);

    $data = json_decode(
        $jsonData,
        true
    );


    // ========================================================
    // COMPROBAR JSON
    // ========================================================

    if (json_last_error() !== JSON_ERROR_NONE) {

        wp_die(
            'El archivo JSON es inválido.'
        );

    }


    // ========================================================
    // COMPROBAR QUE SEA UN ARRAY DE PRODUCTOS
    // ========================================================

    if (!is_array($data)) {

        wp_die(
            'El JSON no contiene una lista válida de productos.'
        );

    }


    // ========================================================
    // CREAR ARCHIVO CSV TEMPORAL
    // ========================================================

    $tempCsvFile = tempnam(
        sys_get_temp_dir(),
        'csv_'
    );

    $csvFile = fopen(
        $tempCsvFile,
        'w'
    );


    // ========================================================
    // ENCABEZADOS DE WOOCOMMERCE
    // ========================================================

    $headers = [

        'ID',
        'Tipo',
        'SKU',
        'GTIN, UPC, EAN, or ISBN',
        'Nombre',
        'Publicado',
        '¿Está destacado?',
        'Visibilidad en el catálogo',
        'Descripción corta',
        'Descripción',
        'Día en que empieza el precio rebajado',
        'Día en que termina el precio rebajado',
        'Estado del impuesto',
        'Clase de impuesto',
        '¿En inventario?',
        'Inventario',
        'Cantidad de bajo inventario',
        '¿Permitir reservas de productos agotados?',
        '¿Vendido individualmente?',
        'Peso (lbs)',
        'Longitud (in)',
        'Ancho (in)',
        'Altura (in)',
        '¿Permitir valoraciones de clientes?',
        'Precio rebajado',
        'Precio normal',
        'Categorías',
        'Etiquetas',
        'Clase de envío',
        'Imágenes',
        'Límite de descargas',
        'Días de caducidad de la descarga',
        'Superior',
        'Productos agrupados',
        'Ventas dirigidas',
        'Ventas cruzadas',
        'URL externa',
        'Texto del botón',
        'Posición',
        'Attribute:modelo',
        'Brands',
        'Attribute:subcategoria'
    ];


    fputcsv(
        $csvFile,
        $headers
    );


    // ========================================================
    // RECORRER PRODUCTOS
    // ========================================================

    foreach ($data as $producto) {


        // ====================================================
        // GTIN
        // ====================================================

        $gtin_value = obtener_gtin(
            $producto
        );

        $gtin = '="' . $gtin_value . '"';


        // ====================================================
        // MARCA
        //
        // VIENE DIRECTAMENTE DEL JSON
        // "marca":"TP-LINK"
        // ====================================================

        $brand = trim(
            (string)($producto['marca'] ?? '')
        );


        // ====================================================
        // NUM PARTE
        //
        // VIENE DIRECTAMENTE DEL JSON
        // "numParte":"CPE210"
        // ====================================================

        $numParte = trim(
            (string)($producto['numParte'] ?? '')
        );


        // ====================================================
        // MODELO
        // ====================================================

        $modelo = trim(
            (string)($producto['modelo'] ?? '')
        );


        // ====================================================
        // CATEGORÍA
        // ====================================================

        $categoria = trim(
            (string)($producto['categoria'] ?? '')
        );


        // ====================================================
        // SUBCATEGORÍA
        // ====================================================

        $subcategoria = trim(
            (string)($producto['subcategoria'] ?? '')
        );


        // ====================================================
        // CATEGORÍAS WOOCOMMERCE
        //
        // WooCommerce permite crear jerarquías mediante:
        //
        // Categoría > Subcategoría
        //
        // Ejemplo:
        //
        // Red Activa > Access Points
        //
        // Esto hará que Access Points sea una categoría hija
        // de Red Activa.
        // ====================================================

        if (
            $categoria !== '' &&
            $subcategoria !== ''
        ) {

            $categorias_woocommerce =
                $categoria .
                ' > ' .
                $subcategoria;

        } elseif ($categoria !== '') {

            $categorias_woocommerce =
                $categoria;

        } else {

            $categorias_woocommerce =
                $subcategoria;

        }


        // ====================================================
        // CREAR URL DE ICECAT
        // ====================================================

        $icecat_url =
            'https://live.icecat.biz/api/?' .
            'UserName=carlos77-&' .
            'Language=es&' .
            'Brand=' . rawurlencode($brand) . '&' .
            'PartCode=' . rawurlencode($numParte) . '&' .
            'Output=JSON';


        // ====================================================
        // CREAR DESCRIPCIÓN
        //
        // PRIMERO:
        //
        // [xyz-ips snippet="ICECAT2"]
        //
        // DESPUÉS:
        //
        // URL DE ICECAT
        // ====================================================

        $descripcion =
            '[xyz-ips snippet="ICECAT2"]' .
            "\r\n" .
            $icecat_url;


        // ====================================================
        // CREAR FILA DEL CSV
        // ====================================================

        $row = [

            // ID
            $producto['idProducto'] ?? '',

            // Tipo
            'simple',

            // SKU
            $producto['clave'] ?? '',

            // GTIN
            $gtin,

            // Nombre
            $producto['nombre'] ?? '',

            // Publicado
            '1',

            // Destacado
            '',

            // Visibilidad
            'visible',

            // Descripción corta
            $producto['descripcion_corta'] ?? '',

            // DESCRIPCIÓN
            // Shortcode + URL Icecat
            $descripcion,

            // Precio rebajado inicio
            '',

            // Precio rebajado fin
            '',

            // Estado impuesto
            '',

            // Clase impuesto
            '',

            // En inventario
            'Sí',

            // Inventario
            '',

            // Bajo inventario
            '',

            // Reservas
            '',

            // Vendido individualmente
            '',

            // Peso
            '',

            // Longitud
            '',

            // Ancho
            '',

            // Altura
            '',

            // Valoraciones
            '',

            // Precio rebajado
            $producto['precio'] ?? '',

            // Precio normal
            $producto['precio'] ?? '',

            // =================================================
            // CATEGORÍAS
            //
            // AHORA:
            //
            // categoria > subcategoria
            //
            // Ejemplo:
            // Red Activa > Access Points
            // =================================================
            $categorias_woocommerce,

            // Etiquetas
            '',

            // Clase envío
            '',

            // Imágenes
            $producto['imagen'] ?? '',

            // Límite descargas
            '',

            // Días caducidad
            '',

            // Superior
            '',

            // Productos agrupados
            '',

            // Ventas dirigidas
            '',

            // Ventas cruzadas
            '',

            // URL externa
            '',

            // Texto botón
            '',

            // Posición
            '',

            // Attribute:modelo
            $modelo,

            // Brands
            $brand,

            // Attribute:subcategoria
            $subcategoria
        ];


        // ====================================================
        // ESCRIBIR PRODUCTO
        // ====================================================

        fputcsv(
            $csvFile,
            $row
        );

    }


    // ========================================================
    // CERRAR CSV
    // ========================================================

    fclose(
        $csvFile
    );


    // ========================================================
    // DESCARGAR CSV
    // ========================================================

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="productos_woocommerce.csv"'
    );

    header(
        'Content-Length: ' . filesize($tempCsvFile)
    );


    readfile(
        $tempCsvFile
    );


    // ========================================================
    // ELIMINAR TEMPORAL
    // ========================================================

    unlink(
        $tempCsvFile
    );


    exit;
}

// ============================================================
// CYBERDEPOT
// EVITAR CATEGORÍAS DUPLICADAS POR SUFIJO NUMÉRICO
//
// Compatible con WooCommerce 10.3.8
//
// Ejemplo:
//
// aires-acondicionados
// aires-acondicionados-7
// aires-acondicionados-9
//
// Si "aires-acondicionados" YA EXISTE bajo el mismo padre,
// se reutiliza esa categoría.
//
// IMPORTANTE:
// - NO modifica el nombre de la categoría.
// - NO modifica categorías normales con números.
// - Respeta la categoría padre.
// - Solo afecta product_cat.
// ============================================================

add_filter(
    'wp_insert_term_data',
    'cyberdepot_evitar_categoria_duplicada',
    10,
    3
);

function cyberdepot_evitar_categoria_duplicada(
    $data,
    $taxonomy,
    $args
) {

    // ========================================================
    // SOLO CATEGORÍAS DE PRODUCTO
    // ========================================================

    if ($taxonomy !== 'product_cat') {
        return $data;
    }


    // ========================================================
    // SI NO HAY SLUG, NO HACER NADA
    // ========================================================

    if (empty($data['slug'])) {
        return $data;
    }


    // ========================================================
    // SLUG ACTUAL
    // ========================================================

    $slug_actual = sanitize_title(
        $data['slug']
    );


    // ========================================================
    // ¿TERMINA EN -NÚMERO?
    //
    // Ejemplos detectados:
    //
    // categoria-2
    // categoria-7
    // categoria-15
    //
    // NO detecta:
    //
    // categoria
    // iphone15
    // usb-3-0
    // ========================================================

    if (!preg_match(
        '/^(.+)-(\d+)$/',
        $slug_actual,
        $matches
    )) {

        return $data;
    }


    // ========================================================
    // OBTENER SLUG BASE
    // ========================================================

    $slug_base = $matches[1];


    // ========================================================
    // OBTENER PADRE
    //
    // WooCommerce puede mandar parent en los argumentos.
    // ========================================================

    $parent_id = 0;

    if (isset($args['parent'])) {

        $parent_id = absint(
            $args['parent']
        );

    } elseif (isset($data['parent'])) {

        $parent_id = absint(
            $data['parent']
        );

    }


    // ========================================================
    // BUSCAR CATEGORÍA BASE
    // ========================================================

    $categoria_existente = get_terms(
        array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'slug'       => $slug_base,
            'parent'     => $parent_id,
            'number'     => 1
        )
    );


    // ========================================================
    // SI NO EXISTE LA BASE
    //
    // NO TOCAMOS NADA.
    //
    // Ejemplo:
    //
    // iphone-15
    //
    // Si "iphone" no existe como categoría,
    // se conserva "iphone-15".
    // ========================================================

    if (
        is_wp_error($categoria_existente) ||
        empty($categoria_existente)
    ) {

        return $data;
    }


    // ========================================================
    // YA EXISTE LA CATEGORÍA BASE
    //
    // Cambiamos el slug que intenta insertar WooCommerce
    // por el slug existente.
    // ========================================================

    $data['slug'] = $slug_base;


    return $data;
}


//-----------------------pagina submenu configuracion API
function ctonline_pagina_configuracion() {
    if (!current_user_can('manage_options')) wp_die('No autorizado.');

    // Leer configuración actual
    $config = obtener_config_ctonline();

    // Guardar si se envió el formulario
    if (isset($_POST['guardar_configuracion_ct'])) {
        $config['email'] = sanitize_text_field($_POST['email'] ?? '');
        $config['cliente'] = sanitize_text_field($_POST['cliente'] ?? '');
        $config['rfc'] = sanitize_text_field($_POST['rfc'] ?? '');
        $config['iva'] = floatval($_POST['iva'] ?? 0);
        $config['margen'] = floatval($_POST['margen'] ?? 0);

        guardar_config_ctonline($config);

        echo '<div class="updated notice"><p>Configuración guardada correctamente.</p></div>';
    }

    // Mostrar formulario con valores actuales
    ?>
    <div class="wrap">
        <h1>Configuración de CT Online</h1>
        <form method="post">
            <h2>Datos de conexión API</h2>
            <table class="form-table">
                <tr><th>Email:</th><td><input type="email" name="email" value="<?php echo esc_attr($config['email'] ?? ''); ?>" class="regular-text" required></td></tr>
                <tr><th>Cliente:</th><td><input type="text" name="cliente" value="<?php echo esc_attr($config['cliente'] ?? ''); ?>" class="regular-text" required></td></tr>
                <tr><th>RFC:</th><td><input type="text" name="rfc" value="<?php echo esc_attr($config['rfc'] ?? ''); ?>" class="regular-text" required></td></tr>
            </table>

            <h2>Ajustes de precios</h2>
            <table class="form-table">
                <tr><th>IVA (%):</th><td><input type="number" step="0.01" name="iva" value="<?php echo esc_attr($config['iva'] ?? ''); ?>" class="small-text"></td></tr>
                <tr><th>Margen de ganancia (%):</th><td><input type="number" step="0.01" name="margen" value="<?php echo esc_attr($config['margen'] ?? ''); ?>" class="small-text"></td></tr>
            </table>

            <p><button type="submit" name="guardar_configuracion_ct" class="button button-primary">Guardar configuración</button></p>
        </form>
    </div>
    <?php
}

// ✅ PROGRAMAR EVENTO CRON CADA HORA (se ejecuta automáticamente al activar el plugin)
add_action('init', function () {
    if (!wp_next_scheduled('ctonline_actualizar_precios_evento')) {
        wp_schedule_event(time(), 'hourly', 'ctonline_actualizar_precios_evento');
    }
});
add_action('ctonline_actualizar_precios_evento', 'ctonline_actualizar_todos_los_precios');

// ✅ ACTUALIZAR PRECIO DE UN PRODUCTO (por SKU)
function ctonline_actualizar_precio_producto($product_id) {
    $product = wc_get_product($product_id);
    if (!$product) return;

    $sku = $product->get_sku();
    if (empty($sku)) return;

    $precio = obtener_precio_ajustado_ctonline_completo($sku);
    if ($precio && isset($precio['precio_ajustado'])) {
        update_post_meta($product_id, '_ctonline_precio_ajustado', $precio['precio_ajustado']);
        update_post_meta($product_id, '_ctonline_precio_normal_ajustado', $precio['precio_normal_ajustado']);
        update_post_meta($product_id, '_ctonline_tiene_promocion', $precio['promocion'] ? '1' : '0');
    }
}

// ✅ ACTUALIZAR TODOS LOS PRODUCTOS DE WOOCOMMERCE (consulta eficiente solo IDs)
function ctonline_actualizar_todos_los_precios() {
    $args = [
        'post_type' => 'product',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'cache_results' => false,
    ];
    $productos = get_posts($args);

    foreach ($productos as $product_id) {
        ctonline_actualizar_precio_producto($product_id);
    }
}

// ✅ CREAR SUBMENÚ PARA ACTUALIZACIÓN MANUAL (AJUSTA 'ctonline_confirmar_pedidos' si usas otro slug)
add_action('admin_menu', function () {
    add_submenu_page(
        'ctonline_confirmar_pedidos',      // ← Ajusta si tu menú principal usa otro slug
        'Actualizar Precios',              // Título de la página
        'Actualizar Precios',              // Texto visible en el menú
        'manage_options',
        'ctonline_actualizar_precios',
        'ctonline_pagina_actualizar_precios'
    );
});

function ctonline_pagina_actualizar_precios() {
    if (!current_user_can('manage_options')) {
        wp_die('No autorizado.');
    }

    // ✅ Si se presiona el botón de actualización de precios
    if (isset($_POST['actualizar_precios'])) {
        ctonline_actualizar_todos_los_precios();
        echo '<div class="notice notice-success is-dismissible"><p>✅ Precios actualizados correctamente.</p></div>';
    }

    // ✅ Si se guarda la preferencia de mostrar JSON crudo
    if (isset($_POST['guardar_json_crudo'])) {
        $mostrar = isset($_POST['mostrar_json_crudo']) ? 'yes' : 'no';
        update_option('ctonline_mostrar_json_crudo', $mostrar);
        echo '<div class="updated notice"><p>Preferencia de visualización de JSON guardada.</p></div>';
    }

    // ✅ Obtener valor actual
    $mostrar_json_crudo = get_option('ctonline_mostrar_json_crudo', 'no');
    ?>

    <div class="wrap">
        <h1>Actualizar precios desde API CT Online</h1>
        <form method="post">
            <p>Presiona el siguiente botón para actualizar los precios desde la API.</p>
            <p><button type="submit" name="actualizar_precios" class="button button-primary">Actualizar ahora</button></p>
        </form>

        <hr>

        <h2>Configuración de visualización</h2>
        <form method="post">
            <label>
                <input type="checkbox" name="mostrar_json_crudo" value="yes" <?php checked($mostrar_json_crudo, 'yes'); ?>>
                Mostrar JSON crudo en Thankyou y Cotización
            </label><br><br>
            <input type="submit" name="guardar_json_crudo" class="button button-secondary" value="Guardar preferencia">
        </form>
    </div>

    <?php
}

// Botón manual para regenerar promociones
add_action('admin_menu', function () {
    add_submenu_page(
        'ctonline_confirmar_pedidos',
        'Regenerar Promociones',
        'Regenerar Promociones',
        'manage_options',
        'ctonline_regenerar_promos',
        'ctonline_pagina_regenerar_promos'
    );
});

function ctonline_pagina_regenerar_promos() {
    if (!current_user_can('manage_options')) {
        wp_die('No autorizado.');
    }

    if (isset($_POST['regenerar_promos'])) {
        // Suponiendo que tienes esta función ya creada en tu plugin:
        ctonline_precargar_promociones(); // ← esta debe guardar el transient de promociones

        echo '<div class="notice notice-success is-dismissible"><p>✅ Promociones actualizadas correctamente.</p></div>';
    }

    echo '<div class="wrap"><h1>Regenerar promociones</h1>';
    echo '<form method="post">';
    echo '<p><input type="submit" name="regenerar_promos" class="button-primary" value="Regenerar promociones ahora"></p>';
    echo '</form></div>';
}

function ctonline_precargar_promociones() {
    // Aquí deberías consultar la API real, este es un ejemplo simulado:
    $respuesta = [
        [
            'sku' => 'ABC123',
            'precio_normal' => 1000,
            'precio_promocion' => 850,
            'promo_ini' => '2025-07-01T07:00:00.000Z',
            'promo_fin' => '2025-07-31T07:00:00.000Z'
        ],
        // ... más promociones
    ];

    // Guardar en el transient por 12 horas (43200 segundos)
    set_transient('ct_promociones_completo', $respuesta, 12 * HOUR_IN_SECONDS);
}



// ✅ MOSTRAR PRECIO AJUSTADO EN EL FRONTEND
add_filter('woocommerce_get_price_html', function ($price_html, $product) {
    $precio_ajustado = get_post_meta($product->get_id(), '_ctonline_precio_ajustado', true);

    if ($precio_ajustado) {
        return '<span class="precio-ajustado">' . wc_price($precio_ajustado) . '</span>';
    } else {
        return $price_html;
    }
}, 20, 2);

// --- BLOQUE 1: Validación al agregar al carrito (bloquea si hay menos stock del solicitado) ---
add_filter('woocommerce_add_to_cart_validation', 'validar_existencia_ctonline', 10, 5);

function validar_existencia_ctonline($passed, $product_id, $quantity, $variation_id = null, $variations = null) {
    $product = wc_get_product($product_id);
    $sku = $product->get_sku();

    if (!$sku) return $passed;

    $token = crearNuevoToken();
    if (!$token) return false;

    $res = servicioApi('GET', 'existencia/promociones/' . $sku, null, $token);

    $existencia_total = 0;
    if (!empty($res->almacenes)) {
        foreach ($res->almacenes as $alm) {
            foreach ($alm as $clave => $exist) {
                if (strtolower($clave) === 'promocion') continue;
                $existencia_total += $exist;
            }
        }
    }

    if ($existencia_total < $quantity) {
        wc_add_notice('No puedes agregar más de ' . $existencia_total . ' unidad(es) de este producto. Stock limitado en CT Online.', 'error');
        return false;
    }

    return $passed;
}


// --- BLOQUE 2: Limita el selector de cantidad al máximo disponible ---
add_filter('woocommerce_quantity_input_max', 'limitar_maximo_por_existencia_api', 20, 2);

function limitar_maximo_por_existencia_api($max, $product) {
    $sku = $product->get_sku();
    if (!$sku) return $max;

    $token = crearNuevoToken();
    if (!$token) return $max;

    $res = servicioApi('GET', 'existencia/promociones/' . $sku, null, $token);

    $existencia_total = 0;
    if (!empty($res->almacenes)) {
        foreach ($res->almacenes as $alm) {
            foreach ($alm as $clave => $exist) {
                if (strtolower($clave) === 'promocion') continue;
                $existencia_total += $exist;
            }
        }
    }

    return $existencia_total > 0 ? $existencia_total : 1;
}

add_filter('woocommerce_cart_item_quantity', 'limitar_cantidad_en_carrito_ctonline', 20, 3);

function limitar_cantidad_en_carrito_ctonline($product_quantity, $cart_item_key, $cart_item) {
    $product = $cart_item['data'];
    $sku = $product->get_sku();
    if (!$sku) return $product_quantity;

    $token = crearNuevoToken();
    if (!$token) return $product_quantity;

    $res = servicioApi('GET', 'existencia/promociones/' . $sku, null, $token);

    $existencia_total = 0;
    if (!empty($res->almacenes)) {
        foreach ($res->almacenes as $alm) {
            foreach ($alm as $clave => $exist) {
                if (strtolower($clave) === 'promocion') continue;
                $existencia_total += $exist;
            }
        }
    }

    // Establecer máximo según existencia real
    $max_qty = ($existencia_total > 0) ? $existencia_total : 1;

    return woocommerce_quantity_input([
        'input_name'  => "cart[{$cart_item_key}][qty]",
        'input_value' => $cart_item['quantity'],
        'max_value'   => $max_qty,
        'min_value'   => 1,
    ], $product, false);
}
//-------------------------------------------------


// Registrar y cargar slick slider solo una vez
function ct_cargar_slick_slider() {
    if (!wp_script_is('slick-js', 'enqueued')) {
        wp_enqueue_style('slick-css', 'https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.css');
        wp_enqueue_script('slick-js', 'https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.min.js', ['jquery'], null, true);
    }
}
add_action('wp_enqueue_scripts', 'ct_cargar_slick_slider');


// Shortcode para mostrar promociones como carrusel
function ct_shortcode_promociones() {
    $promos = get_transient('ct_promociones_completo');

    if (!is_array($promos)) {
        // Si no es array, lo convertimos en array vacío para evitar errores
        $promos = [];
    }

    // Filtrar solo los productos que tengan precio promocion válido y mayor que 0
    $promos = array_filter($promos, function ($p) {
        return isset($p['precio_promocion']) && is_numeric($p['precio_promocion']) && $p['precio_promocion'] > 0;
    });

    if (count($promos) === 0) {
        return '<div style="text-align:center; padding:20px;">No hay promociones disponibles en este momento.</div>';
    }

    ob_start();
    ?>
    <div class="ct-promos-wrapper">
        <div class="ct-promos-header">NUESTROS PRODUCTOS EN OFERTA</div>
        <div class="ct-promos-slider">
            <?php foreach ($promos as $promo):
                $sku = $promo['sku'];
                $producto_id = wc_get_product_id_by_sku($sku);
                if (!$producto_id) continue;

                $producto = wc_get_product($producto_id);
                if (!$producto) continue;

                $url = get_permalink($producto->get_id());
                $img = wp_get_attachment_image_src($producto->get_image_id(), 'medium')[0] ?? wc_placeholder_img_src();

                $precio_normal = number_format(floatval($promo['precio_normal']), 2);
                $precio_promocion = number_format(floatval($promo['precio_promocion']), 2);
                $ini = date('d/m/Y', strtotime($promo['promo_ini']));
                $fin = date('d/m/Y', strtotime($promo['promo_fin']));
            ?>
                <div class="ct-promo-card">
                    <a href="<?= esc_url($url); ?>">
                        <img src="<?= esc_url($img); ?>" alt="<?= esc_attr($producto->get_name()); ?>">
                        <div class="ct-promo-title"><?= esc_html($producto->get_name()); ?></div>
                        <div class="ct-promo-precios">
                            <span class="ct-precio-normal">$<?= $precio_normal; ?></span>
                            <span class="ct-precio-oferta">$<?= $precio_promocion; ?></span>
                        </div>
                        <div class="ct-promo-fechas">Válido: <?= $ini; ?> al <?= $fin; ?></div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <style>
        .ct-promos-wrapper {
            max-width: 100%;
            margin: 30px auto;
            padding: 10px;
            box-sizing: border-box;
        }
        .ct-promos-header {
            background: #c00;
            color: #fff;
            font-weight: bold;
            font-size: 18px;
            padding: 8px 16px;
            margin-bottom: 10px;
            text-align: center;
            border-radius: 5px;
        }
        .ct-promos-slider {
            display: flex;
            overflow-x: auto;
            scroll-behavior: smooth;
            gap: 16px;
        }
        .ct-promo-card {
            min-width: 220px;
            flex: 0 0 auto;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            text-align: center;
            transition: transform 0.2s;
        }
        .ct-promo-card:hover {
            transform: scale(1.03);
        }
        .ct-promo-card img {
            max-width: 100%;
            height: 160px;
            object-fit: contain;
            padding: 10px;
        }
       .ct-promo-title {
    font-size: 13px !important;
    font-weight: 600 !important;
    padding: 4px 10px !important;
    line-height: 1.3 !important;
    height: 2.6em !important; /* exactamente 2 líneas */
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    display: -webkit-box !important;
    -webkit-line-clamp: 2 !important;
    -webkit-box-orient: vertical !important;
    white-space: normal !important;
}

        .ct-promo-precios {
            padding: 4px;
            font-size: 14px;
        }
        .ct-precio-normal {
            text-decoration: line-through;
            color: #888;
            margin-right: 5px;
        }
        .ct-precio-oferta {
            color: #c00;
            font-weight: bold;
        }
        .ct-promo-fechas {
            font-size: 11px;
            color: #555;
            padding-bottom: 6px;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const slider = document.querySelector('.ct-promos-slider');
            let scrollStep = 1;
            let scrollInterval = setInterval(() => {
                if (slider.scrollLeft + slider.clientWidth >= slider.scrollWidth) {
                    slider.scrollLeft = 0;
                } else {
                    slider.scrollLeft += scrollStep;
                }
            }, 30);
        });
    </script>
    <?php

    return ob_get_clean();
}

add_shortcode('ct_promociones', 'ct_shortcode_promociones');






// =================================================================================================
// 1. REGISTRAR ESTADO PERSONALIZADO "COTIZACIÓN"
// =================================================================================================

add_filter('woocommerce_register_shop_order_post_statuses', function($statuses) {

    $statuses['wc-cotizacion'] = [
        'label'                     => _x('Cotización', 'Order status', 'woocommerce'),
        'public'                    => false,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop(
            'Cotización <span class="count">(%s)</span>',
            'Cotizaciones <span class="count">(%s)</span>',
            'woocommerce'
        )
    ];

    return $statuses;
});


// Mostrar estado en WooCommerce
add_filter('wc_order_statuses', function($statuses) {

    $new_statuses = [];

    foreach ($statuses as $key => $label) {

        $new_statuses[$key] = $label;

        if ('wc-pending' === $key) {

            $new_statuses['wc-cotizacion'] =
                _x('Cotización', 'Order status', 'woocommerce');
        }
    }

    return $new_statuses;
});


// =================================================================================================
// 2. BOTÓN "GENERAR COTIZACIÓN" EN CHECKOUT
// =================================================================================================

add_action('woocommerce_review_order_before_submit', function() {

    echo '
    <button
        type="button"
        class="button alt"
        id="btn-generar-cotizacion"
        style="
            margin-top:10px;
            width:100%;
            background:#111;
            color:#fff;
            font-weight:bold;
        "
    >
        Generar cotización
    </button>
    ';

    echo '
    <script>
    jQuery(document).ready(function($) {

        $("#btn-generar-cotizacion").on("click", function(e) {

            e.preventDefault();

            var boton = $(this);

            boton.prop("disabled", true);
            boton.text("Generando cotización...");

            var formData = $("form.checkout").serialize();

            $.post(
                wc_checkout_params.ajax_url,
                {
                    action: "ctonline_generar_pedido_cotizacion",
                    form_data: formData
                },
                function(response) {

                    if (
                        response.success &&
                        response.data &&
                        response.data.redirect_url
                    ) {

                        window.location.href =
                            response.data.redirect_url;

                    } else {

                        var mensaje =
                            response.data &&
                            response.data.message
                            ? response.data.message
                            : "No se pudo generar la cotización.";

                        alert("❌ " + mensaje);

                        boton
                            .prop("disabled", false)
                            .text("Generar cotización");
                    }

                }
            ).fail(function(xhr) {

                console.log(
                    "Error AJAX cotización:",
                    xhr
                );

                alert(
                    "❌ Ocurrió un error al generar la cotización."
                );

                boton
                    .prop("disabled", false)
                    .text("Generar cotización");
            });

        });

    });
    </script>
    ';
});


// =================================================================================================
// 3. AJAX - CREAR PEDIDO DE COTIZACIÓN
// =================================================================================================

add_action(
    'wp_ajax_ctonline_generar_pedido_cotizacion',
    'ctonline_generar_pedido_cotizacion'
);

add_action(
    'wp_ajax_nopriv_ctonline_generar_pedido_cotizacion',
    'ctonline_generar_pedido_cotizacion'
);


function ctonline_generar_pedido_cotizacion() {

    try {

        error_log('=== INICIO GENERAR COTIZACION ===');


        // =========================================================================================
        // VALIDAR DATOS
        // =========================================================================================

        if (!isset($_POST['form_data'])) {

            wp_send_json_error([
                'message' => 'Sin datos de formulario.'
            ]);
        }


        parse_str(
            wp_unslash($_POST['form_data']),
            $form
        );


        error_log(
            'FORMULARIO RECIBIDO: ' .
            print_r($form, true)
        );


        // =========================================================================================
        // VALIDAR CARRITO
        // =========================================================================================

        if (
            !function_exists('WC') ||
            !WC()->cart ||
            WC()->cart->is_empty()
        ) {

            wp_send_json_error([
                'message' => 'El carrito está vacío.'
            ]);
        }


        // =========================================================================================
        // ASEGURAR SESIÓN
        // =========================================================================================

        if (WC()->session) {

            WC()->session->set_customer_session_cookie(true);
        }


        // =========================================================================================
        // PAQUETERÍA
        // =========================================================================================

        $paqueteria = '';


        if (WC()->session) {

            $paqueteria =
                WC()->session->get(
                    'paqueteria_seleccionada',
                    ''
                );
        }


        if (empty($paqueteria)) {

            $paqueteria =
                sanitize_text_field(
                    $form['paqueteria_envio'] ?? ''
                );
        }


        // =========================================================================================
        // COSTO ENVÍO
        // =========================================================================================

        $envio = 0;


        if (WC()->session) {

            $envio =
                floatval(
                    WC()->session->get(
                        'ct_envio_precio',
                        0
                    )
                );
        }


        if (
            $envio <= 0 &&
            isset($form['ct_envio_precio'])
        ) {

            $envio =
                floatval(
                    $form['ct_envio_precio']
                );
        }


        error_log(
            'COTIZACION - ENVIO: ' .
            $envio
        );


        error_log(
            'COTIZACION - PAQUETERIA: ' .
            print_r(
                $paqueteria,
                true
            )
        );


        // =========================================================================================
        // CREAR PEDIDO
        // =========================================================================================

        error_log(
            'COTIZACION - CREANDO PEDIDO'
        );


        $order =
            wc_create_order();


        if (is_wp_error($order)) {

            throw new Exception(
                'Error al crear pedido: ' .
                $order->get_error_message()
            );
        }


        $order_id =
            $order->get_id();


        error_log(
            'COTIZACION - PEDIDO CREADO: #' .
            $order_id
        );


        // =========================================================================================
        // AGREGAR PRODUCTOS
        // =========================================================================================

        foreach (
            WC()->cart->get_cart()
            as $cart_item_key => $item
        ) {

            if (
                empty($item['data']) ||
                !is_a(
                    $item['data'],
                    'WC_Product'
                )
            ) {

                continue;
            }


            $item_id =
                $order->add_product(
                    $item['data'],
                    intval(
                        $item['quantity']
                    )
                );


            if (!$item_id) {

                error_log(
                    'COTIZACION - NO SE PUDO AGREGAR PRODUCTO'
                );

                continue;
            }


            // =====================================================================================
            // 🔒 GUARDAR PRECIO EXACTO DE LA COTIZACIÓN
            // =====================================================================================
            //
            // El producto puede venir de CT Online.
            // Guardamos el precio que realmente tiene en este momento.
            //
            // Después, al comprar la cotización, ese precio será recuperado.
            //

            $order_item =
                $order->get_item(
                    $item_id
                );


            if ($order_item) {

                $precio_unitario =
                    floatval(
                        $item['data']->get_price()
                    );


                $cantidad =
                    max(
                        1,
                        intval(
                            $item['quantity']
                        )
                    );


                $total_linea =
                    $precio_unitario *
                    $cantidad;


                $order_item->set_subtotal(
                    $total_linea
                );


                $order_item->set_total(
                    $total_linea
                );


                $order_item->save();
            }
        }


        error_log(
            'COTIZACION - PRODUCTOS AGREGADOS'
        );


        // =========================================================================================
        // DIRECCIÓN DEL CLIENTE
        // =========================================================================================

        $address = [

            'first_name' =>
                sanitize_text_field(
                    $form['billing_first_name'] ?? ''
                ),

            'last_name' =>
                sanitize_text_field(
                    $form['billing_last_name'] ?? ''
                ),

            'email' =>
                sanitize_email(
                    $form['billing_email'] ?? ''
                ),

            'phone' =>
                sanitize_text_field(
                    $form['billing_phone'] ?? ''
                ),

            'address_1' =>
                sanitize_text_field(
                    $form['billing_address_1'] ?? ''
                ),

            'address_2' =>
                sanitize_text_field(
                    $form['billing_address_2'] ?? ''
                ),

            'city' =>
                sanitize_text_field(
                    $form['billing_city'] ?? ''
                ),

            'state' =>
                sanitize_text_field(
                    $form['billing_state'] ?? ''
                ),

            'postcode' =>
                sanitize_text_field(
                    $form['billing_postcode'] ?? ''
                ),

            'country' =>
                sanitize_text_field(
                    $form['billing_country'] ?? ''
                )
        ];


        $order->set_address(
            $address,
            'billing'
        );


        $order->set_address(
            $address,
            'shipping'
        );


        error_log(
            'COTIZACION - DIRECCION GUARDADA'
        );


        // =========================================================================================
        // META
        // =========================================================================================

        $order->update_meta_data(
            '_ctonline_envio_costo',
            $envio
        );


        $order->update_meta_data(
            '_paqueteria_seleccionada',
            $paqueteria
        );


        $order->update_meta_data(
            '_ctonline_es_cotizacion',
            'yes'
        );


        // =========================================================================================
        // FECHA DE EXPIRACIÓN - 5 DÍAS
        // =========================================================================================

        $fecha_expiracion =
            time() +
            (5 * DAY_IN_SECONDS);


        $order->update_meta_data(
            '_ctonline_fecha_expiracion',
            $fecha_expiracion
        );


        // =========================================================================================
        // AGREGAR ENVÍO
        // =========================================================================================

        if ($envio > 0) {

            $shipping_item =
                new WC_Order_Item_Shipping();


            $shipping_title =
                'Envío';


            if (!empty($paqueteria)) {

                $paqueteria_data =
                    json_decode(
                        $paqueteria,
                        true
                    );


                if (is_array($paqueteria_data)) {

                    $nombres = [];


                    foreach (
                        $paqueteria_data
                        as $info
                    ) {

                        if (
                            is_array($info) &&
                            !empty(
                                $info['paqueteria']
                            )
                        ) {

                            $nombres[] =
                                $info['paqueteria'];
                        }
                    }


                    if (!empty($nombres)) {

                        $shipping_title .=
                            ' ' .
                            implode(
                                ', ',
                                array_unique($nombres)
                            );
                    }

                } else {

                    $shipping_title .=
                        ' ' .
                        $paqueteria;
                }
            }


            $shipping_item->set_method_title(
                $shipping_title
            );


            $shipping_item->set_method_id(
                'cotizacion_envio'
            );


            $shipping_item->set_total(
                $envio
            );


            $order->add_item(
                $shipping_item
            );
        }


        // =========================================================================================
        // GUARDAR PRODUCTOS Y PRECIOS DE LA COTIZACIÓN
        // =========================================================================================

        $productos_cotizacion = [];


        foreach (
            $order->get_items('line_item')
            as $item
        ) {

            $cantidad =
                max(
                    1,
                    intval(
                        $item->get_quantity()
                    )
                );


            $total =
                floatval(
                    $item->get_total()
                );


            $productos_cotizacion[] = [

                'product_id' =>
                    $item->get_product_id(),

                'variation_id' =>
                    $item->get_variation_id(),

                'quantity' =>
                    $cantidad,

                // Total de la línea pactado
                'total' =>
                    $total,

                // Precio unitario pactado
                'unit_price' =>
                    $total / $cantidad
            ];
        }


        $order->update_meta_data(
            '_ctonline_productos_cotizacion',
            $productos_cotizacion
        );


        error_log(
            'COTIZACION - PRECIOS GUARDADOS: ' .
            print_r(
                $productos_cotizacion,
                true
            )
        );


        // =========================================================================================
        // CALCULAR
        // =========================================================================================

        $order->calculate_totals();


        error_log(
            'COTIZACION - TOTALES CALCULADOS'
        );


        // =========================================================================================
        // ESTADO
        // =========================================================================================

        $order->update_status(
            'cotizacion',
            'Pedido de cotización generado.'
        );


        error_log(
            'COTIZACION - ESTADO COTIZACION'
        );


        // =========================================================================================
        // URL
        // =========================================================================================

        $comprar_url =
            add_query_arg(

                [
                    'cotizacion' =>
                        $order->get_id()
                ],

                wc_get_checkout_url()
            );


        $order->update_meta_data(
            '_ctonline_comprar_cotizacion_url',
            esc_url_raw(
                $comprar_url
            )
        );


        $order->save();


        error_log(
            'COTIZACION - PEDIDO GUARDADO'
        );


        // =========================================================================================
        // PDF
        // =========================================================================================

        error_log(
            'COTIZACION - ANTES DE GENERAR PDF'
        );


        do_action(
            'ctonline_enviar_pdf_cotizacion',
            $order->get_id()
        );


        error_log(
            'COTIZACION - PDF TERMINADO'
        );


        // =========================================================================================
        // VACIAR CARRITO
        // =========================================================================================

        WC()->cart->empty_cart();


        // =========================================================================================
        // REDIRECCIÓN
        // =========================================================================================

        $redirect_url =
            add_query_arg(

                [
                    'id' =>
                        $order->get_id(),

                    'print' =>
                        1
                ],

                site_url(
                    '/cotizacion/'
                )
            );


        error_log(
            'COTIZACION - REDIRECT: ' .
            $redirect_url
        );


        wp_send_json_success([

            'redirect_url' =>
                $redirect_url,

            'order_id' =>
                $order->get_id()

        ]);


    } catch (Throwable $e) {

        error_log(
            '❌ ERROR GENERAR COTIZACION: ' .
            $e->getMessage()
        );


        error_log(
            'ARCHIVO: ' .
            $e->getFile()
        );


        error_log(
            'LINEA: ' .
            $e->getLine()
        );


        wp_send_json_error([

            'message' =>
                'Error interno: ' .
                $e->getMessage(),

            'archivo' =>
                $e->getFile(),

            'linea' =>
                $e->getLine()

        ]);
    }
}


// =================================================================================================
// 4. GENERAR PDF Y ENVIAR POR CORREO
// =================================================================================================

add_action(
    'ctonline_enviar_pdf_cotizacion',
    function($order_id) {

        try {

            $order =
                wc_get_order(
                    $order_id
                );


            if (!$order) {

                error_log(
                    'CYBER DEPOT PDF: Pedido no encontrado.'
                );

                return;
            }


            // =====================================================================================
            // VERIFICAR PLUGIN PDF
            // =====================================================================================

            if (
                !function_exists(
                    'wcpdf_get_document'
                )
            ) {

                error_log(
                    'CYBER DEPOT PDF: WooCommerce PDF Invoices & Packing Slips no disponible.'
                );

                return;
            }


            // =====================================================================================
            // OBTENER DOCUMENTO
            // =====================================================================================

            $document =
                wcpdf_get_document(
                    'invoice',
                    $order
                );


            if (!$document) {

                error_log(
                    'CYBER DEPOT PDF: No se pudo obtener documento.'
                );

                return;
            }


            error_log(
                'CYBER DEPOT PDF: Documento obtenido: ' .
                get_class($document)
            );


            // =====================================================================================
            // GENERAR PDF
            // =====================================================================================

            /*
             * No utilizar:
             *
             * $document->export('save');
             *
             * porque la versión instalada no dispone de ese método.
             */


            $pdf_path = '';


            // Intentar obtener archivo ya generado
            if (
                method_exists(
                    $document,
                    'get_pdf_path'
                )
            ) {

                $pdf_path =
                    $document->get_pdf_path();
            }


            // =====================================================================================
            // SI NO EXISTE, INTENTAR GENERAR PDF
            // =====================================================================================

            if (
                empty($pdf_path) ||
                !file_exists($pdf_path)
            ) {

                if (
                    method_exists(
                        $document,
                        'get_pdf'
                    )
                ) {

                    $pdf =
                        $document->get_pdf();


                    if (!empty($pdf)) {

                        $upload =
                            wp_upload_dir();


                        $pdf_path =
                            trailingslashit(
                                $upload['basedir']
                            ) .
                            'cotizacion-' .
                            $order->get_id() .
                            '.pdf';


                        file_put_contents(
                            $pdf_path,
                            $pdf
                        );
                    }
                }
            }


            // =====================================================================================
            // VERIFICAR PDF
            // =====================================================================================

            if (
                empty($pdf_path) ||
                !file_exists($pdf_path)
            ) {

                error_log(
                    'CYBER DEPOT PDF: No se pudo obtener/generar el archivo PDF.'
                );

                return;
            }


            error_log(
                'CYBER DEPOT PDF: PDF generado correctamente: ' .
                $pdf_path
            );


            // =====================================================================================
            // DATOS
            // =====================================================================================

            $envio =
                floatval(
                    $order->get_meta(
                        '_ctonline_envio_costo',
                        true
                    )
                );


            $paqueteria =
                $order->get_meta(
                    '_paqueteria_seleccionada',
                    true
                );


            // =====================================================================================
            // EMAIL CLIENTE
            // =====================================================================================

            $to =
                $order->get_billing_email();


            $subject =
                'Tu cotización #' .
                $order->get_order_number();


            $headers = [
                'Content-Type: text/html; charset=UTF-8'
            ];


            $attachments = [
                $pdf_path
            ];


            $body =
                'Hola ' .
                esc_html(
                    $order->get_billing_first_name()
                ) .
                ',<br><br>';


            $body .=
                'Adjuntamos tu cotización en PDF.<br><br>';


            if (!empty($paqueteria)) {

                $body .=
                    '<strong>Paquetería:</strong> ' .
                    esc_html($paqueteria) .
                    '<br>';
            }


            if ($envio > 0) {

                $body .=
                    '<strong>Envío:</strong> $' .
                    number_format(
                        $envio,
                        2
                    ) .
                    '<br>';
            }


            $body .=
                '<br>La cotización es válida por 5 días.';


            $body .=
                '<br><br>Gracias por tu interés en Cyber Depot.';


            // =====================================================================================
            // ENVIAR AL CLIENTE
            // =====================================================================================

            if (!empty($to)) {

                $resultado_cliente =
                    wp_mail(
                        $to,
                        $subject,
                        $body,
                        $headers,
                        $attachments
                    );


                error_log(
                    'CYBER DEPOT PDF: correo cliente = ' .
                    (
                        $resultado_cliente
                        ? 'OK'
                        : 'ERROR'
                    )
                );
            }


            // =====================================================================================
            // COPIA CYBER DEPOT
            // =====================================================================================

            $resultado_copia =
                wp_mail(

                    'cyberdepotoficial@gmail.com',

                    'Copia de cotización #' .
                    $order->get_order_number(),

                    'Cotización generada correctamente.<br>' .
                    'Pedido #' .
                    $order->get_order_number(),

                    $headers,

                    $attachments
                );


            error_log(
                'CYBER DEPOT PDF: correo copia = ' .
                (
                    $resultado_copia
                    ? 'OK'
                    : 'ERROR'
                )
            );


        } catch (Throwable $e) {

            error_log(
                '❌ CYBER DEPOT PDF ERROR: ' .
                $e->getMessage()
            );


            error_log(
                'ARCHIVO: ' .
                $e->getFile()
            );


            error_log(
                'LINEA: ' .
                $e->getLine()
            );


            // No detener la creación de la cotización
            return;
        }
    }
);


// =================================================================================================
// 5. BOTÓN "VER COTIZACIÓN PDF" EN MI CUENTA
// =================================================================================================

add_filter(
    'woocommerce_my_account_my_orders_actions',
    function($actions, $order) {

        if (
            $order->get_status()
            ===
            'cotizacion'
        ) {

            $actions['ver_cotizacion_pdf'] = [

                'url' =>
                    home_url(
                        '/cotizacion-pdf/?id=' .
                        $order->get_id()
                    ),

                'name' =>
                    __('📄 Ver Cotización', 'woocommerce')
            ];
        }


        return $actions;

    },
    10,
    2
);


// =================================================================================================
// 6. ACCIÓN ADMINISTRATIVA - CONVERTIR A PEDIDO REAL
// =================================================================================================

add_filter(
    'woocommerce_order_actions',
    function($actions, $order) {

        if (
            $order->get_status()
            ===
            'cotizacion'
        ) {

            $actions['convertir_a_pedido_real'] =
                'Convertir a pedido real';
        }


        return $actions;

    },
    10,
    2
);


add_action(
    'woocommerce_order_action_convertir_a_pedido_real',
    function($order) {

        $order->update_status(
            'processing',
            'Pedido convertido desde cotización.'
        );
    }
);


// =================================================================================================
// 7. PDF - PERMITIR ESTADO COTIZACIÓN
// =================================================================================================

add_filter(
    'wpo_wcpdf_email_allowed_statuses',
    function($statuses) {

        $statuses[] =
            'cotizacion';


        return array_unique(
            $statuses
        );
    }
);


// =================================================================================================
// 8. REGISTRAR DOCUMENTO PDF "COTIZACIÓN"
// =================================================================================================

add_filter(
    'wpo_wcpdf_documents',
    function($documents) {

        $documents['cotizacion'] = [

            'name' =>
                __('Cotización', 'woocommerce-pdf-invoices-packing-slips'),

            'description' =>
                __('Documento PDF de cotización', 'woocommerce-pdf-invoices-packing-slips'),

            'template' =>
                'simple',

            'attach_to' =>
                ['cotizacion'],

            'number' =>
                false,

            'invoice_type' =>
                false
        ];


        return $documents;
    }
);


// =================================================================================================
// 9. PDF VISIBLE EN MI CUENTA
// =================================================================================================

add_filter(
    'wpo_wcpdf_my_account_allowed_order_statuses',
    function($statuses) {

        $statuses[] =
            'cotizacion';


        return array_unique(
            $statuses
        );
    }
);


// =================================================================================================
// 10. SHORTCODE [mostrar_cotizacion_pdf]
// =================================================================================================

add_shortcode(
    'mostrar_cotizacion_pdf',
    function() {

        ob_start();


        $order_id =
            isset($_GET['id'])
            ? absint($_GET['id'])
            : 0;


        if (!$order_id) {

            echo '<p style="color:red;">ID de cotización no válido.</p>';

            return ob_get_clean();
        }


        $order =
            wc_get_order(
                $order_id
            );


        if (!$order) {

            echo '<p style="color:red;">Cotización no encontrada.</p>';

            return ob_get_clean();
        }


        if (
            $order->get_status()
            !==
            'cotizacion'
        ) {

            echo '<p style="color:red;">Este pedido no es una cotización.</p>';

            return ob_get_clean();
        }


        // -----------------------------------------------------------------------------------------
        // Datos
        // -----------------------------------------------------------------------------------------

        $envio =
            floatval(
                $order->get_meta(
                    '_ctonline_envio_costo',
                    true
                )
            );


        $paqueteria =
            $order->get_meta(
                '_paqueteria_seleccionada',
                true
            );


        $comprar_url =
            $order->get_meta(
                '_ctonline_comprar_cotizacion_url',
                true
            );


        if (empty($comprar_url)) {

            $comprar_url =
                add_query_arg(

                    [
                        'cotizacion' =>
                            $order->get_id()
                    ],

                    wc_get_checkout_url()
                );
        }


        // =========================================================================================
        // INCLUIR PLANTILLA
        // =========================================================================================

        $plantilla =
            plugin_dir_path(__FILE__) .
            'page-cotizacion-pdf.php';


        if (file_exists($plantilla)) {

            include $plantilla;

        } else {

            echo '<p style="color:red;">No se encontró page-cotizacion-pdf.php</p>';
        }


        return ob_get_clean();
    }
);


// =================================================================================================
// 11. CARGAR COTIZACIÓN EN CHECKOUT
// =================================================================================================

add_action(
    'template_redirect',
    'ctonline_cargar_cotizacion_checkout'
);


function ctonline_cargar_cotizacion_checkout() {

    if (
        !is_checkout() ||
        empty($_GET['cotizacion'])
    ) {

        return;
    }


    $order_id =
        absint(
            $_GET['cotizacion']
        );


    if (!$order_id) {
        return;
    }


    $order =
        wc_get_order(
            $order_id
        );


    if (!$order) {
        return;
    }


    // =============================================================================================
    // SOLO COTIZACIONES
    // =============================================================================================

    if (
        $order->get_status()
        !==
        'cotizacion'
    ) {

        return;
    }


    // =============================================================================================
    // VERIFICAR EXPIRACIÓN
    // =============================================================================================

    $fecha_expiracion =
        absint(
            $order->get_meta(
                '_ctonline_fecha_expiracion',
                true
            )
        );


    if (
        $fecha_expiracion &&
        time() > $fecha_expiracion
    ) {

        wc_add_notice(
            '❌ Esta cotización #' .
            $order->get_order_number() .
            ' ha expirado. La cotización tenía una vigencia de 5 días.',
            'error'
        );


        return;
    }


    // =============================================================================================
    // EVITAR RECARGAR LA MISMA COTIZACIÓN
    // =============================================================================================

    $ya_cargada =
        WC()->session->get(
            '_ctonline_cotizacion_cargada'
        );


    if (
        intval($ya_cargada)
        ===
        intval($order_id)
    ) {

        return;
    }


    // =============================================================================================
    // VACIAR CARRITO
    // =============================================================================================

    WC()->cart->empty_cart();


    // =============================================================================================
    // AGREGAR PRODUCTOS
    // =============================================================================================

    foreach (
        $order->get_items('line_item')
        as $item
    ) {

        $product_id =
            $item->get_product_id();


        $variation_id =
            $item->get_variation_id();


        $quantity =
            $item->get_quantity();


        if (
            !$product_id ||
            $quantity <= 0
        ) {

            continue;
        }


        $cart_key = false;


        if ($variation_id) {

            $cart_key =
                WC()->cart->add_to_cart(

                    $product_id,

                    $quantity,

                    $variation_id
                );

        } else {

            $cart_key =
                WC()->cart->add_to_cart(

                    $product_id,

                    $quantity
                );
        }


        if ($cart_key) {

            error_log(
                'COTIZACION - PRODUCTO CARGADO AL CARRITO: #' .
                $product_id
            );
        }
    }


    // =============================================================================================
    // 🔒 RECUPERAR PRECIOS PACTADOS
    // =============================================================================================

    $productos_cotizacion =
        $order->get_meta(
            '_ctonline_productos_cotizacion',
            true
        );


    if (
        !is_array($productos_cotizacion)
    ) {

        $productos_cotizacion = [];
    }


    // Guardar precios pactados en sesión
    WC()->session->set(
        '_ctonline_precios_cotizacion',
        $productos_cotizacion
    );


    error_log(
        'COTIZACION - PRECIOS PACTADOS RECUPERADOS: ' .
        print_r(
            $productos_cotizacion,
            true
        )
    );


    // =============================================================================================
    // RECUPERAR ENVÍO
    // =============================================================================================

    $envio =
        floatval(
            $order->get_meta(
                '_ctonline_envio_costo',
                true
            )
        );


    // =============================================================================================
    // RECUPERAR PAQUETERÍA
    // =============================================================================================

    $paqueteria =
        $order->get_meta(
            '_paqueteria_seleccionada',
            true
        );


    // =============================================================================================
    // GUARDAR EN SESIÓN
    // =============================================================================================

    WC()->session->set(
        'ct_envio_precio',
        $envio
    );


    WC()->session->set(
        'paqueteria_seleccionada',
        $paqueteria
    );


    // Compatibilidad con código anterior
    WC()->session->set(
        '_ctonline_envio_costo',
        $envio
    );


    WC()->session->set(
        '_paqueteria_seleccionada',
        $paqueteria
    );


    // =============================================================================================
    // IDENTIFICAR COTIZACIÓN
    // =============================================================================================

    WC()->session->set(
        '_ctonline_cotizacion_origen',
        $order_id
    );


    WC()->session->set(
        '_ctonline_cotizacion_cargada',
        $order_id
    );


    // =============================================================================================
    // DATOS DEL CLIENTE
    // =============================================================================================

    if (
        $order->get_billing_email()
    ) {

        WC()->customer->set_billing_first_name(
            $order->get_billing_first_name()
        );

        WC()->customer->set_billing_last_name(
            $order->get_billing_last_name()
        );

        WC()->customer->set_billing_email(
            $order->get_billing_email()
        );

        WC()->customer->set_billing_phone(
            $order->get_billing_phone()
        );

        WC()->customer->set_billing_address_1(
            $order->get_billing_address_1()
        );

        WC()->customer->set_billing_address_2(
            $order->get_billing_address_2()
        );

        WC()->customer->set_billing_city(
            $order->get_billing_city()
        );

        WC()->customer->set_billing_state(
            $order->get_billing_state()
        );

        WC()->customer->set_billing_postcode(
            $order->get_billing_postcode()
        );

        WC()->customer->set_billing_country(
            $order->get_billing_country()
        );


        WC()->customer->set_shipping_first_name(
            $order->get_shipping_first_name()
        );

        WC()->customer->set_shipping_last_name(
            $order->get_shipping_last_name()
        );

        WC()->customer->set_shipping_address_1(
            $order->get_shipping_address_1()
        );

        WC()->customer->set_shipping_address_2(
            $order->get_shipping_address_2()
        );

        WC()->customer->set_shipping_city(
            $order->get_shipping_city()
        );

        WC()->customer->set_shipping_state(
            $order->get_shipping_state()
        );

        WC()->customer->set_shipping_postcode(
            $order->get_shipping_postcode()
        );

        WC()->customer->set_shipping_country(
            $order->get_shipping_country()
        );
    }


    // =============================================================================================
    // AVISO EN CHECKOUT
    // =============================================================================================

    wc_add_notice(

        'Los productos de tu cotización #' .
        $order->get_order_number() .
        ' fueron cargados al carrito con los precios pactados.',

        'notice'
    );


    // Recalcular carrito
    WC()->cart->calculate_totals();
}


// =================================================================================================
// 12. 🔒 APLICAR PRECIOS CONGELADOS DE LA COTIZACIÓN
// =================================================================================================
//
// IMPORTANTE:
//
// No modifica el precio del producto en WooCommerce.
//
// Solamente modifica el objeto del producto dentro del carrito cuando:
//
// _ctonline_cotizacion_origen
//
// existe en la sesión.
//
// Esto permite que las compras normales sigan utilizando los precios
// actuales de CT Online.
//
// =================================================================================================

add_action(
    'woocommerce_before_calculate_totals',
    'ctonline_aplicar_precios_cotizacion',
    9999
);




// 1. Submenú "Consultar Guías" en el menú CT ONLINE
add_action('admin_menu', function () {
    add_submenu_page(
        'ctonline_confirmar_pedidos', // Slug del menú padre
        'Consultar Guías',            // Título de la página
        'Consultar Guías',            // Texto en el submenú
        'manage_woocommerce',         // Capacidad requerida
        'ctonline_consultar_guias',   // Slug de la nueva página
        'ctonline_pagina_consultar_guias' // Callback para mostrar contenido
    );
});

// Página para consultar guías por folio
function ctonline_pagina_consultar_guias() {
    ?>
    <div class="wrap">
        <h1>Consultar Guías por Folio</h1>
        <p>Ingresa el folio del pedido (ej. W03-017848) y haz clic en consultar:</p>

        <input type="text" id="folio-input" placeholder="Ej. W03-017848" style="width:250px; padding:5px;">
        <button id="consultar-btn" class="button button-primary">Consultar Guías</button>

        <div id="resultado-guias" style="margin-top:20px;"></div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const btn = document.getElementById('consultar-btn');
        const input = document.getElementById('folio-input');
        const resultado = document.getElementById('resultado-guias');

        btn.addEventListener('click', function () {
            const folio = input.value.trim();
            if (!folio) {
                resultado.innerHTML = '<div style="color:red;">Por favor ingresa un folio.</div>';
                return;
            }

            resultado.innerHTML = '<em>Consultando guías...</em>';

            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'consultar_guias_admin_ctonline',
                    folio: folio
                })
            })
            .then(res => res.json())
            .then(data => {
                resultado.innerHTML = '';
                if (data.guias) {
                    const paqueteria = (data.paqueteria || 'estafeta').toLowerCase();
                    let base = '';
                    if (paqueteria === 'estafeta') {
                        base = 'https://www.estafeta.com/rastrear-envio/?guia=';
                    } else if (paqueteria === 'paquetexpress') {
                        base = 'https://www.paquetexpress.com.mx/rastreo/';
                    }

                    data.guias.forEach(function (guia) {
                        const div = document.createElement('div');
                        const link = document.createElement('a');
                        link.href = base + encodeURIComponent(guia);
                        link.textContent = '🔗 ' + guia;
                        link.target = '_blank';
                        link.rel = 'noopener noreferrer';
                        div.appendChild(link);
                        resultado.appendChild(div);
                    });
                } else if (data.mensaje) {
                    resultado.innerHTML = '<div style="color:red;">' + data.mensaje + '</div>';
                } else {
                    resultado.textContent = 'No se pudo consultar guías.';
                }
            })
            .catch(() => {
                resultado.innerHTML = '<div style="color:red;">Error al consultar guías.</div>';
            });
        });
    });
    </script>
    <?php
}

// 2. Handler AJAX para consulta desde el Admin
add_action('wp_ajax_consultar_guias_admin_ctonline', 'ajax_consultar_guias_admin_ctonline');

function ajax_consultar_guias_admin_ctonline() {
    $folio = sanitize_text_field($_POST['folio'] ?? '');

    if (!$folio) {
        wp_send_json_error(['mensaje' => 'Folio requerido.']);
    }

    // ✅ Fallback de prueba
    if ($folio === 'W03-017848') {
        wp_send_json([
            'guias' => ['3058716484660700812468', '1058716484660700812123'],
            'paqueteria' => 'paquetexpress'
        ]);
    }

    if (!function_exists('crearNuevoToken') || !function_exists('servicioApi')) {
        wp_send_json_error(['mensaje' => 'Funciones API no disponibles en el plugin.']);
    }

    $token = crearNuevoToken();
    if (!$token) {
        wp_send_json_error(['mensaje' => 'Error al autenticar con la API.']);
    }

    // ✅ Llamada real a la API CT Online
    $res = servicioApi('GET', 'paqueteria/detalles/guia/' . $folio, null, $token);

    // ✅ Convertir objeto a array
    $res = json_decode(json_encode($res), true);

    // ✅ Debug log
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[CTOnline][Consulta Guías] Folio: ' . $folio . ' | Respuesta: ' . print_r($res, true));
    }

    if (!isset($res['respuesta'])) {
        wp_send_json(['mensaje' => 'No se obtuvo respuesta válida de la API.']);
    }

    $respuesta = $res['respuesta'];

    // ✅ Si hay guías
    if (!empty($respuesta['guias']) && is_array($respuesta['guias'])) {
        wp_send_json([
            'guias' => $respuesta['guias'],
            'paqueteria' => $respuesta['paqueteria'] ?? 'estafeta'
        ]);
    }

    // ✅ Si no hay guías pero hay mensaje
    if (!empty($respuesta['mensaje'])) {
        wp_send_json([
            'mensaje' => $respuesta['mensaje']
        ]);
    }

    // ✅ Fallback final
    wp_send_json([
        'mensaje' => 'Las guías se encuentran pendiente de asignar, consultar más tarde.'
    ]);
}
add_filter( 'woocommerce_checkout_fields', 'cambiar_etiqueta_direccion_calle' );
function cambiar_etiqueta_direccion_calle( $fields ) {
    $fields['billing']['billing_address_1']['label'] = 'Dirección completa con # número de casa y calle';
    return $fields;
}

// ============================================================
// SHORTCODE: [filtro_acordeon]
// SIDEBAR INTELIGENTE PARA WOOCOMMERCE
// ============================================================

add_shortcode('filtro_acordeon', function() {

    ob_start();

    // ========================================================
    // DETECTAR SI ESTAMOS EN UNA CATEGORÍA DE PRODUCTO
    // ========================================================

    $current_cat_id = 0;
    $current_cat = null;

    if (is_product_category()) {

        $current_cat = get_queried_object();

        if ($current_cat && !is_wp_error($current_cat)) {
            $current_cat_id = $current_cat->term_id;
        }
    }


    // ========================================================
    // OBTENER CATEGORÍAS PRINCIPALES
    // parent = 0
    // ========================================================

    $categorias_principales = get_terms([
        'taxonomy'   => 'product_cat',
        'parent'     => 0,
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'ASC'
    ]);


    // ========================================================
    // OBTENER TODAS LAS SUBCATEGORÍAS
    //
    // parent != 0
    // ========================================================

    $todas_las_categorias = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'ASC'
    ]);

    $todas_las_subcategorias = [];

    if (!empty($todas_las_categorias) && !is_wp_error($todas_las_categorias)) {

        foreach ($todas_las_categorias as $cat) {

            if ((int)$cat->parent > 0) {
                $todas_las_subcategorias[] = $cat;
            }
        }
    }


    // ========================================================
    // SI ESTAMOS DENTRO DE UNA CATEGORÍA,
    // OBTENER SUS HIJAS DIRECTAS
    // ========================================================

    $subcategorias_actuales = [];

    if ($current_cat_id > 0) {

        $subcategorias_actuales = get_terms([
            'taxonomy'   => 'product_cat',
            'parent'     => $current_cat_id,
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC'
        ]);
    }


    ?>

    <div class="sidebar-accordion-fixed" id="sidebar-accordion-fixed">

        <div class="sidebar-scroll">


            <!-- =================================================
                 MARCAS
            ================================================= -->

            <div class="accordion-item">

                <button class="accordion-header">
                    <span class="arrow">►</span>
                    Marcas
                </button>

                <div class="accordion-content">

                    <ul>

                        <?php

                        $brands = get_terms([
                            'taxonomy'   => 'product_brand',
                            'hide_empty' => true,
                            'orderby'    => 'name',
                            'order'      => 'ASC'
                        ]);

                        if (!empty($brands) && !is_wp_error($brands)) {

                            foreach ($brands as $brand) {

                                echo '<li>';
                                echo '<a href="' .
                                    esc_url(get_term_link($brand)) .
                                    '">' .
                                    esc_html($brand->name) .
                                    '</a>';
                                echo '</li>';
                            }

                        } else {

                            echo '<li>No hay marcas</li>';
                        }

                        ?>

                    </ul>

                </div>

            </div>


            <!-- =================================================
                 CATEGORÍAS PRINCIPALES
            ================================================= -->

            <div class="accordion-item">

                <button class="accordion-header">
                    <span class="arrow">►</span>
                    Categorías
                </button>

                <div class="accordion-content">

                    <ul>

                        <?php

                        if (!empty($categorias_principales) &&
                            !is_wp_error($categorias_principales)) {

                            foreach ($categorias_principales as $cat) {

                                echo '<li>';
                                echo '<a href="' .
                                    esc_url(get_term_link($cat)) .
                                    '">' .
                                    esc_html($cat->name) .
                                    '</a>';
                                echo '</li>';
                            }

                        } else {

                            echo '<li>No hay categorías</li>';
                        }

                        ?>

                    </ul>

                </div>

            </div>


            <!-- =================================================
                 SUBCATEGORÍAS
            ================================================= -->

            <div class="accordion-item">

                <button class="accordion-header">
                    <span class="arrow">►</span>

                    <?php
                    if ($current_cat_id > 0 && $current_cat) {
                        echo 'Subcategorías de ' .
                            esc_html($current_cat->name);
                    } else {
                        echo 'Subcategorías';
                    }
                    ?>

                </button>

                <div class="accordion-content">

                    <ul>

                        <?php

                        // =============================================
                        // SI ESTAMOS EN UNA CATEGORÍA:
                        // MOSTRAR SOLO SUS HIJAS
                        // =============================================

                        if ($current_cat_id > 0) {

                            if (!empty($subcategorias_actuales) &&
                                !is_wp_error($subcategorias_actuales)) {

                                foreach ($subcategorias_actuales as $cat) {

                                    echo '<li>';
                                    echo '<a href="' .
                                        esc_url(get_term_link($cat)) .
                                        '">' .
                                        esc_html($cat->name) .
                                        '</a>';
                                    echo '</li>';
                                }

                            } else {

                                // Si no tiene hijas

                                echo '<li>No hay subcategorías</li>';
                            }

                        }

                        // =============================================
                        // INICIO / TIENDA / OTRAS PÁGINAS
                        // MOSTRAR TODAS LAS SUBCATEGORÍAS
                        // =============================================

                        else {

                            if (!empty($todas_las_subcategorias)) {

                                foreach ($todas_las_subcategorias as $cat) {

                                    echo '<li>';
                                    echo '<a href="' .
                                        esc_url(get_term_link($cat)) .
                                        '">' .
                                        esc_html($cat->name) .
                                        '</a>';
                                    echo '</li>';
                                }

                            } else {

                                echo '<li>No hay subcategorías</li>';
                            }
                        }

                        ?>

                    </ul>

                </div>

            </div>


            <!-- =================================================
                 CATEGORÍA PADRE
                 SOLO APARECE SI ESTAMOS EN SUBCATEGORÍA
            ================================================= -->

            <?php

            if (
                $current_cat &&
                (int)$current_cat->parent > 0
            ) {

                $parent_cat = get_term(
                    $current_cat->parent,
                    'product_cat'
                );

                if ($parent_cat && !is_wp_error($parent_cat)) {
            ?>

                <div class="accordion-item">

                    <button class="accordion-header">
                        <span class="arrow">►</span>
                        Categoría padre
                    </button>

                    <div class="accordion-content">

                        <ul>

                            <li>

                                <a href="<?php
                                    echo esc_url(
                                        get_term_link($parent_cat)
                                    );
                                ?>">

                                    ← <?php
                                        echo esc_html(
                                            $parent_cat->name
                                        );
                                    ?>

                                </a>

                            </li>

                        </ul>

                    </div>

                </div>

            <?php
                }
            }
            ?>


        </div>

    </div>


    <!-- ========================================================
         BOTÓN TOGGLE
    ======================================================== -->

    <div
        class="sidebar-toggle-btn"
        id="sidebar-toggle-btn"
    >
        ☰
    </div>


    <style>

    /* =========================================================
       SIDEBAR
    ========================================================= */

    .sidebar-accordion-fixed {

        position: fixed;
        top: 169px;
        left: 0;

        width: 190px;

        background: #ff6600;
        color: #fff;

        border-radius: 0 6px 6px 0;

        font-size: 14px;

        z-index: 9999;

        box-shadow:
            2px 2px 6px rgba(0,0,0,0.2);

        transform: translateX(-190px);

        transition:
            transform 0.3s ease;
    }


    .sidebar-accordion-fixed.show {

        transform:
            translateX(0);
    }


    /* =========================================================
       SCROLL
    ========================================================= */

    .sidebar-scroll {

        max-height:
            calc(100vh - 120px);

        overflow-y: auto;

        padding: 0;
    }


    /* =========================================================
       BOTÓN TOGGLE
    ========================================================= */

    .sidebar-toggle-btn {

        position: fixed;

        top: 169px;

        left: 0;

        background: #ff6600;

        color: #fff;

        padding: 10px 14px;

        cursor: pointer;

        font-size: 18px;

        z-index: 10000;

        border-radius:
            0 5px 5px 0;

        transition:
            left 0.3s ease;
    }


    .sidebar-accordion-fixed.show
    + .sidebar-toggle-btn {

        left: 190px;
    }


    /* =========================================================
       ACORDEÓN
    ========================================================= */

    .accordion-item {

        border-bottom:
            1px solid rgba(255,255,255,0.2);
    }


    .accordion-header {

        width: 100%;

        background: #ff6600;

        color: #fff;

        border: none;

        text-align: left;

        padding: 10px;

        font-size: 14px;

        font-weight: bold;

        cursor: pointer;

        display: flex;

        align-items: center;

        gap: 6px;
    }


    .accordion-header:hover {

        background: #e65c00;
    }


    .arrow {

        display: inline-block;

        width: 14px;

        font-weight: bold;

        user-select: none;

        transition:
            transform 0.3s ease;

        transform-origin: center;
    }


    .arrow.rotate {

        transform:
            rotate(90deg);
    }


    .accordion-content {

        display: none;

        padding: 8px;

        background: #ff8533;
    }


    .accordion-content ul {

        list-style: none;

        margin: 0;

        padding: 0;
    }


    .accordion-content li {

        margin-bottom: 7px;
    }


    .accordion-content li a {

        color: #fff;

        text-decoration: none;

        font-size: 13px;

        display: block;

        padding: 2px 0;
    }


    .accordion-content li a:hover {

        text-decoration: underline;
    }


    </style>


    <script>

    document.addEventListener(
        'DOMContentLoaded',
        function() {

            const sidebar =
                document.getElementById(
                    'sidebar-accordion-fixed'
                );

            const toggleBtn =
                document.getElementById(
                    'sidebar-toggle-btn'
                );


            // =================================================
            // ABRIR / CERRAR SIDEBAR
            // =================================================

            if (sidebar && toggleBtn) {

                toggleBtn.addEventListener(
                    'click',
                    function() {

                        sidebar.classList.toggle(
                            'show'
                        );
                    }
                );
            }


            // =================================================
            // ACORDEONES
            // =================================================

            const headers =
                document.querySelectorAll(
                    '.accordion-header'
                );


            headers.forEach(
                header => {

                    header.addEventListener(
                        'click',
                        function() {

                            // CERRAR OTROS

                            headers.forEach(
                                h => {

                                    if (h !== this) {

                                        const content =
                                            h.nextElementSibling;

                                        if (
                                            content &&
                                            content.classList.contains(
                                                'show'
                                            )
                                        ) {

                                            content.classList.remove(
                                                'show'
                                            );

                                            content.style.display =
                                                'none';

                                            const otherArrow =
                                                h.querySelector(
                                                    '.arrow'
                                                );

                                            if (otherArrow) {

                                                otherArrow.classList.remove(
                                                    'rotate'
                                                );
                                            }
                                        }
                                    }
                                }
                            );


                            // ABRIR / CERRAR ACTUAL

                            const content =
                                this.nextElementSibling;

                            const arrow =
                                this.querySelector(
                                    '.arrow'
                                );


                            if (
                                content.classList.contains(
                                    'show'
                                )
                            ) {

                                content.classList.remove(
                                    'show'
                                );

                                content.style.display =
                                    'none';

                                arrow.classList.remove(
                                    'rotate'
                                );

                            } else {

                                content.classList.add(
                                    'show'
                                );

                                content.style.display =
                                    'block';

                                arrow.classList.add(
                                    'rotate'
                                );
                            }

                        }
                    );
                }
            );

        }
    );

    </script>

    <?php

    return ob_get_clean();

});

// ============================================================
// MOSTRAR SIDEBAR AUTOMÁTICAMENTE
// ============================================================

add_action('wp_footer', function() {

    if (
        is_front_page() ||
        is_shop() ||
        is_product_category()
    ) {

        echo do_shortcode('[filtro_acordeon]');
    }

});

//-------------------------------------------------------------------



// ============================================================================
// 👁️ CONTADOR DE VISITAS DE PRODUCTO
// ============================================================================

add_action(
    'template_redirect',
    'contar_visitas_producto'
);

function contar_visitas_producto() {

    if ( ! is_product() ) {
        return;
    }

    global $post;

    if ( ! $post || empty($post->ID) ) {
        return;
    }

    $visitas = get_post_meta(
        $post->ID,
        '_visitas_producto',
        true
    );

    $visitas = $visitas
        ? intval($visitas) + 1
        : 1;

    update_post_meta(
        $post->ID,
        '_visitas_producto',
        $visitas
    );
}


// ============================================================================
// 👁️ MOSTRAR VISITAS EN LA PÁGINA DEL PRODUCTO
// ============================================================================

add_action(
    'woocommerce_single_product_summary',
    'mostrar_visitas_producto',
    25
);

function mostrar_visitas_producto() {

    global $post;

    if ( ! $post || empty($post->ID) ) {
        return;
    }

    $visitas = get_post_meta(
        $post->ID,
        '_visitas_producto',
        true
    );

    echo '<p class="visitas-producto">
        <strong>Visitas:</strong> ' .
        intval($visitas) .
        '</p>';
}

// ============================================================
// CYBERDEPOT - ANALIZADOR DE SLUGS DE CATEGORÍAS
//
// NO MODIFICA NADA.
//
// Busca categorías hijas cuyo slug tenga este patrón:
//
// slug-hijo-slug-padre-numero
//
// Ejemplo:
//
// access-points-red-activa-3
//                 ^^^^^^^^^^
//                 slug padre
//
// ============================================================


// ============================================================
// CREAR PÁGINA EN HERRAMIENTAS
// ============================================================

add_action(
    'admin_menu',
    function () {

        add_management_page(
            'Analizar Categorías Cyberdepot',
            'Analizar Categorías',
            'manage_woocommerce',
            'cyberdepot-analizar-categorias',
            'cyberdepot_mostrar_analisis_categorias'
        );

    }
);


// ============================================================
// FUNCIÓN PRINCIPAL
// ============================================================

function cyberdepot_mostrar_analisis_categorias() {


    // Seguridad
    if (
        !current_user_can('manage_woocommerce')
    ) {
        return;
    }


    echo '<div class="wrap">';

    echo '<h1>🔍 Analizador de Categorías Cyberdepot</h1>';

    echo '<p>Este análisis NO modifica ninguna categoría.</p>';


    // ========================================================
    // OBTENER TODAS LAS CATEGORÍAS
    // ========================================================

    $categorias = get_terms(
        array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false
        )
    );


    if (
        is_wp_error($categorias)
    ) {

        echo '<div class="notice notice-error">';
        echo '<p>Error al obtener categorías.</p>';
        echo '</div>';

        echo '</div>';

        return;
    }


    // ========================================================
    // CONTADORES
    // ========================================================

    $total_categorias = count($categorias);

    $con_padre = 0;

    $patrones_detectados = 0;

    $duplicados_probables = 0;

    $categorias_numericas_normales = 0;


    // ========================================================
    // TABLA
    // ========================================================

    echo '<h2>Resultados</h2>';

    echo '<p>';

    echo '<strong>Total categorías:</strong> ' .
        intval($total_categorias);

    echo '</p>';


    echo '<table class="widefat fixed striped">';


    // ========================================================
    // ENCABEZADOS
    // ========================================================

    echo '<thead>';

    echo '<tr>';

    echo '<th>ID</th>';

    echo '<th>Nombre</th>';

    echo '<th>Padre</th>';

    echo '<th>Slug actual</th>';

    echo '<th>Slug esperado</th>';

    echo '<th>¿Existe categoría correcta?</th>';

    echo '<th>URL actual</th>';

    echo '<th>Diagnóstico</th>';

    echo '</tr>';

    echo '</thead>';


    echo '<tbody>';


    // ========================================================
    // RECORRER TODAS LAS CATEGORÍAS
    // ========================================================

    foreach (
        $categorias as $categoria
    ) {


        // ====================================================
        // SOLO NOS INTERESAN HIJAS
        // ====================================================

        if (
            empty($categoria->parent)
        ) {
            continue;
        }


        $con_padre++;


        // ====================================================
        // OBTENER CATEGORÍA PADRE
        // ====================================================

        $padre = get_term(
            $categoria->parent,
            'product_cat'
        );


        if (
            !$padre ||
            is_wp_error($padre)
        ) {
            continue;
        }


        // ====================================================
        // DATOS
        // ====================================================

        $slug_hijo = $categoria->slug;

        $slug_padre = $padre->slug;


        // ====================================================
        // PATRÓN A BUSCAR
        //
        // -slug-padre
        //
        // Ejemplo:
        //
        // access-points-red-activa-3
        //
        // termina en:
        //
        // -red-activa-3
        // ====================================================

        $patron =
            '-' .
            preg_quote(
                $slug_padre,
                '/'
            ) .
            '-(\d+)$';


        // ====================================================
        // COMPROBAR SI CUMPLE EL PATRÓN
        // ====================================================

        if (
            preg_match(
                '/' . $patron . '/',
                $slug_hijo,
                $matches
            )
        ) {


            $patrones_detectados++;


            // =================================================
            // ELIMINAR:
            //
            // -slug-padre-numero
            //
            // Ejemplo:
            //
            // access-points-red-activa-3
            //
            // QUEDA:
            //
            // access-points
            // =================================================

            $slug_esperado =
                preg_replace(
                    '/' . $patron . '/',
                    '',
                    $slug_hijo
                );


            // =================================================
            // BUSCAR SI EXISTE LA CATEGORÍA CORRECTA
            // BAJO EL MISMO PADRE
            // =================================================

            $categoria_correcta = get_terms(
                array(
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => false,
                    'slug'       => $slug_esperado,
                    'parent'     => $categoria->parent,
                    'number'     => 1
                )
            );


            $existe_correcta = false;

            $id_correcta = 0;


            if (
                !is_wp_error($categoria_correcta) &&
                !empty($categoria_correcta)
            ) {

                $existe_correcta = true;

                $id_correcta =
                    $categoria_correcta[0]->term_id;

                $duplicados_probables++;

            }


            // =================================================
            // URL
            // =================================================

            $url_actual = get_term_link(
                $categoria,
                'product_cat'
            );


            // =================================================
            // MOSTRAR FILA
            // =================================================

            echo '<tr>';


            // ID
            echo '<td>' .
                intval($categoria->term_id) .
                '</td>';


            // Nombre
            echo '<td>';

            echo '<strong>' .
                esc_html($categoria->name) .
                '</strong>';

            echo '</td>';


            // Padre
            echo '<td>';

            echo esc_html(
                $padre->name
            );

            echo '<br>';

            echo '<small>';

            echo 'ID: ' .
                intval($padre->term_id);

            echo '</small>';

            echo '</td>';


            // Slug actual
            echo '<td>';

            echo '<code>' .
                esc_html($slug_hijo) .
                '</code>';

            echo '</td>';


            // Slug esperado
            echo '<td>';

            echo '<code style="color:green;font-weight:bold;">' .
                esc_html($slug_esperado) .
                '</code>';

            echo '</td>';


            // ¿Existe correcta?
            echo '<td>';


            if ($existe_correcta) {

                echo '⚠️ <strong style="color:red;">SÍ</strong>';

                echo '<br>';

                echo 'ID existente: ' .
                    intval($id_correcta);

            } else {

                echo 'ℹ️ NO';

            }


            echo '</td>';


            // URL
            echo '<td>';


            if (
                !is_wp_error($url_actual)
            ) {

                echo '<a href="' .
                    esc_url($url_actual) .
                    '" target="_blank">';

                echo esc_html(
                    $url_actual
                );

                echo '</a>';

            }


            echo '</td>';


            // Diagnóstico
            echo '<td>';


            if ($existe_correcta) {

                echo '<strong style="color:red;">';

                echo 'POSIBLE DUPLICADO';

                echo '</strong>';

                echo '<br>';

                echo '<small>';

                echo 'Existe otra categoría con el slug esperado bajo el mismo padre.';

                echo '</small>';

            } else {

                echo '<strong style="color:#d97706;">';

                echo 'SLUG CONTAMINADO PERO SIN DUPLICADO';

                echo '</strong>';

                echo '<br>';

                echo '<small>';

                echo 'La jerarquía está bien, pero el slug contiene el nombre del padre.';

                echo '</small>';

            }


            echo '</td>';


            echo '</tr>';

        }

    }


    echo '</tbody>';

    echo '</table>';


    // ========================================================
    // RESUMEN
    // ========================================================

    echo '<hr>';


    echo '<h2>📊 Resumen</h2>';


    echo '<table class="widefat" style="max-width:700px;">';


    echo '<tr>';

    echo '<td><strong>Total categorías</strong></td>';

    echo '<td>' .
        intval($total_categorias) .
        '</td>';

    echo '</tr>';


    echo '<tr>';

    echo '<td><strong>Categorías hijas</strong></td>';

    echo '<td>' .
        intval($con_padre) .
        '</td>';

    echo '</tr>';


    echo '<tr>';

    echo '<td><strong>Slugs con patrón hijo-padre-número</strong></td>';

    echo '<td style="color:#d97706;font-weight:bold;">' .
        intval($patrones_detectados) .
        '</td>';

    echo '</tr>';


    echo '<tr>';

    echo '<td><strong>Posibles duplicados reales</strong></td>';

    echo '<td style="color:red;font-weight:bold;">' .
        intval($duplicados_probables) .
        '</td>';

    echo '</tr>';


    echo '</table>';


    // ========================================================
    // EXPLICACIÓN
    // ========================================================

    echo '<hr>';

    echo '<h2>¿Qué significa cada resultado?</h2>';


    echo '<p>';

    echo '<strong>POSIBLE DUPLICADO:</strong> ';

    echo 'Existe una categoría con el mismo slug esperado bajo el mismo padre. ';

    echo 'Ejemplo: ';

    echo '<code>aires-acondicionados</code> ';

    echo 'y ';

    echo '<code>aires-acondicionados-linea-blanca-9</code>.';

    echo '</p>';


    echo '<p>';

    echo '<strong>SLUG CONTAMINADO PERO SIN DUPLICADO:</strong> ';

    echo 'La categoría está correctamente ubicada, pero su slug contiene el nombre del padre y un número. ';

    echo 'No se modifica automáticamente.';

    echo '</p>';


    echo '</div>';

}


//prueba inicia
/*
add_action('woocommerce_order_details_after_order_table', function($order) {

    if (!$order) {
        return;
    }

   echo '<div style="margin-top:30px;padding:15px;background:#f5f5f5;border:1px solid #ccc;">';
   echo '<h3>DEBUG CT Online</h3>';

    echo '<strong>_paqueteria_seleccionada:</strong><pre>';
   print_r($order->get_meta('_paqueteria_seleccionada'));
   echo '</pre>';

   echo '<strong>_ctonline_productos_finales:</strong><pre>';
   print_r($order->get_meta('_ctonline_productos_finales'));
    echo '</pre>';

    echo '</div>';

});
*/