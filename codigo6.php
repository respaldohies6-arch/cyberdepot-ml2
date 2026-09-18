<?php
/* ============================================================
 * CYBERDEPOT - CONFIGURADOR PC GAMER
 * Snippet: configuradorpc
 *
 * FUENTE DE PRODUCTOS:
 * /wp-content/uploads/productos.json
 *
 * IMPORTANTE:
 * - NO utiliza Mercado Libre.
 * - NO utiliza arbol_mapeo.txt.
 * - Las categorías se toman de productos.json.
 * - Primero valida existencia en CT.
 * - Después valida que el producto exista realmente en WooCommerce.
 * - Prueba clave y numParte.
 * - El SKU encontrado en WooCommerce se usa para obtener
 *   el precio ajustado de CT Online.
 * ============================================================ */

if (!function_exists('cdpc_mostrar_configurador')) {


    /* =========================================================
     * CONFIGURACIÓN DE COMPONENTES
     * ========================================================= */

    function cdpc_componentes_config() {

        return array(

            /* =================================================
             * TARJETA DE VIDEO
             *
             * En productos.json:
             * categoria    = Tarjetas
             * subcategoria = Tarjetas de Video
             * ================================================= */
            'gpu' => array(
                'titulo' => 'Tarjeta de Video',
                'icono'  => '🎮',
                'color'  => '#8b5cf6',

                'subcategorias' => array(
                    'Tarjetas de Video'
                )
            ),

            /* =================================================
             * CPU
             *
             * categoria    = Ensamble
             * subcategoria = Microprocesadores
             * ================================================= */
            'cpu' => array(
                'titulo' => 'Microprocesador',
                'icono'  => '🧠',
                'color'  => '#3b82f6',

                'subcategorias' => array(
                    'Microprocesadores'
                )
            ),

            /* =================================================
             * MOTHERBOARD
             * ================================================= */
            'motherboard' => array(
                'titulo' => 'Motherboard',
                'icono'  => '🔷',
                'color'  => '#06b6d4',

                'subcategorias' => array(
                    'Motherboards',
                    'Motherboards Gaming'
                )
            ),

            /* =================================================
             * RAM
             *
             * Solo Memorias RAM para PC.
             * NO incluimos Memorias RAM para Servidores.
             * ================================================= */
            'ram' => array(
                'titulo' => 'Memoria RAM',
                'icono'  => '💾',
                'color'  => '#22c55e',

                'subcategorias' => array(
                    'Memorias RAM'
                )
            ),

            /* =================================================
             * ALMACENAMIENTO
             *
             * Regla estricta:
             * categoria = Almacenamiento
             * subcategoria = Discos Duros / SSD
             *
             * NO incluye Almacenamiento Portatil.
             * ================================================= */
            'storage' => array(
                'titulo' => 'Almacenamiento',
                'icono'  => '💽',
                'color'  => '#eab308',

                'reglas_categoria' => array(
                    array(
                        'categoria' => 'Almacenamiento',
                        'subcategorias' => array(
                            'Discos Duros',
                            'SSD'
                        )
                    )
                )
            ),

            /* =================================================
             * FUENTE DE PODER
             *
             * Incluye las fuentes normales y Gaming.
             * NO incluye fuentes de videovigilancia/POS/servidor.
             * ================================================= */
            'psu' => array(
                'titulo' => 'Fuente de Poder',
                'icono'  => '⚡',
                'color'  => '#f97316',

                'subcategorias' => array(
                    'Fuentes de Poder',
                    'Fuentes de Poder Gaming'
                )
            ),

            /* =================================================
             * GABINETE
             * ================================================= */
            'case' => array(
                'titulo' => 'Gabinete',
                'icono'  => '🖥️',
                'color'  => '#ef4444',

                'subcategorias' => array(
                    'Gabinetes para Computadoras',
                    'Gabinetes Gaming'
                )
            ),

            /* =================================================
             * ENFRIAMIENTO
             *
             * Solo la subcategoría real de ensamble.
             * No incluye bases enfriadoras para laptop.
             * ================================================= */
            'cooling' => array(
                'titulo' => 'Enfriamiento y Ventilación',
                'icono'  => '❄️',
                'color'  => '#06b6d4',

                'subcategorias' => array(
                    'Enfriamiento y Ventilación'
                )
            ),

            /* =================================================
             * LECTORES DE MEMORIAS
             * ================================================= */
            'readers' => array(
                'titulo' => 'Lectores de Memorias',
                'icono'  => '📀',
                'color'  => '#a855f7',

                'subcategorias' => array(
                    'Lectores de Memorias'
                )
            )
        );
    }

    /* =========================================================
     * RUTA JSON
     * ========================================================= */

    function cdpc_ruta_json() {

        $upload = wp_upload_dir();

        return trailingslashit($upload['basedir']) . 'productos.json';
    }


    /* =========================================================
     * NORMALIZAR TEXTO
     * ========================================================= */

    function cdpc_normalizar_texto($texto) {

        if (is_array($texto) || is_object($texto)) {
            return '';
        }

        $texto = (string) $texto;

        if (function_exists('remove_accents')) {
            $texto = remove_accents($texto);
        }

        $texto = html_entity_decode(
            $texto,
            ENT_QUOTES,
            'UTF-8'
        );

        $texto = strtolower($texto);

        $texto = str_replace(
            array('–', '—', '_'),
            '-',
            $texto
        );

        $texto = preg_replace('/\s*-\s*/', ' - ', $texto);

        $texto = preg_replace('/\s+/', ' ', $texto);

        return trim($texto);
    }


    /* =========================================================
     * NORMALIZAR CATEGORÍA
     * ========================================================= */

    function cdpc_normalizar_categoria($texto) {

        return cdpc_normalizar_texto($texto);
    }


    /* =========================================================
     * COINCIDENCIA DE CATEGORÍA
     *
     * Acepta:
     *
     * 1) categorias simples:
     *    "Tarjetas de Video"
     *
     * 2) reglas:
     *    categoria + subcategorias
     *
     * Esto permite que almacenamiento use:
     *
     * Almacenamiento
     *   -> Discos Duros
     *   -> SSD
     * ========================================================= */

    function cdpc_categoria_coincide($producto, $config) {

        $categoria_producto = isset($producto['categoria'])
            ? cdpc_normalizar_categoria($producto['categoria'])
            : '';

        $subcategoria_producto = isset($producto['subcategoria'])
            ? cdpc_normalizar_categoria($producto['subcategoria'])
            : '';

        if (
            $categoria_producto === '' &&
            $subcategoria_producto === ''
        ) {
            return false;
        }

        /* =====================================================
         * 1. REGLAS ESTRUCTURADAS
         * ===================================================== */
        if (
            !empty($config['reglas_categoria']) &&
            is_array($config['reglas_categoria'])
        ) {

            foreach ($config['reglas_categoria'] as $regla) {

                if (!is_array($regla)) {
                    continue;
                }

                $categoria_regla = isset($regla['categoria'])
                    ? cdpc_normalizar_categoria($regla['categoria'])
                    : '';

                if ($categoria_regla === '') {
                    continue;
                }

                if ($categoria_producto !== $categoria_regla) {
                    continue;
                }

                $subcategorias =
                    isset($regla['subcategorias']) &&
                    is_array($regla['subcategorias'])
                    ? $regla['subcategorias']
                    : array();

                if (empty($subcategorias)) {
                    return true;
                }

                foreach ($subcategorias as $subcategoria) {

                    if (
                        $subcategoria_producto ===
                        cdpc_normalizar_categoria($subcategoria)
                    ) {
                        return true;
                    }
                }
            }

            return false;
        }

        /* =====================================================
         * 2. SUBCATEGORÍAS EXACTAS
         *
         * Este es el formato principal del catálogo CT Online.
         * Ejemplo:
         *   categoria = Ensamble
         *   subcategoria = Memorias RAM
         * ===================================================== */
        if (
            !empty($config['subcategorias']) &&
            is_array($config['subcategorias'])
        ) {

            foreach ($config['subcategorias'] as $objetivo) {

                $objetivo = cdpc_normalizar_categoria($objetivo);

                if (
                    $objetivo !== '' &&
                    $subcategoria_producto === $objetivo
                ) {
                    return true;
                }
            }
        }

        /* =====================================================
         * 3. CATEGORÍAS EXACTAS
         *
         * Se conserva para categorías antiguas o productos cuyo
         * nombre del componente esté directamente en categoria.
         * ===================================================== */
        if (
            !empty($config['categorias']) &&
            is_array($config['categorias'])
        ) {

            foreach ($config['categorias'] as $objetivo) {

                $objetivo = cdpc_normalizar_categoria($objetivo);

                if ($objetivo === '') {
                    continue;
                }

                if ($categoria_producto === $objetivo) {
                    return true;
                }

                if ($subcategoria_producto === $objetivo) {
                    return true;
                }

                $combinada =
                    $categoria_producto .
                    ' - ' .
                    $subcategoria_producto;

                if ($combinada === $objetivo) {
                    return true;
                }
            }
        }

        return false;
    }

    /* =========================================================
     * EXISTENCIA JSON
     * ========================================================= */

    function cdpc_existencia_json($producto) {

        if (!isset($producto['existencia'])) {
            return 0;
        }

        $valor = $producto['existencia'];

        /*
         * CT Online puede entregar existencia como número o como
         * estructura por almacén. En el segundo caso sumamos todas
         * las existencias numéricas.
         */
        if (is_array($valor) || is_object($valor)) {

            $total = 0;

            $recorrer = function($dato) use (&$recorrer, &$total) {

                if (is_object($dato)) {
                    $dato = (array) $dato;
                }

                if (is_array($dato)) {
                    foreach ($dato as $v) {
                        $recorrer($v);
                    }
                    return;
                }

                if (is_numeric($dato)) {
                    $total += (float) $dato;
                }
            };

            $recorrer($valor);

            return $total;
        }

        $valor = str_replace(
            array(',', '$', ' '),
            '',
            (string) $valor
        );

        return floatval($valor);
    }


    /* =========================================================
     * OBTENER SKUS CANDIDATOS
     *
     * IMPORTANTE:
     * No asumimos que "clave" siempre es el SKU de WooCommerce.
     *
     * Se prueban:
     * 1. clave
     * 2. numParte
     *
     * sin duplicarlos.
     * ========================================================= */

    function cdpc_obtener_skus($producto) {

        $resultado = array();

        $campos = array(
            'clave',
            'numParte'
        );

        foreach ($campos as $campo) {

            if (!isset($producto[$campo])) {
                continue;
            }

            $sku = trim((string) $producto[$campo]);

            if ($sku === '') {
                continue;
            }

            if (!in_array($sku, $resultado, true)) {
                $resultado[] = $sku;
            }
        }

        return $resultado;
    }


    /* =========================================================
     * COMPATIBILIDAD CON CÓDIGO ANTERIOR
     * ========================================================= */

    function cdpc_obtener_sku($producto) {

        $skus = cdpc_obtener_skus($producto);

        return !empty($skus)
            ? $skus[0]
            : '';
    }


    /* =========================================================
     * APLANAR VALORES DE ESPECIFICACIONES
     * ========================================================= */

    function cdpc_aplanar_valores($valor) {

        $resultado = array();

        if (is_array($valor)) {

            foreach ($valor as $v) {

                if (is_array($v)) {
                    $resultado = array_merge(
                        $resultado,
                        cdpc_aplanar_valores($v)
                    );
                }
                elseif (is_object($v)) {
                    $resultado = array_merge(
                        $resultado,
                        cdpc_aplanar_valores((array) $v)
                    );
                }
                else {
                    $resultado[] = (string) $v;
                }
            }

        }
        elseif (is_object($valor)) {

            $resultado = cdpc_aplanar_valores(
                (array) $valor
            );

        }
        else {

            $resultado[] = (string) $valor;
        }

        return $resultado;
    }


    /* =========================================================
     * TEXTO DE ESPECIFICACIONES
     * ========================================================= */

    function cdpc_especificaciones_texto($producto) {

        $texto = '';

        if (!empty($producto['especificaciones'])) {

            $valores = cdpc_aplanar_valores(
                $producto['especificaciones']
            );

            if ($valores) {
                $texto = implode(
                    ' | ',
                    $valores
                );
            }
        }

        /*
         * También incluimos campos importantes del JSON.
         */
        $campos = array(
            'nombre',
            'modelo',
            'marca',
            'numParte',
            'clave'
        );

        foreach ($campos as $campo) {

            if (!empty($producto[$campo])) {

                $texto .= ' ' .
                    (string) $producto[$campo];
            }
        }

        return cdpc_normalizar_texto($texto);
    }


    /* =========================================================
     * FICHA PARA COMPATIBILIDAD
     * ========================================================= */

    function cdpc_ficha_compatibilidad($producto) {

        $texto = cdpc_especificaciones_texto($producto);

        $ficha = array(
            'texto' => $texto,

            'socket' => '',
            'ddr' => '',
            'form_factor' => '',

            'nvme' => false,
            'm2' => false,
            'sata' => false,
            'pcie' => false,

            'watts' => 0,
            'gpu' => false,
            'cpu' => false,
            'motherboard' => false,
            'ram' => false,
            'storage' => false,
            'psu' => false,
            'case' => false,
            'cooling' => false
        );


        /*
         * SOCKET
         */

        $sockets = array(
            'am5',
            'am4',
            'lga1700',
            'lga1200',
            'lga1151',
            'lga1150',
            'lga2066',
            'lga2011',
            'fm2',
            'fm2+',
            'fm1'
        );

        foreach ($sockets as $socket) {

            if (strpos($texto, $socket) !== false) {
                $ficha['socket'] = strtoupper($socket);
                break;
            }
        }


        /*
         * DDR
         */

        if (preg_match(
            '/\bddr[\s\-]?([2-6])\b/i',
            $texto,
            $m
        )) {

            $ficha['ddr'] = 'DDR' . $m[1];
        }


        /*
         * FORM FACTOR
         */

        $factores = array(
            'e-atx',
            'eatx',
            'atx',
            'micro atx',
            'micro-atx',
            'matx',
            'mini itx',
            'mini-itx',
            'itx'
        );

        foreach ($factores as $factor) {

            if (strpos($texto, $factor) !== false) {

                $ficha['form_factor'] =
                    strtoupper(
                        str_replace(
                            array(' ', '-'),
                            '',
                            $factor
                        )
                    );

                break;
            }
        }


        /*
         * ALMACENAMIENTO
         */

        if (
            strpos($texto, 'nvme') !== false ||
            strpos($texto, 'm.2 nvme') !== false
        ) {
            $ficha['nvme'] = true;
        }

        if (
            strpos($texto, 'm.2') !== false ||
            strpos($texto, 'm2') !== false
        ) {
            $ficha['m2'] = true;
        }

        if (strpos($texto, 'sata') !== false) {
            $ficha['sata'] = true;
        }

        if (
            strpos($texto, 'pcie') !== false ||
            strpos($texto, 'pci-e') !== false
        ) {
            $ficha['pcie'] = true;
        }


        /*
         * WATTS
         */

        if (preg_match_all(
            '/(\d{3,4})\s*(?:w|watts|watt)\b/i',
            $texto,
            $matches
        )) {

            $watts = array_map(
                'intval',
                $matches[1]
            );

            if ($watts) {
                $ficha['watts'] =
                    max($watts);
            }
        }


        return $ficha;
    }


    /* =========================================================
     * DATOS REALES DE WOOCOMMERCE
     *
     * Aquí se evita mostrar productos fantasma.
     * ========================================================= */

    function cdpc_datos_wc($sku) {

        if (!function_exists('wc_get_product_id_by_sku')) {
            return false;
        }

        $sku = trim((string) $sku);

        if ($sku === '') {
            return false;
        }

        $product_id = wc_get_product_id_by_sku($sku);

        if (!$product_id) {
            return false;
        }

        $product = wc_get_product($product_id);

        if (!$product) {
            return false;
        }


        /*
         * Debe estar publicado.
         */

        if (
            get_post_status($product_id) !==
            'publish'
        ) {
            return false;
        }


        /*
         * No mostrar productos ocultos
         * del catálogo.
         */

        $visibility =
            $product->get_catalog_visibility();

        if ($visibility === 'hidden') {
            return false;
        }


        /*
         * Producto real.
         */

        $precio_wc = $product->get_price();

        $imagen = '';

        if ($product->get_image_id()) {

            $imagen =
                wp_get_attachment_image_url(
                    $product->get_image_id(),
                    'woocommerce_thumbnail'
                );
        }

        return array(

            'id' => $product_id,

            'sku' =>
                (string) $product->get_sku(),

            'nombre' =>
                $product->get_name(),

            'precio_wc' =>
                $precio_wc,

            'imagen' =>
                $imagen,

            'producto' =>
                $product
        );
    }


    /* =========================================================
     * EXTRAER PRECIO DEVUELTO POR CT ONLINE
     *
     * Protege contra respuestas:
     * - numéricas
     * - strings
     * - arrays
     * - objetos
     * ========================================================= */

    function cdpc_extraer_precio_ct($valor) {

        if (
            $valor === null ||
            $valor === false
        ) {
            return 0;
        }


        /*
         * Número directo.
         */

        if (is_numeric($valor)) {

            $numero = floatval($valor);

            return $numero > 0
                ? $numero
                : 0;
        }


        /*
         * Objeto.
         */

        if (is_object($valor)) {
            $valor = (array) $valor;
        }


        /*
         * Array.
         */

        if (is_array($valor)) {

            $claves = array(
                'precio_ajustado',
                'precioAjustado',
                'precio',
                'precioVenta',
                'precio_venta',
                'precio_final',
                'precioFinal',
                'precio_publico',
                'precioPublico',
                'total'
            );

            foreach ($claves as $clave) {

                if (!array_key_exists(
                    $clave,
                    $valor
                )) {
                    continue;
                }

                $precio =
                    cdpc_extraer_precio_ct(
                        $valor[$clave]
                    );

                if ($precio > 0) {
                    return $precio;
                }
            }

            return 0;
        }


        /*
         * String.
         */

        if (is_string($valor)) {

            $texto = trim($valor);

            if ($texto === '') {
                return 0;
            }

            /*
             * Quitamos símbolos monetarios.
             */
            $texto = str_replace(
                array(
                    '$',
                    'MXN',
                    'USD',
                    'mxn',
                    'usd',
                    ' '
                ),
                '',
                $texto
            );

            /*
             * Caso normal:
             * 12,345.67
             */
            if (
                strpos($texto, ',') !== false &&
                strpos($texto, '.') !== false
            ) {

                $texto =
                    str_replace(',', '', $texto);
            }

            /*
             * Caso:
             * 12345,67
             */
            elseif (
                strpos($texto, ',') !== false &&
                strpos($texto, '.') === false
            ) {

                $texto =
                    str_replace(',', '.', $texto);
            }

            $texto =
                preg_replace(
                    '/[^0-9.\-]/',
                    '',
                    $texto
                );

            if (is_numeric($texto)) {

                $numero =
                    floatval($texto);

                return $numero > 0
                    ? $numero
                    : 0;
            }
        }

        return 0;
    }


    /* =========================================================
     * PRECIO CT ONLINE
     *
     * PRIORIDAD:
     *
     * 1. obtener_precio_ajustado_ctonline_completo()
     * 2. JSON como respaldo
     *
     * MUY IMPORTANTE:
     * $sku debe ser el SKU REAL encontrado en WooCommerce.
     * ========================================================= */

    function cdpc_precio_ctonline(
        $sku,
        $producto_json
    ) {

        $precio = 0;

        /* =====================================================
         * PRIORIDAD 1:
         * FUNCIÓN REAL DEL SISTEMA CT ONLINE
         *
         * Esta es la función que ya utiliza tu integración:
         * obtenerPrecioFinalDelProducto()
         *
         * Devuelve el precio normal/promocional de CT Online.
         * ===================================================== */

        if (function_exists('obtenerPrecioFinalDelProducto')) {

            try {

                $token = '';

                if (function_exists('crearNuevoToken')) {
                    $token = crearNuevoToken();
                }

                if (!empty($token)) {

                    $respuesta =
                        obtenerPrecioFinalDelProducto(
                            $sku,
                            $token
                        );

                    if (is_array($respuesta)) {

                        $precio = cdpc_extraer_precio_ct(
                            $respuesta['precio'] ?? 0
                        );
                    }
                    else {

                        $precio = cdpc_extraer_precio_ct(
                            $respuesta
                        );
                    }

                    if ($precio > 0) {
                        return $precio;
                    }
                }

            } catch (Throwable $e) {
                $precio = 0;
            }
        }


        /* =====================================================
         * PRIORIDAD 2:
         * FUNCIÓN ANTIGUA SI ESTÁ DISPONIBLE
         * ===================================================== */

        if (
            $precio <= 0 &&
            function_exists(
                'obtener_precio_ajustado_ctonline_completo'
            )
        ) {

            try {

                $respuesta =
                    obtener_precio_ajustado_ctonline_completo(
                        $sku
                    );

                $precio =
                    cdpc_extraer_precio_ct(
                        $respuesta
                    );

                if ($precio > 0) {
                    return $precio;
                }

            } catch (Throwable $e) {
                $precio = 0;
            }
        }


        /* =====================================================
         * PRIORIDAD 3:
         * JSON COMO RESPALDO
         * ===================================================== */

        $posibles = array(
            'precio',
            'precioVenta',
            'precioPublico',
            'precioLista'
        );

        foreach ($posibles as $campo) {

            if (
                !isset($producto_json[$campo]) ||
                !is_numeric($producto_json[$campo])
            ) {
                continue;
            }

            $precio = floatval($producto_json[$campo]);

            if ($precio <= 0) {
                continue;
            }

            /*
             * Si el JSON marca el precio como USD, convertirlo
             * usando el tipo de cambio guardado en el producto.
             */

            $moneda = isset($producto_json['moneda'])
                ? strtoupper(trim((string) $producto_json['moneda']))
                : '';

            $tipo_cambio = 0;

            if (
                isset($producto_json['tipoCambio']) &&
                is_numeric($producto_json['tipoCambio'])
            ) {
                $tipo_cambio = floatval($producto_json['tipoCambio']);
            }

            if (
                $moneda === 'USD' &&
                $tipo_cambio > 0
            ) {
                $precio *= $tipo_cambio;
            }

            return $precio;
        }

        return 0;
    }

    /* =========================================================
     * IMAGEN JSON
     * ========================================================= */

    function cdpc_imagen_json(
        $producto,
        $sku = ''
    ) {

        /*
         * Posibles campos directos.
         */

        $campos = array(
            'imagen',
            'imagenUrl',
            'imagenURL',
            'urlImagen',
            'url_imagen',
            'foto',
            'fotoUrl'
        );

        foreach ($campos as $campo) {

            if (
                isset($producto[$campo]) &&
                is_string($producto[$campo]) &&
                trim($producto[$campo]) !== ''
            ) {

                $url =
                    trim(
                        $producto[$campo]
                    );

                if (
                    filter_var(
                        $url,
                        FILTER_VALIDATE_URL
                    )
                ) {
                    return esc_url($url);
                }
            }
        }


        /*
         * Imagen CT por SKU.
         */

        if ($sku !== '') {

            $url =
                'https://static.ctonline.mx/imagenes/' .
                rawurlencode($sku) .
                '/' .
                rawurlencode($sku) .
                '_full.jpg';

            return esc_url($url);
        }

        return '';
    }


    /* =========================================================
     * PREPARAR PRODUCTOS
     *
     * AQUÍ ESTÁ LA CORRECCIÓN PRINCIPAL.
     *
     * Flujo:
     *
     * JSON
     *   ↓
     * categoría correcta
     *   ↓
     * existencia > 0
     *   ↓
     * probar clave
     *   ↓
     * probar numParte
     *   ↓
     * WooCommerce real
     *   ↓
     * SKU REAL DE WOO
     *   ↓
     * CT ONLINE
     *   ↓
     * producto
     * ========================================================= */

    function cdpc_preparar_productos(
        $component_key,
        $config
    ) {

        $ruta = cdpc_ruta_json();

        if (!file_exists($ruta)) {
            return array();
        }

        $contenido =
            file_get_contents($ruta);

        if (
            $contenido === false ||
            trim($contenido) === ''
        ) {
            return array();
        }

        $json =
            json_decode(
                $contenido,
                true
            );

        if (
            !is_array($json)
        ) {
            return array();
        }


        /*
         * Algunos JSON pueden tener:
         *
         * {
         *   "productos": [...]
         * }
         *
         * o directamente:
         *
         * [...]
         */

        if (
            isset($json['productos']) &&
            is_array($json['productos'])
        ) {

            $productos_json =
                $json['productos'];

        }
        else {

            $productos_json =
                $json;
        }


        $resultado = array();

        /*
         * Evitar duplicados por producto Woo.
         */
        $productos_usados = array();


        foreach ($productos_json as $producto_json) {

            if (!is_array($producto_json)) {
                continue;
            }


            /*
             * -------------------------------------------------
             * 1. CATEGORÍA CT ONLINE
             * -------------------------------------------------
             */

            if (
                !cdpc_categoria_coincide(
                    $producto_json,
                    $config
                )
            ) {
                continue;
            }


            /*
             * -------------------------------------------------
             * 2. EXISTENCIA CT
             * -------------------------------------------------
             */

            if (
                cdpc_existencia_json(
                    $producto_json
                ) <= 0
            ) {
                continue;
            }


            /*
             * -------------------------------------------------
             * 3. OBTENER CANDIDATOS:
             *    clave + numParte
             * -------------------------------------------------
             */

            $skus =
                cdpc_obtener_skus(
                    $producto_json
                );

            if (empty($skus)) {
                continue;
            }


            /*
             * -------------------------------------------------
             * 4. BUSCAR PRODUCTO REAL EN WOOCOMMERCE
             *
             * Probamos TODOS los candidatos hasta encontrar
             * uno válido.
             * -------------------------------------------------
             */

            $wc_data = false;
            $sku_real = '';

            foreach ($skus as $sku_candidato) {

                $datos =
                    cdpc_datos_wc(
                        $sku_candidato
                    );

                if (!$datos) {
                    continue;
                }

                $wc_data =
                    $datos;

                /*
                 * Usamos el SKU que realmente tiene
                 * WooCommerce.
                 */
                $sku_real =
                    !empty($datos['sku'])
                    ? $datos['sku']
                    : $sku_candidato;

                break;
            }


            /*
             * Si ninguno existe en WooCommerce:
             *
             * NO mostrar.
             *
             * Esto elimina los productos fantasma.
             */

            if (!$wc_data || !$sku_real) {
                continue;
            }


            /*
             * -------------------------------------------------
             * 5. EVITAR DUPLICADOS
             * -------------------------------------------------
             */

            if (
                isset(
                    $productos_usados[
                        $wc_data['id']
                    ]
                )
            ) {
                continue;
            }

            $productos_usados[
                $wc_data['id']
            ] = true;


            /*
             * -------------------------------------------------
             * 6. PRECIO CT ONLINE
             *
             * USAMOS EL SKU REAL ENCONTRADO EN WOO.
             * -------------------------------------------------
             */

            $precio =
                cdpc_precio_ctonline(
                    $sku_real,
                    $producto_json
                );

            if ($precio <= 0) {
                continue;
            }


            /*
             * -------------------------------------------------
             * 7. IMAGEN
             * -------------------------------------------------
             */

            $imagen =
                $wc_data['imagen'];

            if (!$imagen) {

                $imagen =
                    cdpc_imagen_json(
                        $producto_json,
                        $sku_real
                    );
            }


            /*
             * -------------------------------------------------
             * 8. FICHA COMPATIBILIDAD
             * -------------------------------------------------
             */

            $ficha =
                cdpc_ficha_compatibilidad(
                    $producto_json
                );


            /*
             * -------------------------------------------------
             * 9. RESULTADO
             * -------------------------------------------------
             */

            $resultado[] = array(

                'id' =>
                    $wc_data['id'],

                'sku' =>
                    $sku_real,

                'nombre' =>
                    !empty(
                        $producto_json['nombre']
                    )
                    ? $producto_json['nombre']
                    : $wc_data['nombre'],

                'marca' =>
                    isset(
                        $producto_json['marca']
                    )
                    ? $producto_json['marca']
                    : '',

                'modelo' =>
                    isset(
                        $producto_json['modelo']
                    )
                    ? $producto_json['modelo']
                    : '',

                'numParte' =>
                    isset(
                        $producto_json['numParte']
                    )
                    ? $producto_json['numParte']
                    : '',

                'categoria' =>
                    isset(
                        $producto_json['categoria']
                    )
                    ? $producto_json['categoria']
                    : '',

                'subcategoria' =>
                    isset(
                        $producto_json['subcategoria']
                    )
                    ? $producto_json['subcategoria']
                    : '',

                'existencia' =>
                    cdpc_existencia_json(
                        $producto_json
                    ),

                'precio' =>
                    $precio,

                'imagen' =>
                    $imagen,

                'url' =>
                    !empty($wc_data['producto'])
                    ? $wc_data['producto']->get_permalink()
                    : get_permalink($wc_data['id']),

                'ficha' =>
                    $ficha,

                'json' =>
                    $producto_json
            );
        }


        /*
         * Ordenar por precio ascendente.
         */

        usort(
            $resultado,
            function($a, $b) {

                return
                    $a['precio'] <=>
                    $b['precio'];
            }
        );


        return $resultado;
    }


    /* =========================================================
     * HTML PRODUCTO
     * ========================================================= */

    function cdpc_html_producto(
        $producto,
        $component_key
    ) {

        $id =
            intval($producto['id']);

        $sku =
            esc_attr(
                $producto['sku']
            );

        $nombre =
            esc_html(
                $producto['nombre']
            );

        $marca =
            esc_html(
                $producto['marca']
            );

        $modelo =
            esc_html(
                $producto['modelo']
            );

        $precio =
            number_format(
                floatval(
                    $producto['precio']
                ),
                2,
                '.',
                ','
            );

        $imagen =
            !empty($producto['imagen'])
            ? esc_url($producto['imagen'])
            : wc_placeholder_img_src();

        $url_producto =
            !empty($producto['url'])
            ? esc_url($producto['url'])
            : '';

        $ficha =
            isset($producto['ficha'])
            ? $producto['ficha']
            : array();


        /*
         * La compatibilidad inicial se evalúa en JS
         * contra los productos seleccionados.
         *
         * Empezamos en gris.
         */

        $texto_ficha =
            !empty($ficha['texto'])
            ? esc_attr(
                mb_substr(
                    $ficha['texto'],
                    0,
                    400
                )
            )
            : '';


        return '
        <div
            class="cdpc-producto"
            data-component="' . esc_attr($component_key) . '"
            data-product-id="' . $id . '"
            data-sku="' . $sku . '"
            data-nombre="' . $nombre . '"
            data-precio="' . esc_attr($producto['precio']) . '"
            data-socket="' . esc_attr($ficha['socket'] ?? '') . '"
            data-ddr="' . esc_attr($ficha['ddr'] ?? '') . '"
            data-form-factor="' . esc_attr($ficha['form_factor'] ?? '') . '"
            data-nvme="' . (!empty($ficha['nvme']) ? '1' : '0') . '"
            data-m2="' . (!empty($ficha['m2']) ? '1' : '0') . '"
            data-sata="' . (!empty($ficha['sata']) ? '1' : '0') . '"
            data-pcie="' . (!empty($ficha['pcie']) ? '1' : '0') . '"
            data-watts="' . esc_attr($ficha['watts'] ?? 0) . '"
            data-ficha="' . $texto_ficha . '"
        >

            <div class="cdpc-producto-imagen-wrap">
                <img
                    class="cdpc-producto-imagen"
                    src="' . $imagen . '"
                    alt="' . $nombre . '"
                    loading="lazy"
                    onerror="this.onerror=null;this.src=\'' . esc_url(wc_placeholder_img_src()) . '\';"
                >

                <div class="cdpc-compat-badge">
                    <span class="cdpc-compat-dot"></span>
                    <span class="cdpc-compat-text">
                        Sin evaluar
                    </span>
                </div>
            </div>

            <div class="cdpc-producto-contenido">

                ' . (
                    $marca !== ''
                    ? '<div class="cdpc-producto-marca">' . $marca . '</div>'
                    : ''
                ) . '

                <div class="cdpc-producto-nombre">
                    ' . $nombre . '
                </div>

                ' . (
                    $modelo !== ''
                    ? '<div class="cdpc-producto-modelo">
                        Modelo: ' . $modelo . '
                    </div>'
                    : ''
                ) . '

                <div class="cdpc-producto-sku">
                    SKU: ' . $sku . '
                </div>

                ' . (
                    $url_producto !== ''
                    ? '<a class="cdpc-ver-producto" href="' . $url_producto . '" target="_blank" rel="noopener">
                        Ver producto
                    </a>'
                    : ''
                ) . '

                <div class="cdpc-producto-pie">

                    <div class="cdpc-producto-precio">
                        $' . $precio . '
                    </div>

                    <label class="cdpc-check-wrap">

                        <input
                            type="checkbox"
                            class="cdpc-producto-check"
                            value="' . $id . '"
                            data-component="' . esc_attr($component_key) . '"
                        >

                        <span class="cdpc-check"></span>

                        <span class="cdpc-check-text">
                            Seleccionar
                        </span>

                    </label>

                </div>

            </div>

        </div>';
    }


    /* =========================================================
     * HTML COMPONENTE
     * ========================================================= */

    function cdpc_html_componente(
        $component_key,
        $config,
        $productos
    ) {

        $titulo =
            esc_html(
                $config['titulo']
            );

        $icono =
            $config['icono'];

        $color =
            esc_attr(
                $config['color']
            );

        $cantidad =
            count($productos);


        $html = '
        <section
            class="cdpc-componente"
            data-component="' .
            esc_attr($component_key) .
            '"
        >

            <button
                type="button"
                class="cdpc-componente-header"
                style="--cdpc-color:' . $color . ';"
                data-target="' .
                esc_attr($component_key) .
            '"
            >

                <span class="cdpc-componente-icono">
                    ' . $icono . '
                </span>

                <span class="cdpc-componente-titulo">
                    ' . $titulo . '
                    <small>
                        ' . $cantidad . ' opciones
                    </small>
                </span>

                <span class="cdpc-componente-status">
                    <span class="cdpc-status-dot"></span>
                    <span class="cdpc-status-text">
                        Sin seleccionar
                    </span>
                </span>

                <span class="cdpc-componente-flecha">
                    ▼
                </span>

            </button>

            <div
                class="cdpc-componente-body"
                id="cdpc-body-' .
                esc_attr($component_key) .
            '"
            >

                <div class="cdpc-productos-grid">';


        if (!$productos) {

            $html .= '
                    <div class="cdpc-sin-productos">
                        <div class="cdpc-sin-productos-icon">
                            ⚠️
                        </div>

                        <strong>
                            Sin productos disponibles
                        </strong>

                        <span>
                            No se encontraron productos
                            disponibles en CT Online que
                            también existan en WooCommerce.
                        </span>
                    </div>';

        }
        else {

            $indice_producto = 0;

            foreach ($productos as $producto) {

                $producto_html =
                    cdpc_html_producto(
                        $producto,
                        $component_key
                    );

                if ($indice_producto >= 8) {
                    $producto_html =
                        str_replace(
                            'class="cdpc-producto"',
                            'class="cdpc-producto cdpc-extra-producto"',
                            $producto_html,
                            $reemplazos
                        );
                }

                $html .= $producto_html;

                $indice_producto++;
            }
        }


        /*
         * Mostrar inicialmente solo 8 productos para que cada
         * componente no ocupe demasiado espacio. Los demás se
         * pueden desplegar con "Ver más productos".
         */
        $total_productos = count($productos);

        if ($total_productos > 8) {

            $html .= '
                <button
                    type="button"
                    class="cdpc-ver-mas-productos"
                    data-component="' . esc_attr($component_key) . '"
                    data-visible-count="8"
                    aria-expanded="false"
                >
                    <span class="cdpc-ver-mas-texto">
                        Ver más productos
                    </span>
                    <span class="cdpc-ver-mas-flecha">▼</span>
                </button>';
        }


        $html .= '
                </div>
            </div>
        </section>';

        return $html;
    }


    /* =========================================================
     * CSS
     * ========================================================= */

    function cdpc_css() {

        return <<<'CSS'

<style>

.cdpc-wrap{
    width:100%;
    max-width:1500px;
    margin:20px auto;
    padding:15px;
    box-sizing:border-box;
    font-family:inherit;
}

.cdpc-layout{
    display:grid;
    grid-template-columns:minmax(0,1fr) 360px;
    gap:20px;
    align-items:start;
}

.cdpc-main{
    min-width:0;
}

.cdpc-sidebar{
    position:sticky;
    top:20px;
}

.cdpc-componente{
    margin-bottom:16px;
    border:1px solid #e5e7eb;
    border-radius:14px;
    background:#fff;
    overflow:hidden;
    box-shadow:0 3px 12px rgba(0,0,0,.06);
}

.cdpc-componente-header{
    width:100%;
    min-height:68px;
    border:0;
    padding:12px 16px;
    display:flex;
    align-items:center;
    gap:12px;
    cursor:pointer;
    background:linear-gradient(
        135deg,
        color-mix(
            in srgb,
            var(--cdpc-color) 12%,
            white
        ),
        white
    );
    text-align:left;
    color:#111827;
}

.cdpc-componente-icono{
    width:42px;
    height:42px;
    border-radius:11px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:var(--cdpc-color);
    color:#fff;
    font-size:22px;
    flex:none;
}

.cdpc-componente-titulo{
    flex:1;
    font-size:17px;
    font-weight:800;
}

.cdpc-componente-titulo small{
    display:block;
    margin-top:3px;
    font-size:12px;
    font-weight:500;
    color:#6b7280;
}

.cdpc-componente-status{
    display:flex;
    align-items:center;
    gap:7px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}

.cdpc-status-dot{
    width:10px;
    height:10px;
    border-radius:50%;
    background:#9ca3af;
    box-shadow:0 0 0 3px rgba(156,163,175,.15);
}

.cdpc-componente-flecha{
    font-size:13px;
    transition:transform .2s ease;
}

.cdpc-componente.abierto .cdpc-componente-flecha{
    transform:rotate(180deg);
}

.cdpc-componente-body{
    display:none;
    padding:15px;
    border-top:1px solid #e5e7eb;
}

.cdpc-componente.abierto .cdpc-componente-body{
    display:block;
}

.cdpc-productos-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
}

