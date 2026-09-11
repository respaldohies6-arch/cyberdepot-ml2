<?php

/*
 * ============================================================
 * CYBERDEPOT
 * MAPEO MERCADO LIBRE — ETAPA 6.7
 *
 * CT → Categoría + Subcategoría
 * → Domain Discovery
 * → TODOS LOS CANDIDATOS
 * → IDs ML
 * → Ruta oficial
 * → Leaf
 * → Atributos
 * → Required
 *
 * PROCESAMIENTO AUTOMÁTICO DE 3 NODOS
 *
 * Insert PHP Code Snippets
 * NO crear shortcode aquí.
 * ============================================================
 */


/* ============================================================
 * CONFIGURACIÓN
 * ============================================================ */

$cd67_batch_size = 3;

$cd67_json_file =
    WP_CONTENT_DIR .
    '/uploads/productos.json';

$cd67_result_file =
    WP_CONTENT_DIR .
    '/uploads/cd67_mapeo_resultados.json';

$cd67_state_key =
    'cd67_mapeo_state';

$cd67_nodes_key =
    'cd67_mapeo_nodes';


/* ============================================================
 * UTILIDADES
 * ============================================================ */

if (!function_exists('cd67_normalize')) {

    function cd67_normalize($text) {

        $text = (string) $text;

        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $text = remove_accents($text);

        $text = strtolower($text);

        $text = preg_replace(
            '/[^a-z0-9]+/',
            ' ',
            $text
        );

        $text = preg_replace(
            '/\s+/',
            ' ',
            $text
        );

        return trim($text);
    }
}


/* ============================================================
 * TOKEN MERCADO LIBRE
 * ============================================================ */

if (!function_exists('cd67_get_token')) {

    function cd67_get_token() {

        $token =
            get_option(
                'meli_access_token',
                ''
            );

        return trim((string)$token);
    }
}


/* ============================================================
 * API MERCADO LIBRE
 * ============================================================ */

if (!function_exists('cd67_api_get')) {

    function cd67_api_get($url) {

        $token =
            cd67_get_token();

        if ($token === '') {

            return array(
                'ok' => false,
                'error' =>
                    'No existe meli_access_token. Conecta Mercado Libre desde el plugin principal.'
            );
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 25,
                'headers' => array(
                    'Authorization' =>
                        'Bearer ' . $token,

                    'Accept' =>
                        'application/json'
                )
            )
        );

        if (is_wp_error($response)) {

            return array(
                'ok' => false,
                'error' =>
                    $response->get_error_message()
            );
        }

        $code =
            wp_remote_retrieve_response_code(
                $response
            );

        $body =
            wp_remote_retrieve_body(
                $response
            );

        if ($code < 200 || $code >= 300) {

            return array(
                'ok' => false,
                'error' =>
                    'HTTP ' . $code,
                'code' => $code
            );
        }

        $data =
            json_decode(
                $body,
                true
            );

        if (!is_array($data)) {

            return array(
                'ok' => false,
                'error' =>
                    'Respuesta JSON inválida'
            );
        }

        return array(
            'ok' => true,
            'data' => $data
        );
    }
}


/* ============================================================
 * DOMAIN DISCOVERY
 * ============================================================ */

if (!function_exists('cd67_domain_discovery')) {

    function cd67_domain_discovery($query) {

        $query =
            trim((string)$query);

        if ($query === '') {
            return array();
        }

        $url =
            'https://api.mercadolibre.com/sites/MLM/domain_discovery/search?' .
            http_build_query(
                array(
                    'q' =>
                        $query,
                    'limit' =>
                        8
                )
            );

        $response =
            cd67_api_get($url);

        if (
            empty($response['ok']) ||
            empty($response['data'])
        ) {
            return array();
        }

        $data =
            $response['data'];

        if (!is_array($data)) {
            return array();
        }

        return $data;
    }
}


/* ============================================================
 * DETALLE CATEGORÍA
 * ============================================================ */

if (!function_exists('cd67_category_detail')) {

    function cd67_category_detail($category_id) {

        static $cache = array();

        $category_id =
            trim((string)$category_id);

        if ($category_id === '') {
            return array();
        }

        if (isset($cache[$category_id])) {
            return $cache[$category_id];
        }

        $url =
            'https://api.mercadolibre.com/categories/' .
            rawurlencode($category_id);

        $response =
            cd67_api_get($url);

        if (
            empty($response['ok']) ||
            empty($response['data'])
        ) {

            $cache[$category_id] =
                array();

            return array();
        }

        $cache[$category_id] =
            $response['data'];

        return $cache[$category_id];
    }
}


/* ============================================================
 * ATRIBUTOS DE CATEGORÍA
 * ============================================================ */

if (!function_exists('cd67_category_attributes')) {

    function cd67_category_attributes($category_id) {

        static $cache = array();

        $category_id =
            trim((string)$category_id);

        if ($category_id === '') {
            return array();
        }

        if (isset($cache[$category_id])) {
            return $cache[$category_id];
        }

        $url =
            'https://api.mercadolibre.com/categories/' .
            rawurlencode($category_id) .
            '/attributes';

        $response =
            cd67_api_get($url);

        if (
            empty($response['ok']) ||
            empty($response['data'])
        ) {

            $cache[$category_id] =
                array();

            return array();
        }

        $cache[$category_id] =
            $response['data'];

        return $cache[$category_id];
    }
}


