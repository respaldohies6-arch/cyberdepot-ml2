<?php
/**
 * ============================================================
 * CYBERDEPOT
 * ANALIZADOR MERCADO LIBRE
 *
 * ETAPA 6.2
 *
 * BASE:
 *   ETAPA 6.1 EXACTA
 *
 * NUEVO:
 *
 * productos.json
 *      ↓
 * campos nativos CT
 *      +
 * especificaciones[]
 *      ↓
 * domain_discovery de Mercado Libre
 *      ↓
 * TODOS LOS CANDIDATOS DEVUELTOS POR ML
 *      ↓
 * category_id
 * category_name
 * domain_id
 * domain_name
 * score
 * attributes
 *      ↓
 * /categories/{category_id}
 *      ↓
 * información oficial de categoría
 *      ↓
 * permalink / URL cuando exista
 * path_from_root
 *      ↓
 * /categories/{category_id}/attributes
 *      ↓
 * atributos oficiales
 *      ↓
 * comparación CT ↔ ML
 *      ↓
 * diagnóstico de candidato
 *
 * IMPORTANTE:
 *
 * NO PUBLICA.
 * NO CREA OAuth.
 * REUTILIZA meli_access_token.
 *
 * NO usa:
 * /sites/MLM/categories
 *
 * Compatible con:
 * [xyz-ips snippet="analizador-ml"]
 * ============================================================
 */


/* ============================================================
 * CONEXIÓN EXISTENTE MERCADO LIBRE
 * ============================================================ */