.cdpc-producto{
    position:relative;
    min-width:0;
    border:1px solid #e5e7eb;
    border-radius:12px;
    background:#fff;
    overflow:hidden;
    transition:
        transform .15s ease,
        box-shadow .15s ease,
        border-color .15s ease;
}

.cdpc-producto:hover{
    transform:translateY(-2px);
    box-shadow:0 7px 20px rgba(0,0,0,.10);
}

.cdpc-producto.cdpc-compatible{
    border-color:#22c55e;
}

.cdpc-producto.cdpc-warning{
    border-color:#eab308;
}

.cdpc-producto.cdpc-incompatible{
    border-color:#ef4444;
    opacity:.72;
}

.cdpc-producto.cdpc-incompatible
.cdpc-producto-check{
    cursor:not-allowed;
}

.cdpc-producto-imagen-wrap{
    position:relative;
    height:175px;
    background:#f8fafc;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
}

.cdpc-producto-imagen{
    width:100%;
    height:100%;
    object-fit:contain;
    padding:10px;
    box-sizing:border-box;
}

.cdpc-compat-badge{
    position:absolute;
    left:8px;
    top:8px;
    display:flex;
    align-items:center;
    gap:5px;
    padding:5px 7px;
    border-radius:20px;
    background:rgba(255,255,255,.94);
    box-shadow:0 2px 8px rgba(0,0,0,.10);
    font-size:10px;
    font-weight:700;
}