/* ============================================================
 * RUTA OFICIAL
 * ============================================================ */

if (!function_exists('cd67_get_path')) {

    function cd67_get_path($detail) {

        $path = array();

        if (
            !empty($detail['path_from_root']) &&
            is_array($detail['path_from_root'])
        ) {

            foreach (
                $detail['path_from_root']
                as $item
            ) {

                if (
                    isset($item['name'])
                ) {

                    $path[] =
                        $item['name'];
                }
            }
        }

        return $path;
    }
}


/* ============================================================
 * DETECTAR LEAF
 * ============================================================ */

if (!function_exists('cd67_is_leaf')) {

    function cd67_is_leaf($detail) {

        if (
            !empty($detail['children_categories']) &&
            is_array(
                $detail['children_categories']
            )
        ) {

            return count(
                $detail['children_categories']
            ) === 0;
        }

        return true;
    }
}


/* ============================================================
 * TEXTO DE RUTA
 * ============================================================ */

if (!function_exists('cd67_path_text')) {

    function cd67_path_text($detail) {

        $path =
            cd67_get_path($detail);

        if (empty($path)) {
            return '';
        }

        return implode(
            ' → ',
            $path
        );
    }
}


/* ============================================================
 * PALABRAS
 * ============================================================ */

if (!function_exists('cd67_words')) {

    function cd67_words($text) {

        $text =
            cd67_normalize($text);

        if ($text === '') {
            return array();
        }

        $words =
            preg_split(
                '/\s+/',
                $text
            );

        $stop =
            array(
                'para',
                'de',
                'del',
                'la',
                'el',
                'los',
                'las',
                'y',
                'en',
                'con',
                'por',
                'tipo',
                'accesorios',
                'producto',
                'productos'
            );

        $out = array();

        foreach ($words as $word) {

            if (
                strlen($word) < 3
            ) {
                continue;
            }

            if (
                in_array(
                    $word,
                    $stop,
                    true
                )
            ) {
                continue;
            }

            $out[] =
                $word;
        }

        return array_values(
            array_unique($out)
        );
    }
}


/* ============================================================
 * SIMILITUD TEXTUAL
 * ============================================================ */

if (!function_exists('cd67_text_score')) {

    function cd67_text_score(
        $source,
        $target
    ) {

        $a =
            cd67_words($source);

        $b =
            cd67_words($target);

        if (
            empty($a) ||
            empty($b)
        ) {
            return 0;
        }

        $matches = 0;

        foreach ($a as $word) {

            foreach ($b as $word2) {

                if (
                    $word === $word2
                ) {

                    $matches += 1;
                    break;
                }

                if (
                    strlen($word) >= 5 &&
                    strlen($word2) >= 5
                ) {

                    if (
                        strpos(
                            $word2,
                            $word
                        ) !== false ||
                        strpos(
                            $word,
                            $word2
                        ) !== false
                    ) {

                        $matches += 0.7;
                        break;
                    }
                }
            }
        }

        $score =
            (
                $matches /
                max(
                    count($a),
                    count($b)
                )
            ) * 100;

        return min(
            100,
            round($score, 2)
        );
    }
}


/* ============================================================
 * CONTEXTO TECNOLÓGICO
 * ============================================================ */

if (!function_exists('cd67_context_score')) {

    function cd67_context_score(
        $ct_category,
        $ct_subcategory,
        $path,
        $domain_name
    ) {

        $source =
            cd67_normalize(
                $ct_category .
                ' ' .
                $ct_subcategory
            );

        $context =
            cd67_normalize(
                implode(
                    ' ',
                    $path
                ) .
                ' ' .
                $domain_name
            );

        $score = 0;

        /*
         * COMPUTACIÓN
         */

        $computer_words =
            array(
                'computacion',
                'computadoras',
                'pc',
                'laptop',
                'perifericos',
                'componentes',
                'monitores',
                'teclados',
                'mouse',
                'mouses',
                'gabinetes',
                'motherboards',
                'procesadores',
                'memorias',
                'discos',
                'almacenamiento',
                'webcams',
                'impresoras',
                'servidores',
                'redes'
            );

        foreach (
            $computer_words
            as $word
        ) {

            if (
                strpos(
                    $source,
                    $word
                ) !== false &&
                strpos(
                    $context,
                    $word
                ) !== false
            ) {

                $score += 12;
            }
        }

        /*
         * GAMING
         */

        if (
            strpos(
                $source,
                'gaming'
            ) !== false
        ) {

            if (
                strpos(
                    $context,
                    'gaming'
                ) !== false
            ) {

                $score += 20;
            }

            if (
                strpos(
                    $context,
                    'computacion'
                ) !== false
            ) {

                $score += 10;
            }

            if (
                strpos(
                    $context,
                    'laptops'
                ) !== false
            ) {

                $score -= 12;
            }

            if (
                strpos(
                    $context,
                    'vehiculos'
                ) !== false
            ) {

                $score -= 30;
            }
        }

        /*
         * AUDIO
         */

        $audio_words =
            array(
                'audifonos',
                'diademas',
                'bocinas',
                'microfonos',
                'audio'
            );

        foreach (
            $audio_words
            as $word
        ) {

            if (
                strpos(
                    $source,
                    $word
                ) !== false &&
                strpos(
                    $context,
                    $word
                ) !== false
            ) {

                $score += 15;
            }
        }

        /*
         * VEHÍCULOS
         */

        if (
            strpos(
                $context,
                'vehiculos'
            ) !== false ||
            strpos(
                $context,
                'autos'
            ) !== false
        ) {

            if (
                strpos(
                    $source,
                    'vehiculo'
                ) === false &&
                strpos(
                    $source,
                    'auto'
                ) === false
            ) {

                $score -= 30;
            }
        }

        /*
         * ALIMENTOS
         */

        if (
            strpos(
                $context,
                'alimentos'
            ) !== false ||
            strpos(
                $context,
                'bebidas'
            ) !== false ||
            strpos(
                $context,
                'despensa'
            ) !== false
        ) {

            if (
                strpos(
                    $source,
                    'alimento'
                ) === false
            ) {

                $score -= 50;
            }
        }

        /*
         * MODA
         */

        if (
            strpos(
                $context,
                'moda'
            ) !== false ||
            strpos(
                $context,
                'ropa'
            ) !== false
        ) {

            if (
                strpos(
                    $source,
                    'ropa'
                ) === false &&
                strpos(
                    $source,
                    'moda'
                ) === false
            ) {

                $score -= 40;
            }
        }

        return $score;
    }
}


