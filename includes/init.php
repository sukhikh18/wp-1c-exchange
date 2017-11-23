<?php

namespace CDevelopers\Exchange;

class Init
{
    private $import, $offers, $args;

    private function get_import_file()
    {
        if( ! $this->import ) {
            if( is_readable(wp_upload_dir()['basedir'] . '/exchange/import0_1.xml') ) {
                $this->import = new \SimpleXMLElement(
                    file_get_contents( wp_upload_dir()['basedir'] . '/exchange/import0_1.xml' ) );
            }
            else {
                return false;
            }
        }

        return $this->import;
    }

    private function get_offers_file()
    {
        if( ! $this->offers ) {
            if( is_readable(wp_upload_dir()['basedir'] . '/exchange/offers0_1.xml') ) {
                $this->offers = new \SimpleXMLElement(
                    file_get_contents( wp_upload_dir()['basedir'] . '/exchange/offers0_1.xml' ) );
            }
            else {
                return false;
            }
        }

        return $this->offers;
    }

    function __construct( $args = array() )
    {
        $this->args = wp_parse_args( array_map('intval', (array) $args), array(
            'offset' => 500,
            'part'   => 1,
        ) );
    }

    private static function update_terms( $arr, $args = array() )
    {
        global $wpdb;

        if( ! is_array($arr) || ! sizeof($arr) )
            return;

        $args = wp_parse_args( $args, array(
            'taxanomy' => 'product_cat',
            ) );

        /**
         * Search Exchanged Terms by XML_ID
         * @var array
         */
        $XMLS = array_keys($arr);
        foreach ($XMLS as &$XML) { $XML = "meta_value = '$XML'"; }
        $exsists = $wpdb->get_results("
            SELECT term_id, meta_value
            FROM $wpdb->termmeta
            WHERE meta_key = 'XML_ID'
            AND (". implode(" \t\n OR ", $XMLS) . ")");

        /**
         * Set term_ids for global array
         */
        foreach ($exsists as $exsist) {
            if( isset($arr[ $exsist->meta_value ]) ) {
                $arr[ $exsist->meta_value ]['term_id'] = $exsist->term_id;
            }
        }

        $insert = array();
        $place_holders = array();
        foreach ($arr as $xml_id => $term) {
            $parent = (isset($term['parent']) && isset( $arr[ $term['parent'] ]['term_id'] ))
                ? $arr[ $term['parent'] ]['term_id'] : 0;

            if( isset($term['term_id']) ) {
                /**
                 * Update term info
                 */
                $result = wp_update_term($term['term_id'], $args['taxanomy'], array(
                    'name' => $term['name'],
                    'parent' => $parent,
                    ) );
            }
            else {
                /**
                 * Create new term with info and add XML_ID meta
                 */
                $result = wp_insert_term($term['name'], $args['taxanomy'], array(
                    'description' => '',
                    'parent'      => $parent,
                    ) );

                if( ! is_wp_error($result) && isset($result['term_id']) ) {
                    array_push( $insert, '', $result['term_id'], 'XML_ID', $xml_id );
                    $place_holders[] = "('%d', '%d', '%s', '%s')";
                }
            }
        }

        /**
         * Insert XML_ID meta for new terms
         */
        if( count($insert) ) {
            $qtermmeta = "INSERT INTO $wpdb->termmeta (meta_id, term_id, meta_key, meta_value) VALUES ";
            $qtermmeta .= implode(', ', $place_holders);
            $sql = $wpdb->prepare("$qtermmeta ", $insert);

            $wpdb->query( $sql );
        }
    }

    private static function update_relationships( $arr, $args )
    {
        global $wpdb;

        if( ! is_array($arr) || ! sizeof($arr) )
            return;

        $args = wp_parse_args( $args, array(
            'var'      => 'terms',
            'taxanomy' => 'product_cat',
            ) );

        /**
         * Get all exchanged products
         */
        $posts = $wpdb->get_results("SELECT ID, post_mime_type FROM $wpdb->posts WHERE post_type = 'product' AND post_mime_type LIKE 'XML/%'");

        /**
         * Set post ID for global array
         */
        foreach ($posts as $post) {
            $xml = explode('/', $post->post_mime_type);

            if( isset( $xml[1] ) ) {
                $arr[ $xml[1] ]['post_id'] = $post->ID;
            }
        }

        foreach ($arr as $_post) {
            $relationships = array();
            $r = array();
            if( isset($_post['post_id']) && isset($_post[ $args['var'] ]) && is_array($_post[ $args['var'] ]) ) {
                if( in_array($args['var'], array('brand', 'stock_wh') ) ) {
                    foreach ($_post[ $args['var'] ] as $xml_key => $xml) {
                        if( $xml_key )
                            $r[] = "meta_value = '$xml_key'";
                    }
                }
                else {
                    foreach ($_post[ $args['var'] ] as $xml_key => $xml) {
                        if( $xml )
                           $r[] = "meta_value = '$xml'";
                    }
                }

                if( ! count( $r ) ) continue;

                $terms = $wpdb->get_results("
                    SELECT term_id, meta_value
                    FROM $wpdb->termmeta
                    WHERE meta_key = 'XML_ID'
                    AND (".implode(' OR ', $r).")");

                foreach ($terms as $term) {
                    $relationships[] = (int) $term->term_id;
                }

                wp_set_object_terms( $_post['post_id'], $relationships, $args['taxanomy'], $append = false );
            }
        }
    }

    static function update_posts( $arr )
    {
        if( ! is_array($arr) || ! sizeof($arr) )
            return;

        global $wpdb;

        $create = $arr;

        $date = date('Y-m-d H:i:s');
        $gmdate = gmdate('Y-m-d H:i:s');

        $XMLS = array_keys($arr);
        foreach ($XMLS as &$XML) { $XML = "post_mime_type = 'XML/$XML'"; }
        $exsists = $wpdb->get_results("SELECT ID, post_mime_type FROM $wpdb->posts WHERE post_type = 'product' AND (". implode(" \t\n OR ", $XMLS) . ")");

        foreach ($exsists as $exsist) {
            $xml_orig = explode('/', $exsist->post_mime_type);

            if( isset($arr[ $xml_orig[1] ]) ) {
                $arr[ $xml_orig[1] ]['ID'] = $exsist->ID;

                if( isset( $xml_orig[1] ) ) {
                    unset($create[$xml_orig[1]]);
                }
            }
        }

        $place_holders = array();
        $insert = array();
        $query = "INSERT INTO $wpdb->posts (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count)
        VALUES ";

        $site_url = get_site_url();
        foreach ($arr as $xml_id => $_post) {
            $id = isset( $_post['ID'] ) ? (int) $_post['ID'] : '';
            $slug = sanitize_cyr_url( $_post['title'] );
            array_push( $insert, $id, 1, $date, $gmdate, $_post['content'], $_post['title'], '', 'publish', 'closed', 'closed', '', $slug, '', '', $date, $gmdate, '', 0, $site_url . '/product/' . $slug, 0, 'product', "XML/$xml_id", 0 );
            $place_holders[] = "('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d')";
        }

        $query .= implode(', ', $place_holders);
        $sql = $wpdb->prepare("$query ", $insert);
        $sql.= " ON DUPLICATE KEY UPDATE
            ID=VALUES(ID),
            post_author=VALUES(post_author),
            post_date=VALUES(post_date),
            post_date_gmt=VALUES(post_date_gmt),
            post_content=VALUES(post_content),
            post_title=VALUES(post_title),
            post_excerpt=VALUES(post_excerpt),
            post_status=VALUES(post_status),
            comment_status=VALUES(comment_status),
            ping_status=VALUES(ping_status),
            post_password=VALUES(post_password),
            post_name=VALUES(post_name),
            to_ping=VALUES(to_ping),
            pinged=VALUES(pinged),
            post_modified=VALUES(post_modified),
            post_modified_gmt=VALUES(post_modified_gmt),
            post_content_filtered=VALUES(post_content_filtered),
            post_parent=VALUES(post_parent),
            guid=VALUES(guid),
            menu_order=VALUES(menu_order),
            post_type=VALUES(post_type),
            post_mime_type=VALUES(post_mime_type),
            comment_count=VALUES(comment_count);";

        $wpdb->query( $sql );
        return $sql;
    }

    static function update_postmetas( $arr, $columns = array('sku', 'unit', 'price', 'stock', 'stock_wh') )
    {
        if( ! is_array($arr) || ! sizeof($arr) )
            return;

        global $wpdb;

        $XMLS = array_keys($arr);
        foreach ($XMLS as &$XML) { $XML = "post_mime_type = 'XML/$XML'"; }
        $exsists = $wpdb->get_results("SELECT ID, post_mime_type FROM $wpdb->posts WHERE post_type = 'product' AND (". implode(" \t\n OR ", $XMLS) . ")");

        foreach ($exsists as $exsist) {
            $xml_orig = explode('/', $exsist->post_mime_type);
            if( isset($arr[ $xml_orig[1] ]) ) {
                $arr[ $xml_orig[1] ]['ID'] = $exsist->ID;
            }
        }

        $terms = array();
        foreach ($arr as $xml_id => $_post) {
            $id = isset( $_post['ID'] ) ? (int) $_post['ID'] : '';
            if( ! $id ) continue;

            foreach ($columns as $column) {
                update_post_meta( $id, "_$column", $_post[ $column ] );
            }

            if( in_array('price', $columns) ) {
                update_post_meta( $id, "_regular_price", $_post['price'] );
            }

            if( in_array('stock', $columns) ) {
                update_post_meta( $id, '_manage_stock', 'yes' );
                update_post_meta( $id, '_stock_status', $_post['stock'] < 1 ? 'outofstock' : 'instock');
            }
        }
    }

    public static function update_categories( $arr )
    {

        self::update_terms( $arr, array('taxanomy' => 'product_cat') );
    }

    public static function update_warehouses( $arr )
    {

        self::update_terms( $arr, array('taxanomy' => 'warehouse') );
    }

    public static function update_brands( $arr )
    {

        self::update_terms( $arr, array('taxanomy' => 'brand') );
    }

    public static function update_cat_relationships( $arr )
    {
        self::update_relationships($arr, array(
            'var' => 'terms',
            'taxanomy' => 'product_cat',
            ));
    }

    public static function update_wh_relationships( $arr )
    {
        self::update_relationships($arr, array(
            'var' => 'stock_wh',
            'taxanomy' => 'warehouse',
            ));
    }

    public static function update_brand_relationships( $arr )
    {
        self::update_relationships($arr, array(
            'var' => 'brand',
            'taxanomy' => 'brand',
            ));
    }

    public function parse_categories_recursive( $arr = false, $groups = array(), $parent = false )
    {
        if( ! $arr ) {
            if( $import = $this->get_import_file() ) {
                $arr = $import->Классификатор->Группы->Группа;
            }
        }

        if( ! $arr ) return false;

        foreach ($arr as $group) {
            $xml_id = current( $group->Ид );

            $groups[ $xml_id ] = array(
                // 'xml_id' => $xml_id,
                'name'   => current( $group->Наименование ),
            );

            if( $parent ) {
                $groups[ $xml_id ]['parent'] = $parent;
            }

            if( !empty($group->Группы->Группа) ) {
                $groups = self::parse_categories_recursive($group->Группы->Группа, $groups, $xml_id);
            }
        }

        return $groups;
    }

    public function parse_warehouses()
    {
        $whs = array();
        if( $offers = $this->get_offers_file() ) {
            if( isset($offers->ПакетПредложений) && isset($offers->ПакетПредложений->Склады) ) {
                foreach ($offers->ПакетПредложений->Склады->Склад as $warehouse) {
                    $whs[ current($warehouse->Ид) ] = array(
                        'name' => current($warehouse->Наименование),
                        );
                }
            }
        }
        else {
            // offers file not found
        }

        return $whs;
    }

    public function parse_brands()
    {
        $brands = array();

        if( $import = $this->get_import_file() ) {
            foreach ($import->Каталог->Товары->Товар as $product) {
                if( !isset($product->Изготовитель) ) continue;

                $brands[ current($product->Изготовитель->Ид) ] = array(
                    'name' => current($product->Изготовитель->Наименование),
                    );
            }
        }
        else {
            // import file not found
        }

        return $brands;
    }

    public function parse_products()
    {
        $res = array();
        $i = 0;
        if( $import = $this->get_import_file() ) {
            foreach ($import->Каталог->Товары->Товар as $product) {
                $i++;
                if( $i <= ($this->args['offset'] * $this->args['part']) - $this->args['offset'] ) continue;
                if($i > $this->args['offset'] * $this->args['part']) break;

                $id = (string) $product->Ид;

                $res[ $id ] = array(
                    'sku'     => (string) $product->Артикул,
                    'title'   => (string) $product->Наименование,
                    'unit'    => (string) $product->БазоваяЕдиница[0]->attributes()['НаименованиеПолное'],
                    'content' => (string) $product->Описание,
                    'brand'   => array( (string) $product->Изготовитель->Ид => (string) $product->Изготовитель->Наименование ),
                    );

                $offers = $this->get_offers_file();
                if( isset($offers->ПакетПредложений) && isset($offers->ПакетПредложений->Предложения) ){
                    foreach ($offers->ПакетПредложений->Предложения->Предложение as $offer) {
                        $offer_id = (string) $offer->Ид;

                        $qtys = array();
                        foreach ($offer->Склад as $attr) {
                            $_attr = $attr->attributes();
                            $qtys[ current($_attr['ИдСклада']) ] = intval($_attr['КоличествоНаСкладе']);
                        }
                        if( $offer_id === $id ) {
                        // ['offer'][ $offer_id ]
                            $res[ $id ] = array_merge( $res[ $id ], array(
                                'sku'           => (string) $offer->Артикул,
                                'title'         => (string) $offer->Наименование,
                                'unit'          => (string) $offer->БазоваяЕдиница[0]->attributes()['НаименованиеПолное'],
                                'price'         => (int)    $offer->Цены->Цена->ЦенаЗаЕдиницу,
                                'currency'      => (string) $offer->Цены->Цена->Валюта,
                                'stock'         => (int)    $offer->Количество,
                                'stock_wh'      => $qtys,
                                ) );
                            break;
                        }
                    }
                }

                $groups = array();
                foreach ($product->Группы as $group) {
                    $groups[] = (string) $group->Ид;
                }
                $res[ $id ]['terms'] = $groups;
            }
        }
        else {
            // import file not found
        }

        return $res;
    }
}