.cdpc-compat-dot{
    width:8px;
    height:8px;
    border-radius:50%;
    background:#9ca3af;
}

.cdpc-producto-contenido{
    padding:11px;
}

.cdpc-producto-marca{
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    color:#6b7280;
    margin-bottom:4px;
}

.cdpc-producto-nombre{
    font-size:13px;
    line-height:1.35;
    font-weight:700;
    color:#111827;
    min-height:35px;
}

.cdpc-producto-modelo{
    margin-top:5px;
    color:#6b7280;
    font-size:11px;
}

.cdpc-producto-sku{
    margin-top:5px;
    font-size:10px;
    color:#9ca3af;
    word-break:break-all;
}

.cdpc-ver-producto{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:100%;
    box-sizing:border-box;
    margin-top:9px;
    padding:8px 10px;
    border:1px solid #dbeafe;
    border-radius:8px;
    background:#eff6ff;
    color:#1d4ed8;
    text-decoration:none;
    font-size:11px;
    font-weight:800;
    transition:all .15s ease;
}

.cdpc-ver-producto:hover{
    background:#dbeafe;
    border-color:#93c5fd;
    color:#1e40af;
}

.cdpc-ver-mas-productos{
    grid-column:1/-1;
    width:100%;
    margin-top:4px;
    padding:12px 16px;
    border:1px solid #dbeafe;
    border-radius:10px;
    background:#f8fbff;
    color:#1d4ed8;
    font-size:13px;
    font-weight:900;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    transition:all .15s ease;
}