/* ============================================================
 * SCORE FINAL
 * ============================================================ */

if (!function_exists('cd67_score_candidate')) {

    function cd67_score_candidate(
        $ct_category,
        $ct_subcategory,
        $candidate,
        $detail
    ) {

        $category_name =
            isset(
                $candidate['category_name']
            )
            ? $candidate['category_name']
            : '';

        $domain_name =
            isset(
                $candidate['domain_name']
            )
            ? $candidate['domain_name']
            : '';

        $path =
            cd67_get_path($detail);

        $path_text =
            implode(
                ' ',
                $path
            );

        /*
         * La subcategoría tiene mayor peso
         * que la categoría CT padre.
         */

        $sub_score =
            cd67_text_score(
                $ct_subcategory,
                $category_name
            );

        $cat_score =
            cd67_text_score(
                $ct_category,
                $category_name
            );

        $path_score =
            cd67_text_score(
                $ct_subcategory,
                $path_text
            );

        $context_score =
            cd67_context_score(
                $ct_category,
                $ct_subcategory,
                $path,
                $domain_name
            );

        $score =
            (
                $sub_score * 0.50
            ) +
            (
                $cat_score * 0.15
            ) +
            (
                $path_score * 0.20
            ) +
            (
                $context_score * 0.15
            );

        /*
         * Penalizaciones fuertes
         * para familias claramente
         * incompatibles.
         */

        $source =
            cd67_normalize(
                $ct_category .
                ' ' .
                $ct_subcategory
            );

        $ctx =
            cd67_normalize(
                $path_text .
                ' ' .
                $domain_name
            );

        if (
            strpos(
                $ctx,
                'vehiculos'
            ) !== false &&
            strpos(
                $source,
                'vehiculo'
            ) === false
        ) {

            $score -= 35;
        }

        if (
            strpos(
                $ctx,
                'alimentos'
            ) !== false
        ) {

            $score -= 60;
        }

        if (
            strpos(
                $ctx,
                'ropa'
            ) !== false &&
            strpos(
                $source,
                'ropa'
            ) === false
        ) {

            $score -= 50;
        }

        if (
            strpos(
                $ctx,
                'repuestos para laptops'
            ) !== false &&
            strpos(
                $source,
                'repuesto'
            ) === false
        ) {

            $score -= 18;
        }

        if (
            strpos(
                $source,
                'gaming'
            ) !== false &&
            strpos(
                $ctx,
                'computacion'
            ) !== false
        ) {

            $score += 8;
        }

        return round(
            max(
                0,
                min(
                    100,
                    $score
                )
            ),
            2
        );
    }
}


/* ============================================================
 * ANALIZAR ATRIBUTOS
 * ============================================================ */

if (!function_exists('cd67_attribute_summary')) {

    function cd67_attribute_summary(
        $attributes
    ) {

        $total = 0;
        $required = 0;
        $catalog_required = 0;
        $conditional = 0;
        $fixed = 0;

        $required_list = array();

        if (
            !is_array($attributes)
        ) {

            return array(
                'total' =>
                    0,
                'required' =>
                    0,
                'catalog_required' =>
                    0,
                'conditional' =>
                    0,
                'fixed' =>
                    0,
                'required_list' =>
                    array()
            );
        }

        foreach (
            $attributes
            as $attribute
        ) {

            if (
                !is_array($attribute)
            ) {
                continue;
            }

            $total++;

            $tags =
                isset(
                    $attribute['tags']
                ) &&
                is_array(
                    $attribute['tags']
                )
                ? $attribute['tags']
                : array();

            $id =
                isset(
                    $attribute['id']
                )
                ? $attribute['id']
                : '';

            $name =
                isset(
                    $attribute['name']
                )
                ? $attribute['name']
                : '';

            if (
                isset(
                    $tags['required']
                )
            ) {

                $required++;

                $required_list[] =
                    array(
                        'id' =>
                            $id,
                        'name' =>
                            $name
                    );
            }

            if (
                isset(
                    $tags['catalog_required']
                )
            ) {

                $catalog_required++;
            }

            if (
                isset(
                    $tags['conditional_required']
                )
            ) {

                $conditional++;
            }

            if (
                isset(
                    $tags['fixed']
                )
            ) {

                $fixed++;
            }
        }

        return array(
            'total' =>
                $total,

            'required' =>
                $required,

            'catalog_required' =>
                $catalog_required,

            'conditional' =>
                $conditional,

            'fixed' =>
                $fixed,

            'required_list' =>
                $required_list
        );
    }
}