if (!function_exists('cd_ml_v58_api_get')) {

    function cd_ml_v58_api_get($url, $method = 'GET', $body = null) {

        $token = get_option('meli_access_token', '');

        if (!$token) {

            return array(
                'ok'    => false,
                'error' => 'No existe meli_access_token. Conecta Mercado Libre desde el plugin principal.'
            );
        }

        $args = array(
            'method'  => $method,
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json'
            )
        );

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {

            return array(
                'ok'    => false,
                'error' => $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {

            return array(
                'ok'    => false,
                'code'  => $code,
                'error' => is_array($json)
                    ? wp_json_encode($json)
                    : $raw,
                'data'  => $json
            );
        }

        return array(
            'ok'   => true,
            'code' => $code,
            'data' => $json
        );
    }
}


/* ============================================================
 * NORMALIZACIÓN
 * ============================================================ */

if (!function_exists('cd_ml_v61_normalize')) {

    function cd_ml_v61_normalize($text) {

        $text = is_scalar($text)
            ? (string)$text
            : '';

        if ($text === '') {
            return '';
        }

        $text = remove_accents($text);
        $text = strtolower($text);

        $text = str_replace(
            array('_', '-', '/', '\\'),
            ' ',
            $text
        );

        $text = preg_replace(
            '/[^\p{L}\p{N}\s\.]+/u',
            ' ',
            $text
        );

        $text = preg_replace(
            '/\s+/u',
            ' ',
            $text
        );

        return trim($text);
    }
}


/* ============================================================
 * PRODUCTOS.JSON
 * ============================================================ */

if (!function_exists('cd_ml_v61_load_products')) {

    function cd_ml_v61_load_products() {

        $path = WP_CONTENT_DIR . '/uploads/productos.json';

        if (!file_exists($path)) {

            return array(
                'ok'    => false,
                'error' => 'No se encontró: ' . $path
            );
        }

        $raw = file_get_contents($path);

        if (!$raw) {

            return array(
                'ok'    => false,
                'error' => 'productos.json está vacío o no se pudo leer.'
            );
        }

        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {

            return array(
                'ok'    => false,
                'error' => 'Error JSON: ' . json_last_error_msg()
            );
        }

        if (
            isset($data['productos']) &&
            is_array($data['productos'])
        ) {
            $data = $data['productos'];
        }

        if (!is_array($data)) {

            return array(
                'ok'    => false,
                'error' => 'La estructura de productos.json no es un array.'
            );
        }

        return array(
            'ok'   => true,
            'data' => $data
        );
    }
}


/* ============================================================
 * MAPEO CT → MERCADO LIBRE
 *
 * SE CONSERVA EXACTAMENTE.
 * ============================================================ */

if (!function_exists('cd_ml_v61_mapping')) {

    function cd_ml_v61_mapping() {

        return array(

            'Bocinas Gaming' => array(
                'ruta' => 'Computación- Periféricos de PC- Bocinas para PC- Barra de Sonido',
                'category_id' => ''
            ),

            'Controles Gaming' => array(
                'ruta' => 'Computación- Accesorios para PC Gaming- Controles para Gamers- Gamepads',
                'category_id' => 'MLM21065'
            ),

            'Diademas Gaming' => array(
                'ruta' => 'Computación- Accesorios para PC Gaming- Audífonos- Audifonos Gamer',
                'category_id' => 'MLM6777'
            ),

            'Escritorio Gaming' => array(
                'ruta' => 'Hogar, Muebles y Jardín- Muebles para el Hogar- Escritorios- Escritorio Gamer',
                'category_id' => 'MLM437180'
            ),

            'Fuentes de Poder Gaming' => array(
                'ruta' => 'Computación- Componentes de PC- Fuentes de Alimentación- Fuentes',
                'category_id' => 'MLM1695'
            ),

            'Gabinetes Gaming' => array(
                'ruta' => 'Computación- Componentes de PC- Gabinetes- Gabinete Gamer',
                'category_id' => 'MLM437813'
            ),

            'Kits de Teclado y Mouse Gaming' => array(
                'ruta' => 'Computación- Periféricos de PC- Mouses y Teclados- Kits de Teclado y Mouse',
                'category_id' => 'MLM6263'
            ),

            'Motherboards Gaming' => array(
                'ruta' => 'Computación- Componentes de PC- Tarjetas- Tarjetas Madre',
                'category_id' => 'MLM36864'
            ),

            'Mouse Gaming' => array(
                'ruta' => 'Computación- Periféricos de PC- Mouses y Teclados- Mouse- Mouse Gamer',
                'category_id' => 'MLM1714'
            ),

            'Mouse Pads Gaming' => array(
                'ruta' => 'Computación- Periféricos de PC- Mouses y Teclados- Mouse Pads',
                'category_id' => 'MLM57931'
            ),

            'Sillas Gaming' => array(
                'ruta' => 'Computación- Accesorios para PC Gaming- Sillas Gamer- Silla Gaming',
                'category_id' => 'MLM447782'
            ),

            'Tarjetas de Video Gaming' => array(
                'ruta' => 'Computación- Componentes de PC- Tarjetas- Tarjetas de Video',
                'category_id' => 'MLM9761'
            ),

            'Teclados Gaming' => array(
                'ruta' => 'Computación- Periféricos de PC- Mouses y Teclados- Teclados- Teclados Físicos- Teclado Gamer',
                'category_id' => 'MLM418451'
            )
        );
    }
}


/* ============================================================
 * DOMAIN DISCOVERY
 *
 * IMPORTANTE:
 *
 * Conservamos:
 * - category_id
 * - category_name
 * - domain_id
 * - domain_name
 * - score
 * - attributes
 *
 * Y además conservamos TODA LA RESPUESTA ORIGINAL
 * de cada resultado en "raw".
 * ============================================================ */

if (!function_exists('cd_ml_v61_domain_discovery')) {

    function cd_ml_v61_domain_discovery($query) {

        $url =
            'https://api.mercadolibre.com/sites/MLM/domain_discovery/search?q=' .
            rawurlencode($query);

        $r = cd_ml_v58_api_get($url);

        if (
            !$r['ok'] ||
            !is_array($r['data'])
        ) {

            return array(
                'ok'      => false,
                'results' => array(),
                'error'   => $r['error'] ?? 'Sin respuesta'
            );
        }

        $results = array();

        foreach ($r['data'] as $item) {

            if (!is_array($item)) {
                continue;
            }

            $category_id =
                $item['category_id'] ?? '';

            if (!$category_id) {
                continue;
            }

            $detected_attributes = array();

            if (
                isset($item['attributes']) &&
                is_array($item['attributes'])
            ) {

                foreach ($item['attributes'] as $attr) {

                    if (!is_array($attr)) {
                        continue;
                    }

                    $detected_attributes[] = array(
                        'id' =>
                            $attr['id'] ?? '',

                        'name' =>
                            $attr['name'] ?? '',

                        'value_id' =>
                            $attr['value_id'] ?? '',

                        'value_name' =>
                            $attr['value_name'] ?? ''
                    );
                }
            }

            /*
             * Conservamos el resultado completo
             * devuelto por Mercado Libre.
             */

            $results[$category_id] = array(

                'category_id' =>
                    $category_id,

                'category_name' =>
                    $item['category_name'] ?? '',

                'domain_id' =>
                    $item['domain_id'] ?? '',

                'domain_name' =>
                    $item['domain_name'] ?? '',

                'score' =>
                    isset($item['score'])
                        ? (float)$item['score']
                        : 0,

                'attributes' =>
                    $detected_attributes,

                'raw' =>
                    $item
            );
        }

        return array(
            'ok'      => true,
            'results' => array_values($results),

            /*
             * Respuesta completa de discovery.
             */
            'raw' =>
                $r['data']
        );
    }
}


/* ============================================================
 * ATRIBUTOS DE CATEGORÍA
 * ============================================================ */

if (!function_exists('cd_ml_v61_category_attributes')) {

    function cd_ml_v61_category_attributes($category_id) {

        if (!$category_id) {

            return array(
                'ok'    => false,
                'error' => 'Category ID vacío.'
            );
        }

        $url =
            'https://api.mercadolibre.com/categories/' .
            rawurlencode($category_id) .
            '/attributes';

        $r = cd_ml_v58_api_get($url);

        if (
            !$r['ok'] ||
            !is_array($r['data'])
        ) {

            return array(
                'ok'    => false,
                'error' => $r['error'] ?? 'No se pudo obtener atributos.'
            );
        }

        return array(
            'ok'   => true,
            'data' => $r['data']
        );
    }
}


/* ============================================================
 * INFORMACIÓN DE CATEGORÍA
 * ============================================================ */

if (!function_exists('cd_ml_v61_category_info')) {

    function cd_ml_v61_category_info($category_id) {

        if (!$category_id) {

            return array(
                'ok'    => false,
                'error' => 'Category ID vacío.'
            );
        }

        $url =
            'https://api.mercadolibre.com/categories/' .
            rawurlencode($category_id);

        $r = cd_ml_v58_api_get($url);

        if (!$r['ok']) {

            return array(
                'ok'    => false,
                'error' => $r['error'] ?? '',
                'data'  => array()
            );
        }

        return array(
            'ok'   => true,
            'data' => $r['data']
        );
    }
}


/* ============================================================
 * EXTRAER URL DE MERCADO LIBRE
 *
 * NO INVENTAMOS URL.
 *
 * Solamente utilizamos una URL que venga
 * realmente dentro de la información de categoría.
 * ============================================================ */

if (!function_exists('cd_ml_v61_extract_category_url')) {

    function cd_ml_v61_extract_category_url($data) {

        if (!is_array($data)) {
            return '';
        }

        /*
         * Campos conocidos / posibles.
         *
         * permalink tiene prioridad.
         */

        $direct_keys = array(
            'permalink',
            'url',
            'site_url',
            'web_url'
        );

        foreach ($direct_keys as $key) {

            if (
                isset($data[$key]) &&
                is_string($data[$key]) &&
                trim($data[$key]) !== ''
            ) {

                $value = trim($data[$key]);

                if (
                    filter_var(
                        $value,
                        FILTER_VALIDATE_URL
                    )
                ) {

                    return $value;
                }
            }
        }

        /*
         * Búsqueda recursiva por si Mercado Libre
         * entrega la URL dentro de otro objeto.
         */

        foreach ($data as $value) {

            if (!is_array($value)) {
                continue;
            }

            $found =
                cd_ml_v61_extract_category_url(
                    $value
                );

            if ($found !== '') {
                return $found;
            }
        }

        return '';
    }
}


/* ============================================================
 * EXTRAER RUTA OFICIAL
 *
 * Usa path_from_root si Mercado Libre lo entrega.
 * ============================================================ */

if (!function_exists('cd_ml_v61_extract_category_path')) {

    function cd_ml_v61_extract_category_path($info) {

        if (
            !is_array($info) ||
            empty($info['path_from_root']) ||
            !is_array($info['path_from_root'])
        ) {

            return '';
        }

        $parts = array();

        foreach (
            $info['path_from_root']
            as $node
        ) {

            if (!is_array($node)) {
                continue;
            }

            $name =
                trim(
                    (string)(
                        $node['name']
                        ?? ''
                    )
                );

            if ($name !== '') {
                $parts[] = $name;
            }
        }

        if (!$parts) {
            return '';
        }

        return implode(
            ' → ',
            $parts
        );
    }
}


/* ============================================================
 * REQUIRED / CATALOG_REQUIRED
 * ============================================================ */

if (!function_exists('cd_ml_v61_attr_flags')) {

    function cd_ml_v61_attr_flags($attr) {

        $required = false;
        $catalog_required = false;

        if (
            isset($attr['tags']) &&
            is_array($attr['tags'])
        ) {

            if (
                !empty($attr['tags']['required'])
            ) {
                $required = true;
            }

            if (
                !empty($attr['tags']['catalog_required'])
            ) {
                $catalog_required = true;
            }
        }

        if (
            isset($attr['required']) &&
            $attr['required']
        ) {
            $required = true;
        }

        if (
            isset($attr['catalog_required']) &&
            $attr['catalog_required']
        ) {
            $catalog_required = true;
        }

        return array(
            'required' =>
                $required,

            'catalog_required' =>
                $catalog_required
        );
    }
}


/* ============================================================
 * MAPA DEL ESQUEMA ML
 * ============================================================ */

if (!function_exists('cd_ml_v61_attribute_schema_map')) {

    function cd_ml_v61_attribute_schema_map($attributes) {

        $map = array();

        if (!is_array($attributes)) {
            return $map;
        }

        foreach ($attributes as $attr) {

            if (!is_array($attr)) {
                continue;
            }

            $id = $attr['id'] ?? '';

            if (!$id) {
                continue;
            }

            $flags =
                cd_ml_v61_attr_flags($attr);

            $map[$id] = array(

                'id' => $id,

                'name' =>
                    $attr['name'] ?? '',

                'value_type' =>
                    $attr['value_type'] ?? '',

                'value_max_length' =>
                    $attr['value_max_length'] ?? '',

                'required' =>
                    $flags['required'],

                'catalog_required' =>
                    $flags['catalog_required'],

                'tags' =>
                    isset($attr['tags'])
                        ? $attr['tags']
                        : array(),

                'values' =>
                    isset($attr['values']) &&
                    is_array($attr['values'])
                        ? $attr['values']
                        : array()
            );
        }

        return $map;
    }
}


/* ============================================================
 * CONSTRUIR ATRIBUTOS CT
 * ============================================================ */

if (!function_exists('cd_ml_v61_build_ct_attributes')) {

    function cd_ml_v61_build_ct_attributes($product) {

        $attributes = array();

        /*
         * CAMPOS NATIVOS CT
         */

        if (
            isset($product['marca']) &&
            trim((string)$product['marca']) !== ''
        ) {

            $attributes['BRAND'] = array(
                'id' => 'BRAND',
                'source' => 'campo_ct',
                'ct_name' => 'Marca',
                'value' => trim((string)$product['marca'])
            );
        }

        if (
            isset($product['modelo']) &&
            trim((string)$product['modelo']) !== ''
        ) {

            $attributes['MODEL'] = array(
                'id' => 'MODEL',
                'source' => 'campo_ct',
                'ct_name' => 'Modelo',
                'value' => trim((string)$product['modelo'])
            );
        }

        if (
            isset($product['numParte']) &&
            trim((string)$product['numParte']) !== ''
        ) {

            $attributes['PART_NUMBER'] = array(
                'id' => 'PART_NUMBER',
                'source' => 'campo_ct',
                'ct_name' => 'Número de parte',
                'value' => trim((string)$product['numParte'])
            );
        }

        if (
            isset($product['ean']) &&
            trim((string)$product['ean']) !== ''
        ) {

            $attributes['EAN'] = array(
                'id' => 'EAN',
                'source' => 'campo_ct',
                'ct_name' => 'EAN',
                'value' => trim((string)$product['ean'])
            );

            $attributes['GTIN'] = array(
                'id' => 'GTIN',
                'source' => 'campo_ct',
                'ct_name' => 'GTIN',
                'value' => trim((string)$product['ean'])
            );
        }

        if (
            isset($product['upc']) &&
            trim((string)$product['upc']) !== ''
        ) {

            $attributes['UPC'] = array(
                'id' => 'UPC',
                'source' => 'campo_ct',
                'ct_name' => 'UPC',
                'value' => trim((string)$product['upc'])
            );
        }


        /*
         * ESPECIFICACIONES
         */

        if (
            isset($product['especificaciones']) &&
            is_array($product['especificaciones'])
        ) {

            foreach (
                $product['especificaciones']
                as $spec
            ) {

                if (!is_array($spec)) {
                    continue;
                }

                $tipo =
                    trim(
                        (string)($spec['tipo'] ?? '')
                    );

                $valor =
                    trim(
                        (string)($spec['valor'] ?? '')
                    );

                if (
                    $tipo === '' ||
                    $valor === ''
                ) {
                    continue;
                }

                $key =
                    cd_ml_v61_normalize($tipo);

                if ($key === '') {
                    continue;
                }

                if (
                    !isset(
                        $attributes['CT_' . $key]
                    )
                ) {

                    $attributes['CT_' . $key] = array(
                        'id' => '',
                        'source' => 'especificacion',
                        'ct_name' => $tipo,
                        'value' => $valor
                    );

                } else {

                    $old =
                        $attributes['CT_' . $key]['value'];

                    $attributes['CT_' . $key]['value'] =
                        $old . ' | ' . $valor;
                }
            }
        }

        return $attributes;
    }
}


/* ============================================================
 * ALIAS DE ATRIBUTOS ML
 * ============================================================ */

if (!function_exists('cd_ml_v61_attribute_aliases')) {

    function cd_ml_v61_attribute_aliases() {

        return array(

            'BRAND' => array(
                'marca',
                'brand',
                'fabricante',
                'manufacturer'
            ),

            'MODEL' => array(
                'modelo',
                'model'
            ),

            'PART_NUMBER' => array(
                'numero de parte',
                'numero de pieza',
                'part number',
                'part number del fabricante',
                'manufacturer part number',
                'mpn'
            ),

            'GTIN' => array(
                'gtin',
                'codigo universal de producto',
                'codigo universal',
                'ean'
            ),

            'EAN' => array(
                'ean',
                'gtin'
            ),

            'UPC' => array(
                'upc',
                'codigo upc'
            ),

            'ANTENNA_GAIN' => array(
                'ganancia de la antena',
                'ganancia de antena',
                'ganancia antena',
                'maximum antenna gain',
                'max antenna gain',
                'antenna gain',
                'antenna gain max'
            ),

            'ANTENNA_TYPE' => array(
                'tipo de antena',
                'antenna type'
            ),

            'CHANNELS' => array(
                'cantidad de canales',
                'numero de canales',
                'numero canales',
                'channels',
                'channel count'
            ),

            'TRANSFER_RATE' => array(
                'tasa de transferencia',
                'velocidad de transferencia',
                'transferencia de datos',
                'transfer rate',
                'data transfer rate',
                'transfer speed',
                'velocidad de transmision'
            ),

            'COLOR' => array(
                'color',
                'color del producto',
                'product color'
            ),

            'CONNECTIVITY' => array(
                'tecnologia de conectividad',
                'tecnologia de conexion',
                'connectivity technology',
                'connectivity'
            ),

            'WIDTH' => array(
                'ancho',
                'width',
                'ancho del producto',
                'product width'
            ),

            'HEIGHT' => array(
                'altura',
                'height',
                'altura del producto',
                'product height'
            ),

            'DEPTH' => array(
                'profundidad',
                'depth',
                'profundidad del producto',
                'product depth'
            ),

            'WEIGHT' => array(
                'peso',
                'weight',
                'peso del producto',
                'product weight'
            ),

            'MEMORY_SIZE' => array(
                'memoria interna',
                'tamaño de memoria',
                'tamano de memoria',
                'memory size',
                'internal memory',
                'memoria'
            ),

            'STORAGE_CAPACITY' => array(
                'capacidad total de almacenaje',
                'capacidad de almacenamiento',
                'capacidad de almacenaje',
                'storage capacity',
                'total storage capacity'
            ),

            'SCREEN_SIZE' => array(
                'diagonal de la pantalla',
                'tamaño de pantalla',
                'tamano de pantalla',
                'screen size',
                'display size',
                'diagonal'
            ),

            'PROCESSOR_FAMILY' => array(
                'familia de procesador',
                'familia del procesador',
                'processor family',
                'processor family name'
            ),

            'OPERATING_SYSTEM' => array(
                'sistema operativo instalado',
                'sistema operativo',
                'operating system',
                'installed operating system'
            ),

            'POWER_OUTPUT' => array(
                'potencia de salida',
                'potencia salida',
                'output power',
                'power output'
            ),

            'MODULATION_TYPE' => array(
                'tipo de modulación',
                'tipo de modulacion',
                'modulation type'
            ),

            'FORM_FACTOR' => array(
                'factor de forma',
                'form factor'
            ),

            'REQUIRES_ASSEMBLY' => array(
                'requiere ensamblado',
                'requiere montaje',
                'requiere armado',
                'requires assembly',
                'assembly required'
            ),

            'INCLUDES_ASSEMBLY_MANUAL' => array(
                'incluye manual de ensamblado',
                'incluye manual de montaje',
                'incluye manual de armado',
                'includes assembly manual',
                'assembly manual included'
            ),

            'BACKREST_HEIGHT' => array(
                'altura del respaldo',
                'backrest height'
            ),

            'SEAT_DEPTH' => array(
                'profundidad del asiento',
                'seat depth'
            ),

            'OFFICE_CHAIR_WIDTH' => array(
                'ancho de la silla',
                'ancho de silla',
                'office chair width',
                'chair width'
            ),

            'MAX_CHAIR_HEIGHT' => array(
                'altura maxima de la silla',
                'altura máxima de la silla',
                'altura maxima silla',
                'maximum chair height',
                'max chair height'
            ),

            'DESK_MATERIALS' => array(
                'materiales del escritorio',
                'material del escritorio',
                'materiales escritorio',
                'desk materials',
                'desk material'
            ),

            'IS_GAMER' => array(
                'es gamer',
                'gamer',
                'is gamer'
            ),

            'IS_ERGONOMIC' => array(
                'es ergonomica',
                'es ergonómica',
                'ergonomica',
                'ergonómica',
                'is ergonomic'
            ),

            'IS_SWIVEL' => array(
                'es giratoria',
                'es giratorio',
                'giratoria',
                'swivel',
                'is swivel'
            ),

            'MOTHERBOARDS_COMPATIBILITY' => array(
                'tarjetas madre compatibles',
                'tarjetas madres compatibles',
                'motherboards compatibles',
                'motherboard compatibility',
                'motherboards compatibility',
                'compatible motherboards'
            )
        );
    }
}


/* ============================================================
 * OBTENER ALIAS
 * ============================================================ */

if (!function_exists('cd_ml_v61_get_attribute_aliases')) {

    function cd_ml_v61_get_attribute_aliases($id, $name) {

        $aliases =
            cd_ml_v61_attribute_aliases();

        $out = array();

        if (isset($aliases[$id])) {

            foreach ($aliases[$id] as $alias) {

                $out[] =
                    cd_ml_v61_normalize($alias);
            }
        }

        if ($name !== '') {

            $out[] =
                cd_ml_v61_normalize($name);
        }

        if ($id !== '') {

            $out[] =
                cd_ml_v61_normalize($id);
        }

        return array_values(
            array_unique(
                array_filter($out)
            )
        );
    }
}


/* ============================================================
 * MATCH CT → ML
 * ============================================================ */

if (!function_exists('cd_ml_v61_match_ct_attribute')) {

    function cd_ml_v61_match_ct_attribute(
        $ml_id,
        $ml_name,
        $ct_attributes
    ) {

        /*
         * CASOS DIRECTOS
         */

        if (
            $ml_id === 'BRAND' &&
            isset($ct_attributes['BRAND']) &&
            trim($ct_attributes['BRAND']['value']) !== ''
        ) {

            return $ct_attributes['BRAND'];
        }

        if (
            $ml_id === 'MODEL' &&
            isset($ct_attributes['MODEL']) &&
            trim($ct_attributes['MODEL']['value']) !== ''
        ) {

            return $ct_attributes['MODEL'];
        }

        if (
            $ml_id === 'PART_NUMBER' &&
            isset($ct_attributes['PART_NUMBER']) &&
            trim($ct_attributes['PART_NUMBER']['value']) !== ''
        ) {

            return $ct_attributes['PART_NUMBER'];
        }

        if (
            $ml_id === 'GTIN' &&
            isset($ct_attributes['GTIN']) &&
            trim($ct_attributes['GTIN']['value']) !== ''
        ) {

            return $ct_attributes['GTIN'];
        }

        if (
            $ml_id === 'EAN' &&
            isset($ct_attributes['EAN']) &&
            trim($ct_attributes['EAN']['value']) !== ''
        ) {

            return $ct_attributes['EAN'];
        }

        if (
            $ml_id === 'UPC' &&
            isset($ct_attributes['UPC']) &&
            trim($ct_attributes['UPC']['value']) !== ''
        ) {

            return $ct_attributes['UPC'];
        }


        /*
         * ALIASES
         */

        $ml_aliases =
            cd_ml_v61_get_attribute_aliases(
                $ml_id,
                $ml_name
            );

        foreach (
            $ct_attributes
            as $ct
        ) {

            if (!is_array($ct)) {
                continue;
            }

            $ct_name =
                cd_ml_v61_normalize(
                    $ct['ct_name'] ?? ''
                );

            if ($ct_name === '') {
                continue;
            }

            if (
                in_array(
                    $ct_name,
                    $ml_aliases,
                    true
                )
            ) {

                return $ct;
            }
        }


        /*
         * COINCIDENCIA SEMÁNTICA
         */

        $normalized_id =
            cd_ml_v61_normalize($ml_id);

        $normalized_name =
            cd_ml_v61_normalize($ml_name);

        foreach (
            $ct_attributes
            as $ct
        ) {

            if (!is_array($ct)) {
                continue;
            }

            $ct_name =
                cd_ml_v61_normalize(
                    $ct['ct_name'] ?? ''
                );

            if (!$ct_name) {
                continue;
            }

            $id_words =
                preg_split(
                    '/\s+/u',
                    $normalized_id
                );

            $name_words =
                preg_split(
                    '/\s+/u',
                    $normalized_name
                );

            $ct_words =
                preg_split(
                    '/\s+/u',
                    $ct_name
                );

            $score = 0;

            foreach ($id_words as $word) {

                if (
                    strlen($word) >= 4 &&
                    in_array(
                        $word,
                        $ct_words,
                        true
                    )
                ) {
                    $score++;
                }
            }

            foreach ($name_words as $word) {

                if (
                    strlen($word) >= 5 &&
                    in_array(
                        $word,
                        $ct_words,
                        true
                    )
                ) {
                    $score++;
                }
            }

            if ($score >= 2) {

                return $ct;
            }
        }

        return null;
    }
}


/* ============================================================
 * COMPARAR CT CONTRA ESQUEMA ML
 * ============================================================ */

if (!function_exists('cd_ml_v61_compare_attributes')) {

    function cd_ml_v61_compare_attributes(
        $ct_attributes,
        $domain_detected,
        $schema
    ) {

        $output = array();

        foreach (
            $schema
            as $id => $schema_attr
        ) {

            $name =
                $schema_attr['name'] ?? '';

            $required =
                !empty(
                    $schema_attr['required']
                );

            $catalog_required =
                !empty(
                    $schema_attr['catalog_required']
                );


            $ct_match =
                cd_ml_v61_match_ct_attribute(
                    $id,
                    $name,
                    $ct_attributes
                );


            $domain_match = null;

            foreach (
                $domain_detected
                as $detected
            ) {

                if (!is_array($detected)) {
                    continue;
                }

                if (
                    strtoupper(
                        (string)($detected['id'] ?? '')
                    ) ===
                    strtoupper($id)
                ) {

                    $domain_match =
                        $detected;

                    break;
                }
            }


            $ct_present =
                (
                    is_array($ct_match) &&
                    trim(
                        (string)(
                            $ct_match['value']
                            ?? ''
                        )
                    ) !== ''
                );


            $domain_present =
                (
                    is_array($domain_match) &&
                    (
                        trim(
                            (string)(
                                $domain_match['value_name']
                                ?? ''
                            )
                        ) !== ''
                    )
                );


            $status = 'FALTA';

            if ($ct_present) {

                $status =
                    'PRESENTE EN CT';

            } elseif ($domain_present) {

                $status =
                    'ML DETECTÓ / CT FALTA';
            }


            $output[$id] = array(

                'id' =>
                    $id,

                'name' =>
                    $name,

                'value_id' =>
                    $domain_match['value_id'] ?? '',

                'value_name' =>
                    $domain_match['value_name'] ?? '',

                'ct_name' =>
                    $ct_match['ct_name'] ?? '',

                'ct_value' =>
                    $ct_match['value'] ?? '',

                'source' =>
                    $ct_match['source'] ?? '',

                'required' =>
                    $required,

                'catalog_required' =>
                    $catalog_required,

                'ct_present' =>
                    $ct_present,

                'domain_detected' =>
                    $domain_present,

                'status' =>
                    $status,

                'exists_in_schema' =>
                    true
            );
        }


        /*
         * Se conserva la lógica original.
         */

        foreach (
            $ct_attributes
            as $ct_id => $ct
        ) {

            if (!is_array($ct)) {
                continue;
            }

            $matched = false;

            foreach (
                $output
                as $ml_attr
            ) {

                if (
                    !empty($ml_attr['ct_present']) &&
                    isset($ct['ct_name']) &&
                    isset($ml_attr['ct_name']) &&
                    cd_ml_v61_normalize(
                        $ct['ct_name']
                    ) ===
                    cd_ml_v61_normalize(
                        $ml_attr['ct_name']
                    )
                ) {

                    $matched = true;
                    break;
                }
            }

            if (
                !$matched &&
                !in_array(
                    $ct_id,
                    array(
                        'BRAND',
                        'MODEL',
                        'PART_NUMBER',
                        'EAN',
                        'GTIN',
                        'UPC'
                    ),
                    true
                )
            ) {

                continue;
            }
        }


        return array_values($output);
    }
}


/* ============================================================
 * CALCULAR EVIDENCIA DEL CANDIDATO
 *
 * NO DECIDE AUTOMÁTICAMENTE LA CATEGORÍA.
 *
 * Sirve para ordenar/diagnosticar.
 * ============================================================ */

if (!function_exists('cd_ml_v61_candidate_evidence')) {

    function cd_ml_v61_candidate_evidence(
        $candidate,
        $comparison,
        $mapped_id
    ) {

        $domain_score =
            floatval(
                $candidate['score'] ?? 0
            );

        $domain_detected_count =
            count(
                $candidate['attributes']
                ?? array()
            );

        $schema_count =
            count($comparison);

        $ct_present_count = 0;
        $required_total = 0;
        $required_present = 0;
        $catalog_total = 0;
        $catalog_present = 0;

        foreach (
            $comparison
            as $attr
        ) {

            if (!empty($attr['ct_present'])) {
                $ct_present_count++;
            }

            if (!empty($attr['required'])) {

                $required_total++;

                if (!empty($attr['ct_present'])) {
                    $required_present++;
                }
            }

            if (!empty($attr['catalog_required'])) {

                $catalog_total++;

                if (!empty($attr['ct_present'])) {
                    $catalog_present++;
                }
            }
        }


        $required_ratio =
            $required_total > 0
                ? (
                    $required_present /
                    $required_total
                )
                : 1;


        $catalog_ratio =
            $catalog_total > 0
                ? (
                    $catalog_present /
                    $catalog_total
                )
                : 1;


        /*
         * Score de evidencia.
         *
         * NO reemplaza el score de ML.
         */

        $evidence_score = 0;

        $evidence_score +=
            min(
                40,
                max(
                    0,
                    $domain_score * 40
                )
            );

        $evidence_score +=
            min(
                20,
                $domain_detected_count * 2
            );

        $evidence_score +=
            min(
                20,
                $ct_present_count
            );

        $evidence_score +=
            $required_ratio * 10;

        $evidence_score +=
            $catalog_ratio * 10;


        /*
         * Si coincide con el mapping fijo,
         * se marca fuertemente como referencia.
         */

        $is_mapped =
            (
                $mapped_id !== '' &&
                $mapped_id ===
                ($candidate['category_id'] ?? '')
            );


        if ($is_mapped) {
            $evidence_score += 15;
        }


        $evidence_score =
            min(
                100,
                round($evidence_score, 2)
            );


        /*
         * Estado.
         */

        if ($is_mapped) {

            $status =
                !empty($candidate['forced_mapping'])
                    ? 'MAPEO FIJO FORZADO'
                    : 'MAPEO FIJO ENCONTRADO';

        } else {

            $status =
                'CANDIDATO DOMAIN DISCOVERY';
        }


        return array(

            'status' =>
                $status,

            'is_mapped' =>
                $is_mapped,

            'domain_score' =>
                $domain_score,

            'evidence_score' =>
                $evidence_score,

            'domain_detected_count' =>
                $domain_detected_count,

            'schema_count' =>
                $schema_count,

            'ct_present_count' =>
                $ct_present_count,

            'required_total' =>
                $required_total,

            'required_present' =>
                $required_present,

            'catalog_total' =>
                $catalog_total,

            'catalog_present' =>
                $catalog_present
        );
    }
}


/* ============================================================
 * PROCESAR UN PRODUCTO
 * ============================================================ */

if (!function_exists('cd_ml_v61_process_product')) {

    function cd_ml_v61_process_product(
        $product,
        $mapping
    ) {

        $name =
            trim(
                $product['nombre'] ?? ''
            );

        if ($name === '') {

            return array(
                'ok'    => false,
                'error' => 'Producto sin nombre.'
            );
        }


        /*
         * DATOS CT
         */

        $ct_attributes =
            cd_ml_v61_build_ct_attributes(
                $product
            );


        /*
         * DOMAIN DISCOVERY
         *
         * SOLO NOMBRE DEL PRODUCTO.
         */

        $discovery =
            cd_ml_v61_domain_discovery(
                $name
            );

        if (!$discovery['ok']) {

            return array(
                'ok'      => false,
                'error'   =>
                    $discovery['error']
                    ?? 'Sin resultados',
                'product' => $product
            );
        }


        $categories =
            $discovery['results'];


        /*
         * IMPORTANTE:
         *
         * AHORA NO DESCARTAMOS LOS CANDIDATOS
         * DE DOMAIN DISCOVERY CUANDO EXISTE
         * UN MAPPING FIJO.
         *
         * Conservamos todos para diagnóstico.
         */

        $mapped_id =
            $mapping['category_id'] ?? '';

        $candidates =
            $categories;


        /*
         * Si hay mapping fijo y NO apareció
         * en discovery, lo agregamos como candidato
         * forzado para validarlo directamente.
         */

        if ($mapped_id !== '') {

            $mapped_found = false;

            foreach (
                $candidates
                as $candidate
            ) {

                if (
                    ($candidate['category_id'] ?? '')
                    ===
                    $mapped_id
                ) {

                    $mapped_found = true;
                    break;
                }
            }

            if (!$mapped_found) {

                $candidates[] = array(

                    'category_id' =>
                        $mapped_id,

                    'category_name' =>
                        '',

                    'domain_id' =>
                        '',

                    'domain_name' =>
                        '',

                    'score' =>
                        0,

                    'attributes' =>
                        array(),

                    'raw' =>
                        array(),

                    'forced_mapping' =>
                        true
                );
            }
        }


        $validated = array();


        foreach (
            $candidates
            as $candidate
        ) {

            $category_id =
                $candidate['category_id']
                ?? '';

            if (!$category_id) {
                continue;
            }


            /*
             * ESQUEMA OFICIAL ML
             */

            $schema_response =
                cd_ml_v61_category_attributes(
                    $category_id
                );

            $schema = array();

            if ($schema_response['ok']) {

                $schema =
                    cd_ml_v61_attribute_schema_map(
                        $schema_response['data']
                    );
            }


            /*
             * COMPARACIÓN
             */

            $comparison =
                cd_ml_v61_compare_attributes(
                    $ct_attributes,
                    $candidate['attributes']
                        ?? array(),
                    $schema
                );


            /*
             * INFORMACIÓN OFICIAL
             */

            $category_info =
                cd_ml_v61_category_info(
                    $category_id
                );

            $category_info_data =
                $category_info['ok']
                    ? $category_info['data']
                    : array();


            /*
             * URL REAL, SI EXISTE.
             */

            $category_url =
                cd_ml_v61_extract_category_url(
                    $category_info_data
                );


            /*
             * RUTA OFICIAL.
             */

            $category_path =
                cd_ml_v61_extract_category_path(
                    $category_info_data
                );


            /*
             * CONTADORES
             */

            $required_count = 0;
            $catalog_required_count = 0;

            $required_missing = 0;
            $catalog_missing = 0;

            foreach (
                $comparison
                as $attr
            ) {

                if (
                    !empty(
                        $attr['required']
                    )
                ) {

                    $required_count++;

                    if (
                        empty(
                            $attr['ct_present']
                        )
                    ) {

                        $required_missing++;
                    }
                }

                if (
                    !empty(
                        $attr['catalog_required']
                    )
                ) {

                    $catalog_required_count++;

                    if (
                        empty(
                            $attr['ct_present']
                        )
                    ) {

                        $catalog_missing++;
                    }
                }
            }


            /*
             * EVIDENCIA
             */

            $evidence =
                cd_ml_v61_candidate_evidence(
                    $candidate,
                    $comparison,
                    $mapped_id
                );


            /*
             * Resultado completo.
             */

            $validated[] = array(

                'category_id' =>
                    $category_id,

                'category_name' =>
                    $candidate['category_name']
                    ?? '',

                'domain_id' =>
                    $candidate['domain_id']
                    ?? '',

                'domain_name' =>
                    $candidate['domain_name']
                    ?? '',

                'score' =>
                    $candidate['score']
                    ?? 0,

                'forced_mapping' =>
                    !empty(
                        $candidate['forced_mapping']
                    ),

                'attributes_detected' =>
                    $candidate['attributes']
                    ?? array(),

                /*
                 * RESPUESTA COMPLETA DEL CANDIDATO.
                 */

                'domain_raw' =>
                    $candidate['raw']
                    ?? array(),

                /*
                 * DATOS OFICIALES.
                 */

                'category_url' =>
                    $category_url,

                'category_path' =>
                    $category_path,

                'category_info' =>
                    $category_info_data,

                /*
                 * ESQUEMA.
                 */

                'attributes_schema' =>
                    $schema,

                /*
                 * COMPARACIÓN.
                 */

                'comparison' =>
                    $comparison,

                'ct_attributes' =>
                    $ct_attributes,

                /*
                 * CONTADORES.
                 */

                'required_count' =>
                    $required_count,

                'catalog_required_count' =>
                    $catalog_required_count,

                'required_missing' =>
                    $required_missing,

                'catalog_missing' =>
                    $catalog_missing,

                /*
                 * DIAGNÓSTICO.
                 */

                'evidence' =>
                    $evidence,

                'schema_ok' =>
                    $schema_response['ok']
            );
        }


        /*
         * Ordenamos candidatos por evidencia.
         *
         * El mapping fijo sigue apareciendo,
         * pero no se elimina ningún candidato.
         */

        usort(
            $validated,
            function($a, $b) {

                $ea =
                    floatval(
                        $a['evidence']['evidence_score']
                        ?? 0
                    );

                $eb =
                    floatval(
                        $b['evidence']['evidence_score']
                        ?? 0
                    );

                if ($ea === $eb) {

                    return
                        floatval(
                            $b['score'] ?? 0
                        )
                        <=>
                        floatval(
                            $a['score'] ?? 0
                        );
                }

                return $eb <=> $ea;
            }
        );


        return array(

            'ok' => true,

            'product' => array(

                'idProducto' =>
                    $product['idProducto'] ?? '',

                'clave' =>
                    $product['clave'] ?? '',

                'nombre' =>
                    $product['nombre'] ?? '',

                'marca' =>
                    $product['marca'] ?? '',

                'modelo' =>
                    $product['modelo'] ?? '',

                'numParte' =>
                    $product['numParte'] ?? '',

                'ean' =>
                    $product['ean'] ?? '',

                'upc' =>
                    $product['upc'] ?? '',

                'categoria' =>
                    $product['categoria'] ?? '',

                'subcategoria' =>
                    $product['subcategoria'] ?? ''
            ),

            'mapping' =>
                $mapping,

            /*
             * Información original de Discovery.
             */

            'domain_discovery_query' =>
                $name,

            'domain_discovery_raw' =>
                $discovery['raw']
                ?? array(),

            /*
             * Todos los candidatos.
             */

            'categories' =>
                $validated
        );
    }
}


/* ============================================================
 * ESTADO
 * ============================================================ */

$action =
    sanitize_text_field(
        $_GET['cd_ml_accion'] ?? ''
    );

$state_key =
    'cd_ml_v61_state_' .
    get_current_user_id();

$state =
    get_transient($state_key);


/* ============================================================
 * CARGAR PRODUCTOS
 * ============================================================ */

$products_result =
    cd_ml_v61_load_products();

if (!$products_result['ok']) {

    echo '<div style="
        background:#fee2e2;
        border:1px solid #ef4444;
        padding:20px;
        color:#991b1b;
        border-radius:10px;
        margin:20px 0;
    ">';

    echo '<strong>ERROR:</strong> ' .
        esc_html(
            $products_result['error']
        );

    echo '</div>';

    return;
}


$products =
    $products_result['data'];

$mapping =
    cd_ml_v61_mapping();


/* ============================================================
 * REINICIAR
 * ============================================================ */

if ($action === 'reiniciar') {

    delete_transient($state_key);

    $url =
        remove_query_arg(
            'cd_ml_accion'
        );

    echo '<script>
        window.location.href = "' .
        esc_js($url) .
        '";
    </script>';

    return;
}


/* ============================================================
 * FILTRAR LAS 13 CATEGORÍAS
 * ============================================================ */

$analysis_products = array();

foreach (
    $products
    as $product
) {

    if (!is_array($product)) {
        continue;
    }

    $sub =
        trim(
            $product['subcategoria']
            ?? ''
        );

    if (
        isset(
            $mapping[$sub]
        )
    ) {

        $analysis_products[] =
            $product;
    }
}


/* ============================================================
 * INICIAR
 * ============================================================ */

if (
    $action === 'iniciar' ||
    !$state
) {

    $state = array(

        'started' =>
            current_time('mysql'),

        'total' =>
            count($analysis_products),

        'processed' =>
            0,

        'ok' =>
            0,

        'errors' =>
            0,

        'results' =>
            array()
    );

    set_transient(
        $state_key,
        $state,
        12 * HOUR_IN_SECONDS
    );


    $url =
        add_query_arg(
            'cd_ml_accion',
            'procesar'
        );

    echo '<script>
        setTimeout(function(){
            window.location.href = "' .
            esc_js($url) .
            '";
        }, 300);
    </script>';
}


/* ============================================================
 * PROCESAR BATCH
 *
 * 3 PRODUCTOS POR PETICIÓN
 * ============================================================ */

if ($action === 'procesar') {

    if (!$state) {

        $state = array(

            'started' =>
                current_time('mysql'),

            'total' =>
                count($analysis_products),

            'processed' =>
                0,

            'ok' =>
                0,

            'errors' =>
                0,

            'results' =>
                array()
        );
    }


    $batch_size = 3;

    $start =
        intval(
            $state['processed']
        );

    $end =
        min(
            $start + $batch_size,
            count($analysis_products)
        );


    for (
        $i = $start;
        $i < $end;
        $i++
    ) {

        $product =
            $analysis_products[$i];

        $ct_subcategory =
            trim(
                $product['subcategoria']
                ?? ''
            );

        $result =
            cd_ml_v61_process_product(
                $product,
                $mapping[$ct_subcategory]
            );


        if ($result['ok']) {

            $state['ok']++;

            $state['results'][] =
                $result;

        } else {

            $state['errors']++;

            $state['results'][] =
                array(

                    'ok' =>
                        false,

                    'product' =>
                        array(

                            'idProducto' =>
                                $product['idProducto']
                                ?? '',

                            'clave' =>
                                $product['clave']
                                ?? '',

                            'nombre' =>
                                $product['nombre']
                                ?? '',

                            'marca' =>
                                $product['marca']
                                ?? '',

                            'modelo' =>
                                $product['modelo']
                                ?? '',

                            'subcategoria' =>
                                $ct_subcategory
                        ),

                    'error' =>
                        $result['error']
                        ?? 'Error desconocido'
                );
        }


        $state['processed']++;
    }


    set_transient(
        $state_key,
        $state,
        12 * HOUR_IN_SECONDS
    );


    if (
        $state['processed'] <
        $state['total']
    ) {

        $url =
            add_query_arg(
                'cd_ml_accion',
                'procesar'
            );

        echo '<script>
            setTimeout(function(){
                window.location.href = "' .
                esc_js($url) .
                '";
            }, 1200);
        </script>';

    } else {

        $state['finished'] =
            current_time('mysql');

        set_transient(
            $state_key,
            $state,
            12 * HOUR_IN_SECONDS
        );
    }
}


/* ============================================================
 * DATOS DASHBOARD
 * ============================================================ */

$total =
    intval(
        $state['total'] ?? 0
    );

$processed =
    intval(
        $state['processed'] ?? 0
    );

$ok =
    intval(
        $state['ok'] ?? 0
    );

$errors =
    intval(
        $state['errors'] ?? 0
    );

$percent =
    $total > 0
        ? round(
            ($processed / $total) * 100
        )
        : 0;


/* ============================================================
 * ESTILOS
 * ============================================================ */

echo '<style>

.cdml61-wrap{
    font-family:Arial,sans-serif;
    margin:20px 0;
}

.cdml61-dashboard{
    display:grid;
    grid-template-columns:
        repeat(auto-fit,minmax(170px,1fr));
    gap:12px;
    margin-bottom:20px;
}

.cdml61-card{
    border:1px solid #d1d5db;
    border-radius:12px;
    padding:18px;
    background:#fff;
}

.cdml61-number{
    font-size:30px;
    font-weight:700;
    margin-top:5px;
}

.cdml61-label{
    color:#6b7280;
    font-size:13px;
}

.cdml61-progress{
    background:#e5e7eb;
    border-radius:20px;
    height:22px;
    overflow:hidden;
    margin:15px 0;
}

.cdml61-progress-bar{
    height:100%;
    background:#2563eb;
    color:#fff;
    text-align:center;
    line-height:22px;
    font-size:12px;
    font-weight:bold;
}

.cdml61-ok{
    color:#166534;
    font-weight:bold;
}

.cdml61-error{
    color:#991b1b;
    font-weight:bold;
}

.cdml61-match{
    background:#dcfce7;
    color:#166534;
    padding:4px 8px;
    border-radius:6px;
    font-weight:bold;
    display:inline-block;
}

.cdml61-no{
    background:#fee2e2;
    color:#991b1b;
    padding:4px 8px;
    border-radius:6px;
    font-weight:bold;
    display:inline-block;
}

.cdml61-domain{
    background:#dbeafe;
    color:#1e40af;
    padding:4px 8px;
    border-radius:6px;
    font-weight:bold;
    display:inline-block;
}

.cdml61-warning{
    background:#fef3c7;
    color:#92400e;
    padding:4px 8px;
    border-radius:6px;
    font-weight:bold;
    display:inline-block;
}

.cdml61-table{
    width:100%;
    border-collapse:collapse;
    margin:10px 0 25px;
    background:#fff;
}

.cdml61-table th{
    background:#111827;
    color:#fff;
    padding:9px;
    text-align:left;
}

.cdml61-table td{
    padding:8px;
    border-bottom:1px solid #e5e7eb;
    vertical-align:top;
}

.cdml61-product{
    border:1px solid #d1d5db;
    border-radius:12px;
    margin:20px 0;
    padding:18px;
    background:#f9fafb;
}

.cdml61-category{
    border:1px solid #cbd5e1;
    border-radius:10px;
    margin:15px 0;
    padding:15px;
    background:#fff;
}

.cdml61-btn{
    display:inline-block;
    padding:10px 16px;
    background:#111827;
    color:#fff !important;
    text-decoration:none;
    border-radius:8px;
    margin-bottom:15px;
}

.cdml61-present{
    background:#dcfce7;
    color:#166534;
    font-weight:bold;
}

.cdml61-missing{
    background:#fee2e2;
    color:#991b1b;
    font-weight:bold;
}

.cdml61-inferred{
    background:#fef3c7;
    color:#92400e;
    font-weight:bold;
}

.cdml61-url{
    display:inline-block;
    background:#ede9fe;
    color:#5b21b6;
    padding:6px 10px;
    border-radius:7px;
    font-weight:bold;
    text-decoration:none;
    word-break:break-all;
}

.cdml61-score{
    font-size:18px;
    font-weight:bold;
}

.cdml61-evidence{
    background:#ecfeff;
    border:1px solid #67e8f9;
    color:#155e75;
    padding:10px;
    border-radius:8px;
}

.cdml61-fixed{
    background:#f3e8ff;
    border:1px solid #c084fc;
    color:#6b21a8;
    padding:10px;
    border-radius:8px;
    font-weight:bold;
}

.cdml61-path{
    background:#f8fafc;
    border:1px solid #cbd5e1;
    padding:10px;
    border-radius:8px;
    margin-top:8px;
}

.cdml61-small{
    color:#64748b;
    font-size:12px;
}

.cdml61-json{
    background:#111827;
    color:#f9fafb;
    padding:15px;
    overflow:auto;
    border-radius:8px;
    max-height:500px;
    font-size:12px;
}

</style>';


/* ============================================================
 * CONTENEDOR
 * ============================================================ */

echo '<div class="cdml61-wrap">';


echo '<h2>
🔎 Analizador Mercado Libre — Etapa 6.2
</h2>';

echo '<p>
<strong>
CT → Domain Discovery → TODOS LOS CANDIDATOS ML → Categoría oficial → atributos oficiales → diagnóstico
</strong>
</p>';

echo '<p class="cdml61-small">
Esta etapa solamente analiza y diagnostica. No publica productos.
</p>';


/* ============================================================
 * DASHBOARD
 * ============================================================ */

echo '<div class="cdml61-dashboard">';


echo '<div class="cdml61-card">
<div class="cdml61-label">PRODUCTOS A ANALIZAR</div>
<div class="cdml61-number">' .
    $total .
'</div>
</div>';


echo '<div class="cdml61-card">
<div class="cdml61-label">PROCESADOS</div>
<div class="cdml61-number">' .
    $processed .
'</div>
</div>';


echo '<div class="cdml61-card">
<div class="cdml61-label">ANALIZADOS OK</div>
<div class="cdml61-number cdml61-ok">' .
    $ok .
'</div>
</div>';


echo '<div class="cdml61-card">
<div class="cdml61-label">ERRORES</div>
<div class="cdml61-number cdml61-error">' .
    $errors .
'</div>
</div>';


echo '</div>';


/* ============================================================
 * PROGRESO
 * ============================================================ */

echo '<div class="cdml61-progress">
<div class="cdml61-progress-bar"
style="width:' .
    $percent .
'%;">
' .
    $percent .
'%
</div>
</div>';

echo '<p>
<strong>Progreso:</strong>
' .
    $processed .
' de ' .
    $total .
'</p>';


/* ============================================================
 * BOTÓN REINICIAR
 * ============================================================ */

$reset_url =
    add_query_arg(
        'cd_ml_accion',
        'reiniciar'
    );

echo '<a
class="cdml61-btn"
href="' .
    esc_url($reset_url) .
'">
🔄 Reiniciar análisis
</a>';


/* ============================================================
 * SI TODAVÍA ESTÁ PROCESANDO
 * ============================================================ */

if (
    $processed <
    $total
) {

    echo '<div style="
        padding:18px;
        background:#dbeafe;
        border:1px solid #60a5fa;
        border-radius:10px;
        margin-bottom:20px;
    ">';

    echo '<strong>⏳ Analizando...</strong><br>';

    echo 'Se procesan 3 productos por ciclo.<br>';

    echo 'Procesados: ' .
        $processed .
        ' de ' .
        $total;

    echo '</div>';

    echo '</div>';

    return;
}


/* ============================================================
 * RESUMEN DE ATRIBUTOS FALTANTES
 * ============================================================ */

$attribute_stats = array();


foreach (
    $state['results']
    as $result
) {

    if (
        empty($result['ok']) ||
        empty($result['categories'])
    ) {
        continue;
    }

    foreach (
        $result['categories']
        as $category
    ) {

        foreach (
            $category['comparison']
            ?? array()
            as $attr
        ) {

            if (
                empty($attr['required']) &&
                empty($attr['catalog_required'])
            ) {
                continue;
            }

            if (
                !empty(
                    $attr['ct_present']
                )
            ) {
                continue;
            }

            $id =
                $attr['id']
                ?? '';

            if (!$id) {
                continue;
            }

            if (
                !isset(
                    $attribute_stats[$id]
                )
            ) {

                $attribute_stats[$id] = array(

                    'id' =>
                        $id,

                    'name' =>
                        $attr['name']
                        ?? '',

                    'products' =>
                        0
                );
            }

            $attribute_stats[$id]['products']++;
        }
    }
}


/* ============================================================
 * MOSTRAR RESUMEN
 * ============================================================ */

echo '<h3>
📊 ATRIBUTOS REQUIRED FALTANTES
</h3>';

if (!$attribute_stats) {

    echo '<p class="cdml61-match">
    ✓ No se encontraron atributos REQUIRED faltantes en los datos CT.
    </p>';

} else {

    echo '<table class="cdml61-table">';

    echo '<tr>
        <th>Atributo ID</th>
        <th>Nombre ML</th>
        <th>Productos donde falta</th>
    </tr>';

    foreach (
        $attribute_stats
        as $stat
    ) {

        echo '<tr>';

        echo '<td>' .
            esc_html(
                $stat['id']
            ) .
        '</td>';

        echo '<td>' .
            esc_html(
                $stat['name']
            ) .
        '</td>';

        echo '<td>' .
            intval(
                $stat['products']
            ) .
        '</td>';

        echo '</tr>';
    }

    echo '</table>';
}


/* ============================================================
 * RESULTADOS
 * ============================================================ */

echo '<h3>
📋 RESULTADOS POR PRODUCTO
</h3>';


foreach (
    $state['results']
    as $result
) {

    $product =
        $result['product']
        ?? array();

    $product_name =
        $product['nombre']
        ?? '';

    $product_id =
        $product['idProducto']
        ?? '';

    $subcategory =
        $product['subcategoria']
        ?? '';


    echo '<div class="cdml61-product">';


    echo '<h3>
    📦 ' .
        esc_html(
            $product_name
        ) .
    '</h3>';


    echo '<div>
        <strong>ID:</strong> ' .
        esc_html(
            $product_id
        ) .
        ' &nbsp; | &nbsp;
        <strong>CT:</strong> ' .
        esc_html(
            $subcategory
        ) .
    '</div>';


    if (!$result['ok']) {

        echo '<p class="cdml61-error">
        ❌ ' .
            esc_html(
                $result['error']
                ?? ''
            ) .
        '</p>';

        echo '</div>';

        continue;
    }


    /*
     * DATOS NATIVOS CT
     */

    echo '<div style="
        margin-top:12px;
        padding:12px;
        background:#f0fdf4;
        border:1px solid #86efac;
        border-radius:8px;
    ">';

    echo '<strong>
    📦 DATOS CT UTILIZADOS
    </strong><br><br>';

    echo '<strong>Marca:</strong> ' .
        esc_html(
            $product['marca']
            ?? ''
        ) .
        '<br>';

    echo '<strong>Modelo:</strong> ' .
        esc_html(
            $product['modelo']
            ?? ''
        ) .
        '<br>';

    echo '<strong>Número de parte:</strong> ' .
        esc_html(
            $product['numParte']
            ?? ''
        ) .
        '<br>';

    echo '<strong>EAN:</strong> ' .
        esc_html(
            $product['ean']
            ?? ''
        ) .
        '<br>';

    echo '<strong>UPC:</strong> ' .
        esc_html(
            $product['upc']
            ?? ''
        );

    echo '</div>';


    /*
     * QUERY UTILIZADA
     */

    echo '<div style="
        margin-top:12px;
        padding:12px;
        background:#ecfeff;
        border:1px solid #67e8f9;
        border-radius:8px;
    ">';

    echo '<strong>
    🔎 CONSULTA EN DOMAIN DISCOVERY
    </strong><br><br>';

    echo '<strong>q=</strong> ' .
        esc_html(
            $result['domain_discovery_query']
            ?? $product_name
        );

    echo '</div>';


    /*
     * MAPEO
     */

    $map =
        $result['mapping']
        ?? array();

    echo '<div style="
        margin-top:12px;
        padding:12px;
        background:#eff6ff;
        border-radius:8px;
    ">';

    echo '<strong>
    MAPEO CT:
    </strong><br>';

    echo esc_html(
        $map['ruta']
        ?? ''
    );

    echo '<br><br>';

    echo '<strong>
    CATEGORY ID ML FIJO:
    </strong> ';

    echo esc_html(
        $map['category_id']
        ?? ''
    );

    echo '</div>';


    $categories =
        $result['categories']
        ?? array();


    if (!$categories) {

        echo '<p class="cdml61-warning">
        ⚠️ No se encontraron candidatos de Mercado Libre.
        </p>';

        echo '</div>';

        continue;
    }


    /*
     * RESUMEN DE CANDIDATOS
     */

    echo '<h4>
    🎯 CANDIDATOS ENCONTRADOS POR MERCADO LIBRE
    </h4>';

    echo '<p class="cdml61-small">
    Todos los candidatos que devolvió Domain Discovery son conservados.
    El score de evidencia es solamente diagnóstico y no publica ni selecciona automáticamente una categoría.
    </p>';

    echo '<table class="cdml61-table">';

    echo '<tr>
        <th>#</th>
        <th>Category ID</th>
        <th>Categoría</th>
        <th>Domain</th>
        <th>Score ML</th>
        <th>Evidencia</th>
        <th>Estado</th>
    </tr>';


    $candidate_number = 0;

    foreach (
        $categories
        as $category
    ) {

        $candidate_number++;

        $cid =
            $category['category_id']
            ?? '';

        $cname =
            $category['category_name']
            ?? '';

        $dname =
            $category['domain_name']
            ?? '';

        $dscore =
            floatval(
                $category['score']
                ?? 0
            );

        $evscore =
            floatval(
                $category['evidence']['evidence_score']
                ?? 0
            );

        $status =
            $category['evidence']['status']
            ?? 'CANDIDATO';


        echo '<tr>';

        echo '<td>' .
            $candidate_number .
        '</td>';

        echo '<td><strong>' .
            esc_html($cid) .
        '</strong></td>';

        echo '<td>' .
            esc_html($cname) .
        '</td>';

        echo '<td>' .
            esc_html($dname) .
        '</td>';

        echo '<td>' .
            esc_html(
                $dscore
            ) .
        '</td>';

        echo '<td><strong>' .
            esc_html(
                $evscore
            ) .
            '/100</strong></td>';

        echo '<td>';

        if (
            strpos(
                $status,
                'MAPEO FIJO ENCONTRADO'
            ) !== false
        ) {

            echo '<span class="cdml61-match">
            🎯 ' .
                esc_html($status) .
            '</span>';

        } elseif (
            strpos(
                $status,
                'MAPEO FIJO FORZADO'
            ) !== false
        ) {

            echo '<span class="cdml61-warning">
            ⚠ ' .
                esc_html($status) .
            '</span>';

        } else {

            echo '<span class="cdml61-domain">
            🔎 ' .
                esc_html($status) .
            '</span>';
        }

        echo '</td>';

        echo '</tr>';
    }

    echo '</table>';


    /*
     * DETALLE DE CADA CANDIDATO
     */

    foreach (
        $categories
        as $category
    ) {

        $category_id =
            $category['category_id']
            ?? '';

        $category_name =
            $category['category_name']
            ?? '';

        $domain_id =
            $category['domain_id']
            ?? '';

        $domain_name =
            $category['domain_name']
            ?? '';

        $score =
            $category['score']
            ?? 0;

        $comparison =
            $category['comparison']
            ?? array();

        $schema =
            $category['attributes_schema']
            ?? array();

        $category_url =
            $category['category_url']
            ?? '';

        $category_path =
            $category['category_path']
            ?? '';

        $required_count =
            intval(
                $category['required_count']
                ?? 0
            );

        $catalog_required_count =
            intval(
                $category['catalog_required_count']
                ?? 0
            );

        $required_missing =
            intval(
                $category['required_missing']
                ?? 0
            );

        $catalog_missing =
            intval(
                $category['catalog_missing']
                ?? 0
            );

        $mapped_id =
            $map['category_id']
            ?? '';

        $is_mapped =
            (
                $mapped_id !== '' &&
                $mapped_id === $category_id
            );


        $evidence =
            $category['evidence']
            ?? array();


        echo '<div class="cdml61-category">';


        /*
         * CABECERA
         */

        echo '<h4>';

        echo $is_mapped
            ? '🎯 '
            : '🔎 ';

        echo esc_html(
            $category_id
        );

        if ($category_name !== '') {

            echo ' — ' .
                esc_html(
                    $category_name
                );
        }

        echo '</h4>';


        /*
         * IDENTIFICACIÓN DOMAIN
         */

        echo '<p>';

        echo '<strong>Domain ID:</strong> ' .
            esc_html(
                $domain_id
            );

        echo ' &nbsp; | &nbsp; ';

        echo '<strong>Domain Name:</strong> ' .
            esc_html(
                $domain_name
            );

        echo '</p>';


        /*
         * SCORE
         */

        echo '<p>';

        echo '<strong>Score Domain Discovery:</strong> ' .
            '<span class="cdml61-score">' .
            esc_html(
                $score
            ) .
            '</span>';

        echo ' &nbsp; | &nbsp; ';

        echo '<strong>Evidencia:</strong> ' .
            '<span class="cdml61-score">' .
            esc_html(
                $evidence['evidence_score']
                ?? 0
            ) .
            '/100</span>';

        echo '</p>';


        /*
         * ESTADO
         */

        $status =
            $evidence['status']
            ?? 'CANDIDATO';


        if ($is_mapped) {

            if (
                !empty(
                    $category['forced_mapping']
                )
            ) {

                echo '<div class="cdml61-warning">
                ⚠️ EL CATEGORY ID FIJO NO FUE DEVUELTO POR DOMAIN DISCOVERY.
                Se agregó como candidato forzado para validarlo directamente.
                </div>';

            } else {

                echo '<div class="cdml61-fixed">
                🎯 EL CATEGORY ID FIJO TAMBIÉN FUE ENCONTRADO POR DOMAIN DISCOVERY.
                Esto es una coincidencia importante para el análisis.
                </div>';
            }

        } else {

            echo '<div class="cdml61-evidence">
            🔎 CANDIDATO DEVUELTO DIRECTAMENTE POR DOMAIN DISCOVERY.
            </div>';
        }


        /*
         * URL REAL
         */

        echo '<div style="
            margin-top:12px;
        ">';

        echo '<strong>
        🔗 URL / PERMALINK DE MERCADO LIBRE
        </strong><br><br>';

        if ($category_url !== '') {

            echo '<a
                class="cdml61-url"
                href="' .
                esc_url($category_url) .
                '"
                target="_blank"
                rel="noopener noreferrer"
            >' .
                esc_html($category_url) .
            '</a>';

        } else {

            echo '<span class="cdml61-warning">
            Mercado Libre no proporcionó una URL/permalink utilizable en la información de esta categoría.
            </span>';
        }

        echo '</div>';


        /*
         * RUTA OFICIAL
         */

        if ($category_path !== '') {

            echo '<div class="cdml61-path">';

            echo '<strong>
            🗂 Ruta oficial de categoría
            </strong><br><br>';

            echo esc_html(
                $category_path
            );

            echo '</div>';
        }


        /*
         * CONTADORES
         */

        echo '<p>';

        echo '<strong>Atributos categoría:</strong> ' .
            count($schema);

        echo ' &nbsp; | &nbsp; ';

        echo '<strong>Required:</strong> ' .
            $required_count;

        echo ' &nbsp; | &nbsp; ';

        echo '<strong>Catalog Required:</strong> ' .
            $catalog_required_count;

        echo ' &nbsp; | &nbsp; ';

        echo '<strong>Required faltantes:</strong> ' .
            $required_missing;

        echo ' &nbsp; | &nbsp; ';

        echo '<strong>Catalog faltantes:</strong> ' .
            $catalog_missing;

        echo '</p>';


        /*
         * RESULTADO REQUIRED
         */

        if (
            $required_missing === 0
        ) {

            echo '<p class="cdml61-match">
            🟢 REQUIRED COMPLETOS
            </p>';

        } else {

            echo '<p class="cdml61-missing">
            🔴 FALTAN ' .
                $required_missing .
                ' REQUIRED
            </p>';
        }


        /*
         * EVIDENCIA DETALLADA
         */

        echo '<details>';

        echo '<summary style="
            cursor:pointer;
            font-weight:bold;
            padding:10px;
        ">
        📈 Detalle de evidencia del candidato
        </summary>';

        echo '<table class="cdml61-table">';

        echo '<tr>
            <th>Indicador</th>
            <th>Resultado</th>
        </tr>';

        echo '<tr>
            <td>Score original de Mercado Libre</td>
            <td>' .
            esc_html(
                $evidence['domain_score']
                ?? 0
            ) .
            '</td>
        </tr>';

        echo '<tr>
            <td>Atributos detectados por Domain Discovery</td>
            <td>' .
            intval(
                $evidence['domain_detected_count']
                ?? 0
            ) .
            '</td>
        </tr>';

        echo '<tr>
            <td>Atributos CT presentes en esquema ML</td>
            <td>' .
            intval(
                $evidence['ct_present_count']
                ?? 0
            ) .
            '</td>
        </tr>';

        echo '<tr>
            <td>Required presentes</td>
            <td>' .
            intval(
                $evidence['required_present']
                ?? 0
            ) .
            ' de ' .
            intval(
                $evidence['required_total']
                ?? 0
            ) .
            '</td>
        </tr>';

        echo '<tr>
            <td>Catalog Required presentes</td>
            <td>' .
            intval(
                $evidence['catalog_present']
                ?? 0
            ) .
            ' de ' .
            intval(
                $evidence['catalog_total']
                ?? 0
            ) .
            '</td>
        </tr>';

        echo '<tr>
            <td><strong>Evidencia total</strong></td>
            <td><strong>' .
            esc_html(
                $evidence['evidence_score']
                ?? 0
            ) .
            '/100</strong></td>
        </tr>';

        echo '</table>';

        echo '</details>';


        /*
         * ======================================================
         * COMPARACIÓN CT → ML
         * ======================================================
         */

        echo '<h4>
        🧩 ATRIBUTOS ML VS DATOS CT
        </h4>';


        echo '<table class="cdml61-table">';

        echo '<tr>
            <th>ID ML</th>
            <th>Nombre ML</th>
            <th>Valor CT</th>
            <th>Valor detectado ML</th>
            <th>Required</th>
            <th>Catalog Required</th>
            <th>Estado</th>
        </tr>';


        foreach (
            $comparison
            as $attr
        ) {

            $ct_present =
                !empty(
                    $attr['ct_present']
                );

            $domain_detected =
                !empty(
                    $attr['domain_detected']
                );


            echo '<tr>';


            echo '<td>' .
                esc_html(
                    $attr['id']
                    ?? ''
                ) .
            '</td>';


            echo '<td>' .
                esc_html(
                    $attr['name']
                    ?? ''
                ) .
            '</td>';


            echo '<td>';

            if ($ct_present) {

                echo '<span class="cdml61-present">';

                echo esc_html(
                    $attr['ct_value']
                    ?? ''
                );

                echo '</span>';

            } else {

                echo '—';
            }

            echo '</td>';


            echo '<td>';

            if ($domain_detected) {

                echo '<span class="cdml61-domain">';

                echo esc_html(
                    $attr['value_name']
                    ?? ''
                );

                echo '</span>';

            } else {

                echo '—';
            }

            echo '</td>';


            echo '<td>';

            echo !empty(
                $attr['required']
            )
                ? 'SÍ'
                : 'NO';

            echo '</td>';


            echo '<td>';

            echo !empty(
                $attr['catalog_required']
            )
                ? 'SÍ'
                : 'NO';

            echo '</td>';


            echo '<td>';

            if ($ct_present) {

                echo '<span class="cdml61-match">
                ✓ PRESENTE EN CT
                </span>';

            } elseif ($domain_detected) {

                echo '<span class="cdml61-inferred">
                ⚠ ML LO DETECTÓ / CT FALTA
                </span>';

            } else {

                if (
                    !empty(
                        $attr['required']
                    ) ||
                    !empty(
                        $attr['catalog_required']
                    )
                ) {

                    echo '<span class="cdml61-missing">
                    ✗ FALTA
                    </span>';

                } else {

                    echo 'NO PRESENTE';
                }
            }

            echo '</td>';

            echo '</tr>';
        }


        echo '</table>';


        /*
         * REQUIRED FALTANTES
         */

        echo '<h4>
        🔴 REQUIRED FALTANTES EN CT
        </h4>';


        $missing_required =
            array();

        foreach (
            $comparison
            as $attr
        ) {

            if (
                !empty(
                    $attr['required']
                ) &&
                empty(
                    $attr['ct_present']
                )
            ) {

                $missing_required[] =
                    $attr;
            }
        }


        if (!$missing_required) {

            echo '<p class="cdml61-match">
            ✓ No hay REQUIRED faltantes.
            </p>';

        } else {

            echo '<table class="cdml61-table">';

            echo '<tr>
                <th>ID</th>
                <th>Nombre ML</th>
                <th>Domain Discovery</th>
                <th>Estado CT</th>
            </tr>';


            foreach (
                $missing_required
                as $attr
            ) {

                echo '<tr>';

                echo '<td>' .
                    esc_html(
                        $attr['id']
                    ) .
                '</td>';

                echo '<td>' .
                    esc_html(
                        $attr['name']
                    ) .
                '</td>';

                echo '<td>';

                if (
                    !empty(
                        $attr['domain_detected']
                    )
                ) {

                    echo '<span class="cdml61-inferred">
                    SÍ
                    </span>';

                } else {

                    echo 'NO';
                }

                echo '</td>';

                echo '<td>
                <span class="cdml61-missing">
                FALTA EN CT
                </span>
                </td>';

                echo '</tr>';
            }


            echo '</table>';
        }


        /*
         * CATALOG REQUIRED FALTANTES
         */

        echo '<h4>
        🟡 CATALOG_REQUIRED FALTANTES EN CT
        </h4>';


        $missing_catalog =
            array();

        foreach (
            $comparison
            as $attr
        ) {

            if (
                !empty(
                    $attr['catalog_required']
                ) &&
                empty(
                    $attr['ct_present']
                )
            ) {

                $missing_catalog[] =
                    $attr;
            }
        }


        if (!$missing_catalog) {

            echo '<p class="cdml61-match">
            ✓ No hay CATALOG_REQUIRED faltantes.
            </p>';

        } else {

            echo '<table class="cdml61-table">';

            echo '<tr>
                <th>ID</th>
                <th>Nombre ML</th>
                <th>Estado</th>
            </tr>';


            foreach (
                $missing_catalog
                as $attr
            ) {

                echo '<tr>';

                echo '<td>' .
                    esc_html(
                        $attr['id']
                    ) .
                '</td>';

                echo '<td>' .
                    esc_html(
                        $attr['name']
                    ) .
                '</td>';

                echo '<td>
                <span class="cdml61-missing">
                FALTA EN CT
                </span>
                </td>';

                echo '</tr>';
            }


            echo '</table>';
        }


        /*
         * DOMAIN DISCOVERY
         */

        echo '<details>';

        echo '<summary style="
            cursor:pointer;
            font-weight:bold;
            padding:10px;
        ">
        🔎 Atributos reconocidos por domain_discovery
        (' .
            count(
                $category['attributes_detected']
                ?? array()
            ) .
        ')
        </summary>';


        $detected =
            $category['attributes_detected']
            ?? array();


        if (!$detected) {

            echo '<p class="cdml61-warning">
            domain_discovery no detectó atributos para este resultado.
            </p>';

        } else {

            echo '<table class="cdml61-table">';

            echo '<tr>
                <th>ID</th>
                <th>Nombre ML</th>
                <th>Value ID</th>
                <th>Value Name</th>
            </tr>';


            foreach (
                $detected
                as $attr
            ) {

                echo '<tr>';

                echo '<td>' .
                    esc_html(
                        $attr['id']
                        ?? ''
                    ) .
                '</td>';

                echo '<td>' .
                    esc_html(
                        $attr['name']
                        ?? ''
                    ) .
                '</td>';

                echo '<td>' .
                    esc_html(
                        $attr['value_id']
                        ?? ''
                    ) .
                '</td>';

                echo '<td>' .
                    esc_html(
                        $attr['value_name']
                        ?? ''
                    ) .
                '</td>';

                echo '</tr>';
            }


            echo '</table>';
        }


        echo '</details>';


        /*
         * RESPUESTA COMPLETA DE DOMAIN DISCOVERY
         */

        echo '<details>';

        echo '<summary style="
            cursor:pointer;
            font-weight:bold;
            padding:10px;
        ">
        🧾 Respuesta completa de este candidato
        </summary>';

        echo '<pre class="cdml61-json">';

        echo esc_html(
            wp_json_encode(
                $category['domain_raw']
                ?? array(),
                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE
            )
        );

        echo '</pre>';

        echo '</details>';


        /*
         * ESQUEMA COMPLETO
         */

        echo '<details>';

        echo '<summary style="
            cursor:pointer;
            font-weight:bold;
            padding:10px;
        ">
        📋 Ver todos los atributos de esta categoría
        (' .
            count($schema) .
        ')
        </summary>';


        echo '<table class="cdml61-table">';

        echo '<tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Tipo</th>
            <th>Required</th>
            <th>Catalog Required</th>
        </tr>';


        foreach (
            $schema
            as $attr
        ) {

            echo '<tr>';

            echo '<td>' .
                esc_html(
                    $attr['id']
                ) .
            '</td>';

            echo '<td>' .
                esc_html(
                    $attr['name']
                ) .
            '</td>';

            echo '<td>' .
                esc_html(
                    $attr['value_type']
                ) .
            '</td>';

            echo '<td>';

            echo !empty(
                $attr['required']
            )
                ? '<span class="cdml61-match">SÍ</span>'
                : 'NO';

            echo '</td>';

            echo '<td>';

            echo !empty(
                $attr['catalog_required']
            )
                ? '<span class="cdml61-match">SÍ</span>'
                : 'NO';

            echo '</td>';

            echo '</tr>';
        }


        echo '</table>';

        echo '</details>';


        /*
         * INFORMACIÓN COMPLETA DE CATEGORÍA
         */

        $info =
            $category['category_info']
            ?? array();

        if ($info) {

            echo '<details>';

            echo '<summary style="
                cursor:pointer;
                font-weight:bold;
                padding:10px;
            ">
            🗂 Información oficial completa de categoría ML
            </summary>';

            echo '<pre class="cdml61-json">';

            echo esc_html(
                wp_json_encode(
                    $info,
                    JSON_PRETTY_PRINT |
                    JSON_UNESCAPED_UNICODE
                )
            );

            echo '</pre>';

            echo '</details>';
        }


        echo '</div>';
    }


    echo '</div>';
}


echo '</div>';
?>