.cdpc-ver-mas-productos:hover{
    background:#eff6ff;
    border-color:#93c5fd;
}

.cdpc-ver-mas-flecha{
    font-size:11px;
    transition:transform .2s ease;
}

.cdpc-ver-mas-productos.abierto .cdpc-ver-mas-flecha{
    transform:rotate(180deg);
}

.cdpc-producto.cdpc-extra-producto{
    display:none;
}

.cdpc-producto.cdpc-extra-producto.cdpc-mostrado{
    display:block;
}

.cdpc-producto-pie{
    margin-top:11px;
    padding-top:9px;
    border-top:1px solid #f1f5f9;
}

.cdpc-producto-precio{
    font-size:18px;
    font-weight:900;
    color:#111827;
    margin-bottom:9px;
}

.cdpc-check-wrap{
    display:flex;
    align-items:center;
    gap:7px;
    cursor:pointer;
    user-select:none;
    font-size:12px;
    font-weight:700;
}

.cdpc-producto-check{
    position:absolute;
    opacity:0;
    pointer-events:none;
}

.cdpc-check{
    width:19px;
    height:19px;
    border:2px solid #cbd5e1;
    border-radius:6px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#fff;
    flex:none;
}

.cdpc-producto-check:checked + .cdpc-check{
    background:#2563eb;
    border-color:#2563eb;
}