/* ============================================================
 * LEER PRODUCTOS JSON
 *
 * Solo se mantiene en memoria durante la extracción
 * de nodos.
 * ============================================================ */

if (!function_exists('cd67_extract_nodes')) {

    function cd67_extract_nodes(
        $json_file
    ) {

        if (
            !file_exists($json_file)
        ) {

            return array();
        }

        $json =
            file_get_contents(
                $json_file
            );

        if (
            $json === false ||
            $json === ''
        ) {

            return array();
        }

        $data =
            json_decode(
                $json,
                true
            );

        unset($json);

        if (
            !is_array($data)
        ) {

            return array();
        }

        /*
         * Algunos JSON vienen directamente
         * como array y otros dentro de
         * productos.
         */

        if (
            isset(
                $data['productos']
            ) &&
            is_array(
                $data['productos']
            )
        ) {

            $products =
                $data['productos'];

        } else {

            $products =
                $data;
        }

        $nodes = array();

        foreach (
            $products
            as $product
        ) {

            if (
                !is_array($product)
            ) {
                continue;
            }

            $category =
                isset(
                    $product['categoria']
                )
                ? trim(
                    (string)
                    $product['categoria']
                )
                : '';

            $subcategory =
                isset(
                    $product['subcategoria']
                )
                ? trim(
                    (string)
                    $product['subcategoria']
                )
                : '';

            if (
                $category === ''
            ) {
                continue;
            }

            /*
             * Si no existe subcategoría,
             * se conserva igualmente
             * el nodo padre.
             */

            $key =
                md5(
                    cd67_normalize(
                        $category .
                        '|' .
                        $subcategory
                    )
                );

            if (
                !isset(
                    $nodes[$key]
                )
            ) {

                $nodes[$key] =
                    array(
                        'key' =>
                            $key,

                        'categoria' =>
                            $category,

                        'subcategoria' =>
                            $subcategory,

                        'productos' =>
                            0
                    );
            }

            $nodes[$key]['productos']++;
        }

        unset($products);
        unset($data);

        return array_values(
            $nodes
        );
    }
}


/* ============================================================
 * CARGAR NODOS
 * ============================================================ */

$cd67_nodes =
    get_transient(
        $cd67_nodes_key
    );

if (
    $cd67_nodes === false ||
    !is_array($cd67_nodes)
) {

    $cd67_nodes =
        cd67_extract_nodes(
            $cd67_json_file
        );

    /*
     * 12 horas.
     */

    set_transient(
        $cd67_nodes_key,
        $cd67_nodes,
        12 * HOUR_IN_SECONDS
    );
}


/* ============================================================
 * ESTADO
 * ============================================================ */

$cd67_state =
    get_transient(
        $cd67_state_key
    );

if (
    !is_array($cd67_state)
) {

    $cd67_state =
        array(
            'pos' =>
                0,

            'total' =>
                count($cd67_nodes),

            'processed' =>
                0,

            'confirmed' =>
                0,

            'doubtful' =>
                0,

            'not_found' =>
                0,

            'errors' =>
                0,

            'running' =>
                false,

            'finished' =>
                false,

            'started' =>
                false
        );
}


/* ============================================================
 * RESET
 * ============================================================ */

if (
    isset(
        $_GET['cd67_reset']
    )
) {

    delete_transient(
        $cd67_state_key
    );

    delete_transient(
        $cd67_nodes_key
    );

    if (
        file_exists(
            $cd67_result_file
        )
    ) {

        @unlink(
            $cd67_result_file
        );
    }

    $cd67_state =
        array(
            'pos' =>
                0,

            'total' =>
                count($cd67_nodes),

            'processed' =>
                0,

            'confirmed' =>
                0,

            'doubtful' =>
                0,

            'not_found' =>
                0,

            'errors' =>
                0,

            'running' =>
                false,

            'finished' =>
                false,

            'started' =>
                false
        );

    set_transient(
        $cd67_state_key,
        $cd67_state,
        12 * HOUR_IN_SECONDS
    );
}


/* ============================================================
 * CARGAR RESULTADOS
 * ============================================================ */

$cd67_results = array();

if (
    file_exists(
        $cd67_result_file
    )
) {

    $result_json =
        @file_get_contents(
            $cd67_result_file
        );

    if (
        $result_json !== false &&
        $result_json !== ''
    ) {

        $decoded =
            json_decode(
                $result_json,
                true
            );

        if (
            is_array($decoded)
        ) {

            $cd67_results =
                $decoded;
        }
    }

    unset($result_json);
}


/* ============================================================
 * PROCESAR LOTE
 * ============================================================ */

$cd67_run =
    isset(
        $_GET['cd67_run']
    ) &&
    $_GET['cd67_run'] == '1';