.cdpc-producto-check:checked + .cdpc-check:after{
    content:"✓";
    color:#fff;
    font-size:13px;
    font-weight:900;
}

.cdpc-summary{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:15px;
    box-shadow:0 5px 18px rgba(0,0,0,.08);
    overflow:hidden;
}

.cdpc-summary-header{
    padding:17px;
    background:#111827;
    color:#fff;
}

.cdpc-summary-title{
    font-size:18px;
    font-weight:900;
}

.cdpc-summary-subtitle{
    margin-top:4px;
    color:#cbd5e1;
    font-size:12px;
}

.cdpc-summary-items{
    padding:12px;
}

.cdpc-summary-item{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:10px 5px;
    border-bottom:1px solid #f1f5f9;
}

.cdpc-summary-item:last-child{
    border-bottom:0;
}

.cdpc-summary-item-name{
    font-size:12px;
    font-weight:800;
}

.cdpc-summary-item-product{
    margin-top:2px;
    font-size:10px;
    color:#6b7280;
}

.cdpc-summary-item-price{
    font-size:12px;
    font-weight:800;
    white-space:nowrap;
}

.cdpc-summary-total{
    padding:15px;
    border-top:1px solid #e5e7eb;
    background:#f8fafc;
}

.cdpc-summary-total-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
}

.cdpc-summary-total-label{
    font-weight:800;
}

.cdpc-summary-total-value{
    font-size:22px;
    font-weight:900;
}

.cdpc-add-cart{
    width:100%;
    margin-top:12px;
    border:0;
    border-radius:10px;
    padding:13px 15px;
    background:#16a34a;
    color:#fff;
    font-size:14px;
    font-weight:900;
    cursor:pointer;
}

.cdpc-add-cart:hover{
    background:#15803d;
}

.cdpc-add-cart:disabled{
    background:#9ca3af;
    cursor:not-allowed;
}

.cdpc-message{
    margin:0 15px 15px;
    padding:10px;
    border-radius:9px;
    background:#fef2f2;
    color:#b91c1c;
    font-size:11px;
    font-weight:700;
    display:none;
}

.cdpc-sin-productos{
    grid-column:1/-1;
    min-height:150px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    text-align:center;
    gap:7px;
    color:#6b7280;
}

.cdpc-sin-productos-icon{
    font-size:28px;
}

.cdpc-sin-productos strong{
    color:#374151;
}

.cdpc-sin-productos span{
    max-width:500px;
    font-size:12px;
}

@media(max-width:1200px){

    .cdpc-productos-grid{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
}

@media(max-width:950px){

    .cdpc-layout{
        grid-template-columns:1fr;
    }

    .cdpc-sidebar{
        position:static;
    }
}

@media(max-width:850px){

    .cdpc-productos-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}

@media(max-width:480px){

    .cdpc-wrap{
        margin:10px auto;
        padding:8px;
    }

    .cdpc-productos-grid{
        grid-template-columns:1fr;
    }

    .cdpc-componente-status .cdpc-status-text{
        display:none;
    }

    .cdpc-componente-header{
        padding:10px;
    }

    .cdpc-componente-icono{
        width:38px;
        height:38px;
    }
}

</style>

CSS;
    }


    /* =========================================================
     * JAVASCRIPT
     * ========================================================= */

    function cdpc_js() {

        return <<<'JS'

<script>

(function(){

    'use strict';


    /* ========================================================
     * ESTADO
     * ======================================================== */

    const selected = {};


    /* ========================================================
     * COMPONENTES
     * ======================================================== */

    const components = [
        'gpu',
        'cpu',
        'motherboard',
        'ram',
        'storage',
        'psu',
        'case',
        'cooling',
        'readers'
    ];


    /* ========================================================
     * NORMALIZAR
     * ======================================================== */

    function norm(value){

        if(!value){
            return '';
        }

        return String(value)
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g,'')
            .trim();
    }


    /* ========================================================
     * NÚMERO
     * ======================================================== */

    function num(value){

        const n =
            parseFloat(value || 0);

        return isNaN(n)
            ? 0
            : n;
    }


    /* ========================================================
     * OBTENER DATOS DEL PRODUCTO
     * ======================================================== */

    function productData(el){

        return {

            id:
                el.dataset.productId || '',

            component:
                el.dataset.component || '',

            name:
                el.dataset.nombre || '',

            price:
                num(el.dataset.precio),

            socket:
                norm(el.dataset.socket),

            ddr:
                norm(el.dataset.ddr),

            formFactor:
                norm(el.dataset.formFactor),

            nvme:
                el.dataset.nvme === '1',

            m2:
                el.dataset.m2 === '1',

            sata:
                el.dataset.sata === '1',

            pcie:
                el.dataset.pcie === '1',

            watts:
                num(el.dataset.watts),

            ficha:
                norm(el.dataset.ficha || '')
        };
    }


    /* ========================================================
     * COMPATIBILIDAD
     *
     * Retorna:
     *
     * green = confirmado compatible
     * yellow = advertencia / falta información
     * red = confirmado incompatible
     * gray = sin evaluar
     * ======================================================== */

    function compatibility(product){

        const others =
            Object.values(selected)
                .filter(Boolean);


        if(!others.length){
            return 'gray';
        }


        let result = 'gray';


        others.forEach(function(other){

            if(
                !other ||
                other.component === product.component
            ){
                return;
            }


            /* ==================================================
             * CPU ↔ MOTHERBOARD
             * ================================================== */

            if(
                (
                    product.component === 'cpu' &&
                    other.component === 'motherboard'
                ) ||
                (
                    product.component === 'motherboard' &&
                    other.component === 'cpu'
                )
            ){

                if(
                    product.socket &&
                    other.socket
                ){

                    if(
                        product.socket !==
                        other.socket
                    ){

                        result = 'red';
                        return;
                    }

                    result =
                        result === 'red'
                        ? 'red'
                        : 'green';

                }
                else {

                    if(result !== 'red'){
                        result = 'yellow';
                    }
                }
            }


            /* ==================================================
             * MOTHERBOARD ↔ RAM
             * ================================================== */

            if(
                (
                    product.component === 'motherboard' &&
                    other.component === 'ram'
                ) ||
                (
                    product.component === 'ram' &&
                    other.component === 'motherboard'
                )
            ){

                if(
                    product.ddr &&
                    other.ddr
                ){

                    if(
                        product.ddr !==
                        other.ddr
                    ){

                        result = 'red';
                        return;
                    }

                    result =
                        result === 'red'
                        ? 'red'
                        : 'green';

                }
                else {

                    if(result !== 'red'){
                        result = 'yellow';
                    }
                }
            }


            /* ==================================================
             * MOTHERBOARD ↔ STORAGE
             * ================================================== */

            if(
                (
                    product.component === 'motherboard' &&
                    other.component === 'storage'
                ) ||
                (
                    product.component === 'storage' &&
                    other.component === 'motherboard'
                )
            ){

                const boardSupports =
                    product.nvme ||
                    product.m2 ||
                    product.sata ||
                    product.pcie;

                const storageType =
                    other.nvme ||
                    other.m2 ||
                    other.sata ||
                    other.pcie;

                if(
                    boardSupports &&
                    storageType
                ){

                    /*
                     * Si es NVMe/M.2, necesitamos
                     * soporte M.2/PCIe/NVMe.
                     */
                    if(
                        (
                            other.nvme ||
                            other.m2
                        ) &&
                        !(
                            product.nvme ||
                            product.m2 ||
                            product.pcie
                        )
                    ){

                        result = 'red';
                        return;
                    }

                    /*
                     * SATA necesita SATA.
                     */
                    if(
                        other.sata &&
                        !(
                            product.sata ||
                            product.m2
                        )
                    ){

                        result = 'red';
                        return;
                    }

                    result =
                        result === 'red'
                        ? 'red'
                        : 'green';

                }
                else {

                    if(result !== 'red'){
                        result = 'yellow';
                    }
                }
            }


            /* ==================================================
             * CPU ↔ COOLING
             * ================================================== */

            if(
                (
                    product.component === 'cpu' &&
                    other.component === 'cooling'
                ) ||
                (
                    product.component === 'cooling' &&
                    other.component === 'cpu'
                )
            ){

                if(
                    product.socket &&
                    other.socket
                ){

                    if(
                        product.socket !==
                        other.socket
                    ){

                        result = 'red';
                        return;
                    }

                    result =
                        result === 'red'
                        ? 'red'
                        : 'green';

                }
                else {

                    if(result !== 'red'){
                        result = 'yellow';
                    }
                }
            }


            /* ==================================================
             * MOTHERBOARD ↔ CASE
             * ================================================== */

            if(
                (
                    product.component === 'motherboard' &&
                    other.component === 'case'
                ) ||
                (
                    product.component === 'case' &&
                    other.component === 'motherboard'
                )
            ){

                if(
                    product.formFactor &&
                    other.formFactor
                ){

                    const board =
                        product.component === 'motherboard'
                        ? product
                        : other;

                    const gabinete =
                        product.component === 'case'
                        ? product
                        : other;

                    const bf =
                        board.formFactor;

                    const gf =
                        gabinete.formFactor;


                    /*
                     * ATX case acepta ATX,
                     * mATX e ITX.
                     */

                    if(
                        gf.includes('atx')
                    ){

                        if(
                            bf.includes('atx') ||
                            bf.includes('matx') ||
                            bf.includes('itx')
                        ){

                            result =
                                result === 'red'
                                ? 'red'
                                : 'green';

                        }
                        else {

                            result = 'red';
                            return;
                        }

                    }
                    else if(
                        gf.includes('matx') ||
                        gf.includes('microatx')
                    ){

                        if(
                            bf.includes('matx') ||
                            bf.includes('itx')
                        ){

                            result =
                                result === 'red'
                                ? 'red'
                                : 'green';

                        }
                        else {

                            result = 'red';
                            return;
                        }

                    }
                    else {

                        if(result !== 'red'){
                            result = 'yellow';
                        }
                    }

                }
                else {

                    if(result !== 'red'){
                        result = 'yellow';
                    }
                }
            }


            /* ==================================================
             * GPU ↔ PSU
             * ================================================== */

            if(
                (
                    product.component === 'gpu' &&
                    other.component === 'psu'
                ) ||
                (
                    product.component === 'psu' &&
                    other.component === 'gpu'
                )
            ){

                const gpu =
                    product.component === 'gpu'
                    ? product
                    : other;

                const psu =
                    product.component === 'psu'
                    ? product
                    : other;


                if(
                    gpu.watts &&
                    psu.watts
                ){

                    if(
                        psu.watts <
                        gpu.watts
                    ){

                        result = 'red';
                        return;
                    }

                    result =
                        result === 'red'
                        ? 'red'
                        : 'green';

                }
                else {

                    if(result !== 'red'){
                        result = 'yellow';
                    }
                }
            }


            /* ==================================================
             * GPU ↔ MOTHERBOARD
             *
             * No marcamos incompatibilidad.
             * ================================================== */

            if(
                (
                    product.component === 'gpu' &&
                    other.component === 'motherboard'
                ) ||
                (
                    product.component === 'motherboard' &&
                    other.component === 'gpu'
                )
            ){

                if(result !== 'red'){
                    result = 'yellow';
                }
            }


            /* ==================================================
             * GPU ↔ CASE
             *
             * Sin longitud confiable:
             * advertencia, nunca rojo.
             * ================================================== */

            if(
                (
                    product.component === 'gpu' &&
                    other.component === 'case'
                ) ||
                (
                    product.component === 'case' &&
                    other.component === 'gpu'
                )
            ){

                if(result !== 'red'){
                    result = 'yellow';
                }
            }

        });


        return result;
    }


    /* ========================================================
     * APLICAR ESTADO A PRODUCTO
     * ======================================================== */

    function applyProductState(el){

        const data =
            productData(el);

        const state =
            compatibility(data);


        el.classList.remove(
            'cdpc-compatible',
            'cdpc-warning',
            'cdpc-incompatible'
        );


        const badge =
            el.querySelector(
                '.cdpc-compat-badge'
            );

        const dot =
            el.querySelector(
                '.cdpc-compat-dot'
            );

        const text =
            el.querySelector(
                '.cdpc-compat-text'
            );

        const checkbox =
            el.querySelector(
                '.cdpc-producto-check'
            );


        if(state === 'green'){

            el.classList.add(
                'cdpc-compatible'
            );

            if(dot){
                dot.style.background =
                    '#22c55e';
            }

            if(text){
                text.textContent =
                    'Compatible';
            }

            if(checkbox){
                checkbox.disabled =
                    false;
            }

        }
        else if(state === 'yellow'){

            el.classList.add(
                'cdpc-warning'
            );

            if(dot){
                dot.style.background =
                    '#eab308';
            }

            if(text){
                text.textContent =
                    'Revisar';
            }

            if(checkbox){
                checkbox.disabled =
                    false;
            }

        }
        else if(state === 'red'){

            el.classList.add(
                'cdpc-incompatible'
            );

            if(dot){
                dot.style.background =
                    '#ef4444';
            }

            if(text){
                text.textContent =
                    'Incompatible';
            }

            /*
             * Si ya estaba seleccionado,
             * permitimos deseleccionarlo.
             */
            if(checkbox){

                checkbox.disabled =
                    !checkbox.checked;
            }

        }
        else {

            if(dot){
                dot.style.background =
                    '#9ca3af';
            }

            if(text){
                text.textContent =
                    'Sin evaluar';
            }

            if(checkbox){
                checkbox.disabled =
                    false;
            }
        }
    }


    /* ========================================================
     * APLICAR TODOS
     * ======================================================== */

    function refreshAll(){

        document
            .querySelectorAll(
                '.cdpc-producto'
            )
            .forEach(function(el){

                applyProductState(el);
            });




        updateHeaders();
        updateSummary();
        updateCartButton();
    }


    /* ========================================================
     * ESTADO DEL HEADER
     * ======================================================== */

    function updateHeaders(){

        components.forEach(function(component){

            const section =
                document.querySelector(
                    '.cdpc-componente[data-component="' +
                    component +
                    '"]'
                );

            if(!section){
                return;
            }


            const dot =
                section.querySelector(
                    '.cdpc-status-dot'
                );

            const text =
                section.querySelector(
                    '.cdpc-status-text'
                );

            const productos =
                section.querySelectorAll(
                    '.cdpc-producto'
                );


            const seleccionado =
                selected[component];


            let estado =
                'gray';

            let mensaje =
                'Sin seleccionar';


            if(seleccionado){

                estado =
                    compatibility(
                        seleccionado
                    );

                if(estado === 'green'){
                    mensaje =
                        'Seleccionada';
                }
                else if(estado === 'yellow'){
                    mensaje =
                        'Revisar compatibilidad';
                }
                else if(estado === 'red'){
                    mensaje =
                        'Incompatible';
                }

            }
            else {

                /*
                 * Revisar si existen opciones
                 * compatibles con lo seleccionado.
                 */

                let tieneGreen = false;
                let tieneYellow = false;

                productos.forEach(function(el){

                    const estadoProducto =
                        compatibility(
                            productData(el)
                        );

                    if(
                        estadoProducto === 'green'
                    ){
                        tieneGreen = true;
                    }

                    if(
                        estadoProducto === 'yellow'
                    ){
                        tieneYellow = true;
                    }
                });


                if(!productos.length){

                    estado = 'red';
                    mensaje = 'Sin productos';

                }
                else if(tieneGreen){

                    estado = 'yellow';
                    mensaje =
                        'Hay opciones compatibles';

                }
                else if(tieneYellow){

                    estado = 'yellow';
                    mensaje =
                        'Revisar opciones';

                }
            }


            if(dot){

                if(estado === 'green'){
                    dot.style.background =
                        '#22c55e';
                }
                else if(estado === 'yellow'){
                    dot.style.background =
                        '#eab308';
                }
                else if(estado === 'red'){
                    dot.style.background =
                        '#ef4444';
                }
                else {
                    dot.style.background =
                        '#9ca3af';
                }
            }


            if(text){
                text.textContent =
                    mensaje;
            }

        });
    }


    /* ========================================================
     * RESUMEN
     * ======================================================== */

    function updateSummary(){

        const container =
            document.querySelector(
                '.cdpc-summary-items'
            );

        const totalEl =
            document.querySelector(
                '.cdpc-summary-total-value'
            );


        if(!container){
            return;
        }


        container.innerHTML = '';

        let total = 0;
        let count = 0;


        components.forEach(function(component){

            const product =
                selected[component];

            if(!product){
                return;
            }


            count++;

            total +=
                num(product.price);


            const row =
                document.createElement('div');

            row.className =
                'cdpc-summary-item';

            row.innerHTML =

                '<div>' +

                    '<div class="cdpc-summary-item-name">' +
                        escapeHtml(
                            getComponentName(component)
                        ) +
                    '</div>' +

                    '<div class="cdpc-summary-item-product">' +
                        escapeHtml(
                            product.name
                        ) +
                    '</div>' +

                '</div>' +

                '<div class="cdpc-summary-item-price">' +
                    money(product.price) +
                '</div>';


            container.appendChild(row);

        });


        if(!count){

            const empty =
                document.createElement('div');

            empty.className =
                'cdpc-summary-item';

            empty.innerHTML =
                '<div class="cdpc-summary-item-product">' +
                'Aún no has seleccionado componentes.' +
                '</div>';

            container.appendChild(empty);
        }


        if(totalEl){

            totalEl.textContent =
                money(total);
        }
    }


    /* ========================================================
     * NOMBRE COMPONENTE
     * ======================================================== */

    function getComponentName(component){

        const section =
            document.querySelector(
                '.cdpc-componente[data-component="' +
                component +
                '"]'
            );

        if(!section){
            return component;
        }

        const title =
            section.querySelector(
                '.cdpc-componente-titulo'
            );

        if(!title){
            return component;
        }

        return title.childNodes[0]
            ? title.childNodes[0].textContent.trim()
            : component;
    }


    /* ========================================================
     * DINERO
     * ======================================================== */

    function money(value){

        return '$' +
            num(value).toLocaleString(
                'es-MX',
                {
                    minimumFractionDigits:2,
                    maximumFractionDigits:2
                }
            );
    }


    /* ========================================================
     * ESCAPAR HTML
     * ======================================================== */

    function escapeHtml(value){

        return String(value || '')
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    }


    /* ========================================================
     * BOTÓN CARRITO
     * ======================================================== */

    function updateCartButton(){

        const button =
            document.querySelector(
                '.cdpc-add-cart'
            );

        if(!button){
            return;
        }


        let hasRed = false;
        let count = 0;


        Object.values(selected)
            .filter(Boolean)
            .forEach(function(product){

                count++;

                if(
                    compatibility(product) ===
                    'red'
                ){

                    hasRed = true;
                }
            });


        button.disabled =
            count === 0 ||
            hasRed;


        if(hasRed){

            button.textContent =
                'Hay componentes incompatibles';

        }
        else if(count === 0){

            button.textContent =
                'Selecciona los componentes';

        }
        else {

            button.textContent =
                'Agregar configuración al carrito';
        }
    }


    /* ========================================================
     * CAMBIO CHECKBOX
     * ======================================================== */

    function handleCheck(checkbox){

        const el =
            checkbox.closest(
                '.cdpc-producto'
            );

        if(!el){
            return;
        }


        const data =
            productData(el);

        const component =
            data.component;


        if(checkbox.checked){

            /*
             * Solo un producto por componente.
             */

            const anterior =
                selected[component];


            if(anterior){

                const anteriores =
                    document.querySelectorAll(
                        '.cdpc-producto[data-component="' +
                        component +
                        '"]'
                    );

                anteriores.forEach(function(item){

                    const c =
                        item.querySelector(
                            '.cdpc-producto-check'
                        );

                    if(c && c !== checkbox){
                        c.checked = false;
                    }
                });
            }


            selected[component] =
                data;

        }
        else {

            if(
                selected[component] &&
                selected[component].id ===
                data.id
            ){

                delete selected[component];
            }
        }


        refreshAll();
    }


    /* ========================================================
     * ACORDEONES
     * ======================================================== */

    document.addEventListener(
        'click',
        function(e){

            const header =
                e.target.closest(
                    '.cdpc-componente-header'
                );

            if(!header){
                return;
            }


            const section =
                header.closest(
                    '.cdpc-componente'
                );

            if(!section){
                return;
            }


            section.classList.toggle(
                'abierto'
            );
        }
    );


    /* ========================================================
     * VER MÁS PRODUCTOS
     * ======================================================== */

    document.addEventListener(
        'click',
        function(e){

            const button =
                e.target.closest(
                    '.cdpc-ver-mas-productos'
                );

            if(!button){
                return;
            }

            const section =
                button.closest(
                    '.cdpc-componente'
                );

            if(!section){
                return;
            }

            const extras =
                section.querySelectorAll(
                    '.cdpc-extra-producto'
                );

            const abierto =
                button.classList.contains('abierto');

            extras.forEach(function(producto){

                if(abierto){
                    producto.classList.remove(
                        'cdpc-mostrado'
                    );
                }
                else {
                    producto.classList.add(
                        'cdpc-mostrado'
                    );
                }
            });

            button.classList.toggle(
                'abierto',
                !abierto
            );

            button.setAttribute(
                'aria-expanded',
                !abierto ? 'true' : 'false'
            );

            const texto =
                button.querySelector(
                    '.cdpc-ver-mas-texto'
                );

            if(texto){
                texto.textContent =
                    !abierto
                    ? 'Ver menos productos'
                    : 'Ver más productos';
            }
        }
    );


    /* ========================================================
     * CHECKBOXES
     * ======================================================== */

    document.addEventListener(
        'change',
        function(e){

            if(
                e.target.classList.contains(
                    'cdpc-producto-check'
                )
            ){

                handleCheck(e.target);
            }
        }
    );


    /* ========================================================
     * AGREGAR AL CARRITO
     * ======================================================== */

    document.addEventListener(
        'click',
        function(e){

            const button =
                e.target.closest(
                    '.cdpc-add-cart'
                );

            if(!button){
                return;
            }


            if(button.disabled){
                return;
            }


            const ids = [];

            Object.values(selected)
                .filter(Boolean)
                .forEach(function(product){

                    if(product.id){
                        ids.push(
                            product.id
                        );
                    }
                });


            if(!ids.length){
                return;
            }


            button.disabled = true;
            button.textContent =
                'Agregando...';


            let index = 0;


            function addNext(){

                if(index >= ids.length){

                    button.disabled = false;

                    /*
                     * Recargamos fragmentos WooCommerce
                     * si están disponibles.
                     */

                    if(
                        window.jQuery &&
                        typeof jQuery ===
                        'function'
                    ){

                        jQuery(document.body)
                            .trigger(
                                'wc_fragment_refresh'
                            );
                    }

                    button.textContent =
                        'Configuración agregada';

                    return;
                }


                const productId =
                    ids[index];

                index++;


                const form =
                    new FormData();

                form.append(
                    'action',
                    'woocommerce_add_to_cart'
                );

                form.append(
                    'product_id',
                    productId
                );

                form.append(
                    'quantity',
                    '1'
                );


                fetch(
                    window.cdpc_ajax_url ||
                    '/wp-admin/admin-ajax.php',
                    {
                        method:'POST',
                        credentials:'same-origin',
                        body:form
                    }
                )
                .then(function(){

                    addNext();
                })
                .catch(function(){

                    addNext();
                });
            }


            addNext();
        }
    );


    /* ========================================================
     * INICIALIZACIÓN
     * ======================================================== */

    function init(){

        const first =
            document.querySelector(
                '.cdpc-componente'
            );

        if(first){
            first.classList.add(
                'abierto'
            );
        }


        refreshAll();
    }


    if(
        document.readyState ===
        'loading'
    ){

        document.addEventListener(
            'DOMContentLoaded',
            init
        );

    }
    else {

        init();
    }

})();