if (
    $cd67_run &&
    !$cd67_state['finished']
) {

    $cd67_state['running'] = true;
    $cd67_state['started'] = true;

    $start =
        (int)
        $cd67_state['pos'];

    $end =
        min(
            $start +
            $cd67_batch_size,

            count(
                $cd67_nodes
            )
        );

    for (
        $i = $start;
        $i < $end;
        $i++
    ) {

        $node =
            $cd67_nodes[$i];

        $ct_category =
            $node['categoria'];

        $ct_subcategory =
            $node['subcategoria'];

        $query =
            trim(
                $ct_category .
                ' ' .
                $ct_subcategory
            );

        /*
         * Domain Discovery
         */

        $candidates =
            cd67_domain_discovery(
                $query
            );

        $candidate_results =
            array();

        /*
         * Si no encontró candidatos.
         */

        if (
            empty($candidates)
        ) {

            $cd67_state['not_found']++;

            $cd67_results[] =
                array(
                    'key' =>
                        $node['key'],

                    'categoria' =>
                        $ct_category,

                    'subcategoria' =>
                        $ct_subcategory,

                    'productos' =>
                        $node['productos'],

                    'estado' =>
                        'NO ENCONTRADA',

                    'mejor_categoria' =>
                        '',

                    'category_id' =>
                        '',

                    'score' =>
                        0,

                    'leaf' =>
                        false,

                    'path' =>
                        '',

                    'atributos' =>
                        0,

                    'required' =>
                        0,

                    'catalog_required' =>
                        0,

                    'conditional' =>
                        0,

                    'fixed' =>
                        0,

                    'missing' =>
                        0,

                    'candidatos' =>
                        array(),

                    'fecha' =>
                        current_time(
                            'mysql'
                        )
                );

            $cd67_state['processed']++;
            $cd67_state['pos']++;

            continue;
        }


        /*
         * TODOS LOS CANDIDATOS
         */

        foreach (
            $candidates
            as $candidate
        ) {

            if (
                !is_array(
                    $candidate
                )
            ) {
                continue;
            }

            $category_id =
                isset(
                    $candidate['category_id']
                )
                ? $candidate['category_id']
                : '';

            if (
                $category_id === ''
            ) {
                continue;
            }

            /*
             * Detalle oficial
             */

            $detail =
                cd67_category_detail(
                    $category_id
                );

            if (
                empty($detail)
            ) {
                continue;
            }

            $score =
                cd67_score_candidate(
                    $ct_category,
                    $ct_subcategory,
                    $candidate,
                    $detail
                );

            $path =
                cd67_get_path(
                    $detail
                );

            $leaf =
                cd67_is_leaf(
                    $detail
                );

            /*
             * Atributos
             */

            $attributes =
                cd67_category_attributes(
                    $category_id
                );

            $attr_summary =
                cd67_attribute_summary(
                    $attributes
                );

            $candidate_results[] =
                array(
                    'category_id' =>
                        $category_id,

                    'category_name' =>
                        isset(
                            $candidate['category_name']
                        )
                        ? $candidate['category_name']
                        : '',

                    'domain_id' =>
                        isset(
                            $candidate['domain_id']
                        )
                        ? $candidate['domain_id']
                        : '',

                    'domain_name' =>
                        isset(
                            $candidate['domain_name']
                        )
                        ? $candidate['domain_name']
                        : '',

                    'score' =>
                        $score,

                    'leaf' =>
                        $leaf,

                    'path' =>
                        $path,

                    'attributes' =>
                        $attr_summary['total'],

                    'required' =>
                        $attr_summary['required'],

                    'catalog_required' =>
                        $attr_summary['catalog_required'],

                    'conditional' =>
                        $attr_summary['conditional'],

                    'fixed' =>
                        $attr_summary['fixed'],

                    'required_list' =>
                        $attr_summary['required_list']
                );
        }


        /*
         * Ordenar candidatos
         */

        usort(
            $candidate_results,
            function(
                $a,
                $b
            ) {

                if (
                    $a['score'] ==
                    $b['score']
                ) {

                    /*
                     * En empate preferimos leaf.
                     */

                    if (
                        $a['leaf'] &&
                        !$b['leaf']
                    ) {
                        return -1;
                    }

                    if (
                        !$a['leaf'] &&
                        $b['leaf']
                    ) {
                        return 1;
                    }

                    return 0;
                }

                return
                    ($a['score'] < $b['score'])
                    ? 1
                    : -1;
            }
        );


        /*
         * Elegir mejor candidato.
         */

        $winner =
            !empty($candidate_results)
            ? $candidate_results[0]
            : null;


        if (
            !$winner
        ) {

            $cd67_state['not_found']++;

            $estado =
                'NO ENCONTRADA';

        } else {

            /*
             * Reglas de confirmación.
             *
             * 75+ = CONFIRMADA
             * 55-74.99 = DUDOSA
             * <55 = NO ENCONTRADA
             *
             * Un leaf obtiene prioridad.
             */

            $winner_score =
                (float)
                $winner['score'];

            if (
                $winner['leaf'] &&
                $winner_score >= 70
            ) {

                $estado =
                    'CONFIRMADA';

            } elseif (
                $winner_score >= 75
            ) {

                $estado =
                    'CONFIRMADA';

            } elseif (
                $winner_score >= 55
            ) {

                $estado =
                    'DUDOSA';

            } else {

                $estado =
                    'NO ENCONTRADA';
            }


            if (
                $estado ===
                'CONFIRMADA'
            ) {

                $cd67_state['confirmed']++;

            } elseif (
                $estado ===
                'DUDOSA'
            ) {

                $cd67_state['doubtful']++;

            } else {

                $cd67_state['not_found']++;
            }
        }


        /*
         * Guardar resultado completo.
         */

        $cd67_results[] =
            array(
                'key' =>
                    $node['key'],

                'categoria' =>
                    $ct_category,

                'subcategoria' =>
                    $ct_subcategory,

                'productos' =>
                    $node['productos'],

                'estado' =>
                    $estado,

                'mejor_categoria' =>
                    $winner
                    ? $winner['category_name']
                    : '',

                'category_id' =>
                    $winner
                    ? $winner['category_id']
                    : '',

                'score' =>
                    $winner
                    ? $winner['score']
                    : 0,

                'leaf' =>
                    $winner
                    ? $winner['leaf']
                    : false,

                'path' =>
                    $winner
                    ? $winner['path']
                    : array(),

                'atributos' =>
                    $winner
                    ? $winner['attributes']
                    : 0,

                'required' =>
                    $winner
                    ? $winner['required']
                    : 0,

                'catalog_required' =>
                    $winner
                    ? $winner['catalog_required']
                    : 0,

                'conditional' =>
                    $winner
                    ? $winner['conditional']
                    : 0,

                'fixed' =>
                    $winner
                    ? $winner['fixed']
                    : 0,

                'missing' =>
                    0,

                'candidatos' =>
                    $candidate_results,

                'fecha' =>
                    current_time(
                        'mysql'
                    )
            );


        $cd67_state['processed']++;
        $cd67_state['pos']++;
    }


    /*
     * TERMINADO
     */

    if (
        $cd67_state['pos'] >=
        count($cd67_nodes)
    ) {

        $cd67_state['finished'] =
            true;

        $cd67_state['running'] =
            false;
    }


    /*
     * Guardar estado
     */

    set_transient(
        $cd67_state_key,
        $cd67_state,
        12 * HOUR_IN_SECONDS
    );


    /*
     * Guardar resultados.
     *
     * JSON_UNESCAPED_UNICODE para que
     * conserve correctamente español.
     */

    @file_put_contents(
        $cd67_result_file,
        wp_json_encode(
            $cd67_results,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ),
        LOCK_EX
    );
}