</script>

JS;
    }


    /* =========================================================
     * MOSTRAR CONFIGURADOR
     * ========================================================= */

    function cdpc_mostrar_configurador() {

        $config =
            cdpc_componentes_config();


        $html = '';

        $html .=
            cdpc_css();


        $html .= '
        <div class="cdpc-wrap">

            <div class="cdpc-layout">

                <main class="cdpc-main">';


        foreach (
            $config as $component_key => $component_config
        ) {

            $productos =
                cdpc_preparar_productos(
                    $component_key,
                    $component_config
                );


            $html .=
                cdpc_html_componente(
                    $component_key,
                    $component_config,
                    $productos
                );
        }


        $html .= '
                </main>

                <aside class="cdpc-sidebar">

                    <div class="cdpc-summary">

                        <div class="cdpc-summary-header">

                            <div class="cdpc-summary-title">
                                Tu PC Gamer
                            </div>

                            <div class="cdpc-summary-subtitle">
                                Selecciona tus componentes
                            </div>

                        </div>

                        <div class="cdpc-summary-items">
                        </div>

                        <div class="cdpc-summary-total">

                            <div class="cdpc-summary-total-row">

                                <span class="cdpc-summary-total-label">
                                    Total
                                </span>

                                <span class="cdpc-summary-total-value">
                                    $0.00
                                </span>

                            </div>

                            <button
                                type="button"
                                class="cdpc-add-cart"
                                disabled
                            >
                                Selecciona los componentes
                            </button>

                        </div>

                        <div class="cdpc-message">
                        </div>

                    </div>

                </aside>

            </div>

        </div>';


        $html .=
            cdpc_js();


        return $html;
    }


    /* =========================================================
     * SHORTCODE
     *
     * INSERT PHP CODE SNIPPETS:
     * [xyz-ips snippet="configuradorpc"]
     *
     * NO add_shortcode().
     * ========================================================= */

    echo cdpc_mostrar_configurador();

}
/* ============================================================
 * CYBERDEPOT - DIAGNOSTICO CONFIGURADOR PC
 * NO MODIFICA PRODUCTOS NI PRECIOS
 * ============================================================ */