/* ============================================================
 * PROGRESO
 * ============================================================ */

$total =
    max(
        1,
        (int)
        $cd67_state['total']
    );

$processed =
    (int)
    $cd67_state['processed'];

$percent =
    round(
        (
            $processed /
            $total
        ) * 100,
        1
    );

if (
    $percent > 100
) {
    $percent = 100;
}


/* ============================================================
 * HTML
 * ============================================================ */

?>

<div
    id="cd67-app"
    style="
        font-family:Arial,sans-serif;
        max-width:1500px;
        margin:20px auto;
        background:#fff;
        color:#222;
    "
>

    <h2 style="
        margin-bottom:5px;
        font-size:25px;
    ">
        🧭 Mapeador Mercado Libre — Etapa 6.7
    </h2>

    <div style="
        color:#666;
        margin-bottom:18px;
    ">
        CT → Categoría + Subcategoría → Domain Discovery
        → IDs ML → Ruta oficial → Leaf → Atributos → Required
    </div>


    <!-- =====================================================
         BOTONES
         ===================================================== -->

    <div style="
        display:flex;
        gap:10px;
        flex-wrap:wrap;
        margin-bottom:18px;
    ">

        <?php if (!$cd67_state['finished']) : ?>

            <?php if (!$cd67_run) : ?>

                <a
                    href="<?php
                        echo esc_url(
                            add_query_arg(
                                'cd67_run',
                                '1'
                            )
                        );
                    ?>"
                    style="
                        display:inline-block;
                        background:#087f23;
                        color:#fff;
                        padding:12px 20px;
                        border-radius:6px;
                        text-decoration:none;
                        font-weight:bold;
                    "
                >
                    ▶ INICIAR MAPEO AUTOMÁTICO
                </a>

            <?php else : ?>

                <a
                    href="<?php
                        echo esc_url(
                            remove_query_arg(
                                'cd67_run'
                            )
                        );
                    ?>"
                    style="
                        display:inline-block;
                        background:#b42318;
                        color:#fff;
                        padding:12px 20px;
                        border-radius:6px;
                        text-decoration:none;
                        font-weight:bold;
                    "
                >
                    🛑 DETENER
                </a>

            <?php endif; ?>

        <?php else : ?>

            <div style="
                background:#087f23;
                color:#fff;
                padding:12px 20px;
                border-radius:6px;
                font-weight:bold;
            ">
                ✅ MAPEO COMPLETADO
            </div>

        <?php endif; ?>


        <a
            href="<?php
                echo esc_url(
                    add_query_arg(
                        'cd67_reset',
                        '1'
                    )
                );
            ?>"
            onclick="
                return confirm(
                    '¿Seguro que deseas borrar todo el progreso y comenzar desde cero?'
                );
            "
            style="
                display:inline-block;
                background:#555;
                color:#fff;
                padding:12px 20px;
                border-radius:6px;
                text-decoration:none;
                font-weight:bold;
            "
        >
            ♻️ REINICIAR
        </a>

    </div>


    <!-- =====================================================
         ESTADO
         ===================================================== -->

    <div style="
        display:grid;
        grid-template-columns:
            repeat(auto-fit,minmax(150px,1fr));
        gap:10px;
        margin-bottom:20px;
    ">


        <div style="
            background:#eef6ff;
            border:1px solid #c9def7;
            padding:14px;
            border-radius:7px;
        ">
            <div style="
                font-size:13px;
                color:#555;
            ">
                Nodos CT
            </div>

            <strong style="
                font-size:25px;
            ">
                <?php
                echo number_format(
                    $total
                );
                ?>
            </strong>
        </div>


        <div style="
            background:#f4f4f4;
            border:1px solid #ddd;
            padding:14px;
            border-radius:7px;
        ">
            <div style="
                font-size:13px;
                color:#555;
            ">
                Procesadas
            </div>

            <strong style="
                font-size:25px;
            ">
                <?php
                echo number_format(
                    $processed
                );
                ?>
            </strong>
        </div>


        <div style="
            background:#edfff1;
            border:1px solid #b9e5c1;
            padding:14px;
            border-radius:7px;
        ">
            <div style="
                font-size:13px;
                color:#555;
            ">
                Confirmadas
            </div>

            <strong style="
                font-size:25px;
                color:#087f23;
            ">
                <?php
                echo number_format(
                    $cd67_state['confirmed']
                );
                ?>
            </strong>
        </div>


        <div style="
            background:#fff8e6;
            border:1px solid #f0d890;
            padding:14px;
            border-radius:7px;
        ">
            <div style="
                font-size:13px;
                color:#555;
            ">
                Dudosas
            </div>

            <strong style="
                font-size:25px;
                color:#9a6700;
            ">
                <?php
                echo number_format(
                    $cd67_state['doubtful']
                );
                ?>
            </strong>
        </div>


        <div style="
            background:#fff0f0;
            border:1px solid #e5bcbc;
            padding:14px;
            border-radius:7px;
        ">
            <div style="
                font-size:13px;
                color:#555;
            ">
                No encontradas
            </div>

            <strong style="
                font-size:25px;
                color:#b42318;
            ">
                <?php
                echo number_format(
                    $cd67_state['not_found']
                );
                ?>
            </strong>
        </div>


        <div style="
            background:#f4f4f4;
            border:1px solid #ddd;
            padding:14px;
            border-radius:7px;
        ">
            <div style="
                font-size:13px;
                color:#555;
            ">
                Errores
            </div>

            <strong style="
                font-size:25px;
            ">
                <?php
                echo number_format(
                    $cd67_state['errors']
                );
                ?>
            </strong>
        </div>

    </div>


    <!-- =====================================================
         BARRA DE PROGRESO
         ===================================================== -->

    <div style="
        margin-bottom:22px;
    ">

        <div style="
            display:flex;
            justify-content:space-between;
            margin-bottom:5px;
            font-weight:bold;
        ">

            <span>
                Progreso
            </span>

            <span>
                <?php
                echo $percent;
                ?>%
            </span>

        </div>


        <div style="
            width:100%;
            height:28px;
            background:#e5e5e5;
            border-radius:7px;
            overflow:hidden;
            border:1px solid #ccc;
        ">

            <div style="
                width:<?php
                    echo esc_attr(
                        $percent
                    );
                ?>%;
                height:100%;
                background:#16803c;
                transition:width .4s;
            "></div>

        </div>

    </div>


    <?php if ($cd67_run && !$cd67_state['finished']) : ?>

        <div style="
            padding:12px 15px;
            background:#eef6ff;
            border:1px solid #b9d5f2;
            border-radius:6px;
            margin-bottom:18px;
        ">

            🔄 Procesando automáticamente...

            <strong>
                <?php
                echo $cd67_batch_size;
                ?>
            </strong>

            nodos por ejecución.

        </div>

    <?php endif; ?>


    <?php if ($cd67_state['finished']) : ?>

        <div style="
            padding:15px;
            background:#edfff1;
            border:1px solid #a9d8b1;
            border-radius:6px;
            margin-bottom:20px;
            font-weight:bold;
        ">

            ✅ El mapeo terminó.

            Se procesaron
            <?php
            echo number_format(
                $processed
            );
            ?>
            nodos.

        </div>

    <?php endif; ?>


    <!-- =====================================================
         TABLA
         ===================================================== -->

    <div style="
        overflow:auto;
        border:1px solid #ddd;
        border-radius:7px;
    ">

        <table
            style="
                width:100%;
                border-collapse:collapse;
                font-size:13px;
            "
        >

            <thead>

                <tr style="
                    background:#222;
                    color:#fff;
                ">

                    <th style="padding:9px;">
                        CT categoría
                    </th>

                    <th style="padding:9px;">
                        CT subcategoría
                    </th>

                    <th style="padding:9px;">
                        Productos
                    </th>

                    <th style="padding:9px;">
                        Estado
                    </th>

                    <th style="padding:9px;">
                        Mejor ML
                    </th>

                    <th style="padding:9px;">
                        ID
                    </th>

                    <th style="padding:9px;">
                        Score
                    </th>

                    <th style="padding:9px;">
                        Leaf
                    </th>

                    <th style="padding:9px;">
                        Atributos
                    </th>

                    <th style="padding:9px;">
                        Required
                    </th>

                    <th style="padding:9px;">
                        Catalog
                    </th>

                    <th style="padding:9px;">
                        Conditional
                    </th>

                    <th style="padding:9px;">
                        Ruta oficial ML
                    </th>

                    <th style="padding:9px;">
                        Candidatos
                    </th>

                </tr>

            </thead>


            <tbody>

                <?php

                /*
                 * Mostrar únicamente los últimos 100
                 * para no hacer una página gigantesca.
                 */

                $display_results =
                    array_slice(
                        $cd67_results,
                        -100
                    );

                foreach (
                    $display_results
                    as $row
                ) :

                    $estado =
                        isset(
                            $row['estado']
                        )
                        ? $row['estado']
                        : '';

                    if (
                        $estado ===
                        'CONFIRMADA'
                    ) {

                        $state_bg =
                            '#e8f7ec';

                        $state_color =
                            '#087f23';

                    } elseif (
                        $estado ===
                        'DUDOSA'
                    ) {

                        $state_bg =
                            '#fff8df';

                        $state_color =
                            '#9a6700';

                    } else {

                        $state_bg =
                            '#fff0f0';

                        $state_color =
                            '#b42318';
                    }

                    $path_text =
                        isset(
                            $row['path']
                        ) &&
                        is_array(
                            $row['path']
                        )
                        ? implode(
                            ' → ',
                            $row['path']
                        )
                        : '';

                ?>

                    <tr
                        style="
                            border-bottom:1px solid #ddd;
                        "
                    >

                        <td style="
                            padding:8px;
                        ">
                            <?php
                            echo esc_html(
                                $row['categoria']
                            );
                            ?>
                        </td>


                        <td style="
                            padding:8px;
                        ">
                            <?php
                            echo esc_html(
                                $row['subcategoria']
                            );
                            ?>
                        </td>


                        <td style="
                            padding:8px;
                            text-align:center;
                        ">
                            <?php
                            echo number_format(
                                $row['productos']
                            );
                            ?>
                        </td>


                        <td style="
                            padding:8px;
                            background:
                                <?php
                                echo esc_attr(
                                    $state_bg
                                );
                                ?>;
                            color:
                                <?php
                                echo esc_attr(
                                    $state_color
                                );
                                ?>;
                            font-weight:bold;
                            text-align:center;
                        ">
                            <?php
                            echo esc_html(
                                $estado
                            );
                            ?>
                        </td>


                        <td style="
                            padding:8px;
                        ">
                            <?php
                            echo esc_html(
                                $row['mejor_categoria']
                            );
                            ?>
                        </td>


                        <td style="
                            padding:8px;
                            font-weight:bold;
                        ">
                            <?php
                            echo esc_html(
                                $row['category_id']
                            );
                            ?>
                        </td>


                        <td style="
                            padding:8px;
                            text-align:center;
                            font-weight:bold;
                        ">
                            <?php
                            echo esc_html(
                                $row['score']
                            );
                            ?>
                        </td>


                        <td style="
                            padding:8px;
                            text-align:center;
                        ">

                            <?php
                            if (
                                !empty(
                                    $row['leaf']
                                )
                            ) {
                                echo '✅';
                            } else {
                                echo '❌';
                            }
                            ?>

                        </td>


                        <td style="
                            padding:8px;
                            text-align:center;
                        ">
                            <?php
                            echo number_format(
                                $row['atributos']
                            );
                            ?>
                        </td>


                        <td style="
                            padding:8px;
                            text-align:center;
                        ">
                            <?php
                            echo number_format(
                                $row['required']
                            );
                            ?>
                        </td>


                        <td style="
                            padding:8px;
                            text-align:center;
                        ">
                            <?php
                            echo number_format(
                                $row['catalog_required']
                            );
                            ?>
                        </td>


                        <td style="
                            padding:8px;
                            text-align:center;
                        ">
                            <?php
                            echo number_format(
                                $row['conditional']
                            );
                            ?>
                        </td>


                        <td style="
                            padding:8px;
                            min-width:320px;
                        ">
                            <?php
                            echo esc_html(
                                $path_text
                            );
                            ?>
                        </td>


                        <td style="
                            padding:8px;
                            text-align:center;
                        ">
                            <?php

                            echo number_format(
                                is_array(
                                    $row['candidatos']
                                )
                                ? count(
                                    $row['candidatos']
                                )
                                : 0
                            );

                            ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>


    <div style="
        margin-top:12px;
        color:#777;
        font-size:12px;
    ">
        Se muestran los últimos 100 resultados en pantalla.
        El archivo completo se conserva en:
        <code>
            /wp-content/uploads/cd67_mapeo_resultados.json
        </code>
    </div>

</div>


<?php

/* ============================================================
 * AUTO AVANCE
 *
 * AQUÍ ESTÁ LA CORRECCIÓN PRINCIPAL.
 *
 * Si acabamos de procesar un lote y todavía quedan nodos,
 * la página vuelve a abrirse automáticamente con:
 *
 * ?cd67_run=1
 *
 * Eso hace que el snippet vuelva a ejecutarse y procese
 * los siguientes 3.
 * ============================================================ */

if (
    $cd67_run &&
    !$cd67_state['finished']
) :

    $current_url =
        remove_query_arg(
            array(
                'cd67_reset'
            )
        );

    $current_url =
        add_query_arg(
            'cd67_run',
            '1',
            $current_url
        );

?>

<script>
(function() {

    /*
     * Esperamos 1.2 segundos para que:
     *
     * 1. Se vea el progreso.
     * 2. WordPress termine de enviar la respuesta.
     * 3. Se vuelva a ejecutar automáticamente.
     */

    setTimeout(function() {

        window.location.href =
            <?php
            echo wp_json_encode(
                $current_url
            );
            ?>;

    }, 1200);

})();
</script>

<?php endif; ?>