if (!function_exists('cdpc_diagnostico_configurador')) {

    function cdpc_diagnostico_configurador() {

        if (!current_user_can('manage_options')) {
            return '';
        }

        $ruta = cdpc_ruta_json();

        $resultado = array(
            'json'              => '❌',
            'total_json'        => 0,
            'con_existencia'    => 0,
            'categoria_gpu'     => 0,
            'categoria_cpu'     => 0,
            'categoria_mb'      => 0,
            'categoria_ram'     => 0,
            'categoria_storage' => 0,
            'categoria_psu'     => 0,
            'categoria_case'    => 0,
            'categoria_cooling' => 0,
            'categoria_readers' => 0,

            'woo_gpu'     => 0,
            'woo_cpu'     => 0,
            'woo_mb'      => 0,
            'woo_ram'     => 0,
            'woo_storage' => 0,
            'woo_psu'     => 0,
            'woo_case'    => 0,
            'woo_cooling' => 0,
            'woo_readers' => 0,

            'precio_gpu'     => 0,
            'precio_cpu'     => 0,
            'precio_mb'      => 0,
            'precio_ram'     => 0,
            'precio_storage' => 0,
            'precio_psu'     => 0,
            'precio_case'    => 0,
            'precio_cooling' => 0,
            'precio_readers' => 0,
        );

        $html = '';

        /* ----------------------------------------------------
         * LEER JSON
         * ---------------------------------------------------- */

        if (!file_exists($ruta)) {

            return '
            <div style="
                margin:20px auto;
                max-width:1500px;
                padding:20px;
                background:#fee2e2;
                border:2px solid #ef4444;
                border-radius:10px;
                font-family:Arial,sans-serif;
            ">
                <strong>❌ DIAGNÓSTICO CONFIGURADOR</strong><br><br>
                No se encontró el archivo JSON:<br>
                <code>' . esc_html($ruta) . '</code>
            </div>';
        }

        $contenido = file_get_contents($ruta);

        if (!$contenido) {

            return '
            <div style="
                margin:20px auto;
                max-width:1500px;
                padding:20px;
                background:#fee2e2;
                border:2px solid #ef4444;
                border-radius:10px;
                font-family:Arial,sans-serif;
            ">
                <strong>❌ DIAGNÓSTICO CONFIGURADOR</strong><br><br>
                El archivo JSON existe pero no se pudo leer.
            </div>';
        }

        $json = json_decode($contenido, true);

        if (json_last_error() !== JSON_ERROR_NONE) {

            return '
            <div style="
                margin:20px auto;
                max-width:1500px;
                padding:20px;
                background:#fee2e2;
                border:2px solid #ef4444;
                border-radius:10px;
                font-family:Arial,sans-serif;
            ">
                <strong>❌ ERROR JSON</strong><br><br>
                ' . esc_html(json_last_error_msg()) . '
            </div>';
        }

        $resultado['json'] = '✅';

        if (isset($json['productos']) && is_array($json['productos'])) {
            $productos = $json['productos'];
        } elseif (is_array($json)) {
            $productos = $json;
        } else {
            $productos = array();
        }

        $resultado['total_json'] = count($productos);

        /* ----------------------------------------------------
         * CONFIGURACION DE CATEGORIAS
         * ---------------------------------------------------- */

        $componentes = cdpc_componentes_config();

        $mapeo = array(
            'gpu'     => 'categoria_gpu',
            'cpu'     => 'categoria_cpu',
            'mb'      => 'categoria_mb',
            'ram'     => 'categoria_ram',
            'storage' => 'categoria_storage',
            'psu'     => 'categoria_psu',
            'case'    => 'categoria_case',
            'cooling' => 'categoria_cooling',
            'readers' => 'categoria_readers',
        );

        $mapeo_woo = array(
            'gpu'     => 'woo_gpu',
            'cpu'     => 'woo_cpu',
            'mb'      => 'woo_mb',
            'ram'     => 'woo_ram',
            'storage' => 'woo_storage',
            'psu'     => 'woo_psu',
            'case'    => 'woo_case',
            'cooling' => 'woo_cooling',
            'readers' => 'woo_readers',
        );

        $mapeo_precio = array(
            'gpu'     => 'precio_gpu',
            'cpu'     => 'precio_cpu',
            'mb'      => 'precio_mb',
            'ram'     => 'precio_ram',
            'storage' => 'precio_storage',
            'psu'     => 'precio_psu',
            'case'    => 'precio_case',
            'cooling' => 'precio_cooling',
            'readers' => 'precio_readers',
        );

        /* ----------------------------------------------------
         * RECORRER JSON
         * ---------------------------------------------------- */

        foreach ($productos as $p) {

            if (!is_array($p)) {
                continue;
            }

            $existencia = cdpc_existencia_json($p);

            if ($existencia > 0) {
                $resultado['con_existencia']++;
            }

            foreach ($componentes as $key => $config) {

                if (!isset($mapeo[$key])) {
                    continue;
                }

                /*
                 * CATEGORIA
                 */
                if (!cdpc_categoria_coincide($p, $config)) {
                    continue;
                }

                $resultado[$mapeo[$key]]++;

                /*
                 * STOCK
                 */
                if ($existencia <= 0) {
                    continue;
                }

                /*
                 * SKUS
                 */
                $skus = cdpc_obtener_skus($p);

                if (empty($skus)) {
                    continue;
                }

                /*
                 * BUSCAR PRODUCTO REAL EN WOOCOMMERCE
                 */
                $woo = false;
                $sku_real = '';

                foreach ($skus as $sku) {

                    $datos = cdpc_datos_wc($sku);

                    if ($datos && !empty($datos['id'])) {

                        $woo = $datos;
                        $sku_real = $sku;
                        break;
                    }
                }

                if (!$woo) {
                    continue;
                }

                $resultado[$mapeo_woo[$key]]++;

                /*
                 * PRECIO CT
                 */
                $precio = cdpc_precio_ctonline($sku_real, $p);

                if ($precio !== false && is_numeric($precio) && (float)$precio > 0) {
                    $resultado[$mapeo_precio[$key]]++;
                }
            }
        }

        /* ----------------------------------------------------
         * PRODUCTOS DE PRUEBA
         * ---------------------------------------------------- */

        $muestras = array();

        foreach ($productos as $p) {

            if (!is_array($p)) {
                continue;
            }

            if (count($muestras) >= 15) {
                break;
            }

            $existencia = cdpc_existencia_json($p);

            if ($existencia <= 0) {
                continue;
            }

            $skus = cdpc_obtener_skus($p);

            $woo_encontrado = false;
            $sku_real = '';

            foreach ($skus as $sku) {

                $datos = cdpc_datos_wc($sku);

                if ($datos && !empty($datos['id'])) {
                    $woo_encontrado = true;
                    $sku_real = $sku;
                    break;
                }
            }

            $precio = false;

            if ($woo_encontrado) {
                $precio = cdpc_precio_ctonline($sku_real, $p);
            }

            $muestras[] = array(
                'nombre'       => isset($p['nombre']) ? $p['nombre'] : '',
                'categoria'    => isset($p['categoria']) ? $p['categoria'] : '',
                'subcategoria' => isset($p['subcategoria']) ? $p['subcategoria'] : '',
                'clave'        => isset($p['clave']) ? $p['clave'] : '',
                'numParte'     => isset($p['numParte']) ? $p['numParte'] : '',
                'existencia'   => $existencia,
                'woo'          => $woo_encontrado ? '✅' : '❌',
                'sku_real'     => $sku_real,
                'precio'       => ($precio !== false ? $precio : '❌'),
            );
        }

        /* ----------------------------------------------------
         * HTML
         * ---------------------------------------------------- */

        ob_start();

        ?>

        <div id="cdpc-diagnostico" style="
            max-width:1500px;
            margin:25px auto;
            padding:20px;
            background:#111827;
            color:#f9fafb;
            border-radius:12px;
            font-family:Arial,sans-serif;
            box-sizing:border-box;
        ">

            <h2 style="
                margin:0 0 15px 0;
                color:#fff;
            ">
                🔎 Diagnóstico Configurador PC
            </h2>

            <div style="
                background:#1f2937;
                padding:15px;
                border-radius:8px;
                margin-bottom:20px;
            ">

                <div style="margin-bottom:8px;">
                    <strong>JSON:</strong>
                    <?php echo $resultado['json']; ?>
                </div>

                <div style="margin-bottom:8px;">
                    <strong>Total productos JSON:</strong>
                    <?php echo number_format($resultado['total_json']); ?>
                </div>

                <div>
                    <strong>Productos con existencia &gt; 0:</strong>
                    <?php echo number_format($resultado['con_existencia']); ?>
                </div>

            </div>


            <h3 style="color:#fff;">
                1. Coincidencias de categoría
            </h3>

            <table style="
                width:100%;
                border-collapse:collapse;
                margin-bottom:25px;
                background:#1f2937;
            ">

                <thead>
                    <tr>
                        <th style="padding:10px;text-align:left;">Componente</th>
                        <th style="padding:10px;text-align:center;">Categoría / Subcategoría</th>
                        <th style="padding:10px;text-align:center;">WooCommerce</th>
                        <th style="padding:10px;text-align:center;">Precio CT</th>
                    </tr>
                </thead>

                <tbody>

                <?php

                $nombres = array(
                    'gpu'     => '🎮 Tarjeta de Video',
                    'cpu'     => '🧠 Microprocesador',
                    'mb'      => '🧩 Motherboard',
                    'ram'     => '💾 Memoria RAM',
                    'storage' => '💽 Almacenamiento',
                    'psu'     => '🔌 Fuente de Poder',
                    'case'    => '🖥️ Gabinete',
                    'cooling' => '❄️ Enfriamiento',
                    'readers' => '💿 Lectores',
                );

                foreach ($nombres as $key => $nombre):

                    $cat = $resultado[$mapeo[$key]];
                    $woo = $resultado[$mapeo_woo[$key]];
                    $precio = $resultado[$mapeo_precio[$key]];

                    ?>

                    <tr style="border-top:1px solid #374151;">

                        <td style="padding:10px;">
                            <strong><?php echo esc_html($nombre); ?></strong>
                        </td>

                        <td style="
                            padding:10px;
                            text-align:center;
                            font-size:18px;
                        ">
                            <?php echo $cat > 0 ? '✅ ' . number_format($cat) : '❌ 0'; ?>
                        </td>

                        <td style="
                            padding:10px;
                            text-align:center;
                            font-size:18px;
                        ">
                            <?php echo $woo > 0 ? '✅ ' . number_format($woo) : '❌ 0'; ?>
                        </td>

                        <td style="
                            padding:10px;
                            text-align:center;
                            font-size:18px;
                        ">
                            <?php echo $precio > 0 ? '✅ ' . number_format($precio) : '❌ 0'; ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>


            <h3 style="color:#fff;">
                2. Muestras reales del JSON
            </h3>

            <div style="overflow-x:auto;">

                <table style="
                    width:100%;
                    border-collapse:collapse;
                    background:#1f2937;
                    font-size:13px;
                ">

                    <thead>

                        <tr>

                            <th style="padding:8px;text-align:left;">Producto</th>
                            <th style="padding:8px;">Categoría</th>
                            <th style="padding:8px;">Subcategoría</th>
                            <th style="padding:8px;">Clave</th>
                            <th style="padding:8px;">NumParte</th>
                            <th style="padding:8px;">Stock</th>
                            <th style="padding:8px;">Woo</th>
                            <th style="padding:8px;">SKU Woo</th>
                            <th style="padding:8px;">Precio</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($muestras as $m): ?>

                        <tr style="border-top:1px solid #374151;">

                            <td style="padding:8px;">
                                <?php echo esc_html($m['nombre']); ?>
                            </td>

                            <td style="padding:8px;">
                                <?php echo esc_html($m['categoria']); ?>
                            </td>

                            <td style="padding:8px;">
                                <?php echo esc_html($m['subcategoria']); ?>
                            </td>

                            <td style="padding:8px;">
                                <?php echo esc_html($m['clave']); ?>
                            </td>

                            <td style="padding:8px;">
                                <?php echo esc_html($m['numParte']); ?>
                            </td>

                            <td style="padding:8px;text-align:center;">
                                <?php echo esc_html($m['existencia']); ?>
                            </td>

                            <td style="padding:8px;text-align:center;">
                                <?php echo esc_html($m['woo']); ?>
                            </td>

                            <td style="padding:8px;">
                                <?php echo esc_html($m['sku_real']); ?>
                            </td>

                            <td style="padding:8px;">
                                <?php
                                echo is_numeric($m['precio'])
                                    ? '$' . number_format((float)$m['precio'], 2)
                                    : esc_html($m['precio']);
                                ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>


            <div style="
                margin-top:20px;
                padding:15px;
                background:#374151;
                border-radius:8px;
                line-height:1.6;
            ">

                <strong>Cómo interpretar el resultado:</strong><br>

                <span style="color:#86efac;">
                    ✅ Categoría
                </span>
                = el JSON contiene productos que coinciden con la categoría.<br>

                <span style="color:#86efac;">
                    ✅ WooCommerce
                </span>
                = además encontró un producto real mediante SKU.<br>

                <span style="color:#86efac;">
                    ✅ Precio CT
                </span>
                = se obtuvo precio mediante la función de precio ajustado CT Online.<br><br>

                Si aparece algo como:

                <br>
                <strong>
                    Categoría = 1000 / WooCommerce = 0
                </strong>

                entonces el problema está en la búsqueda del SKU en WooCommerce.

                <br>

                Si aparece:

                <br>
                <strong>
                    Categoría = 1000 / WooCommerce = 500 / Precio CT = 0
                </strong>

                entonces el problema está en la función de precio CT.

                <br>

                Si aparece:

                <br>
                <strong>
                    Categoría = 0
                </strong>

                entonces el problema está en el filtro de categorías.

            </div>

        </div>

        <?php

        $html = ob_get_clean();

        return $html;
    }
}


/* ============================================================
 * MOSTRAR DIAGNOSTICO
 * ============================================================ */

if (function_exists('cdpc_mostrar_configurador')) {

    add_action('wp_footer', function () {

        if (is_admin()) {
            return;
        }

        echo cdpc_diagnostico_configurador();

    }, 99);
}